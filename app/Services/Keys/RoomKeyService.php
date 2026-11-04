<?php

namespace App\Services\Keys;

use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Keys\DoorEvent;
use App\Models\Keys\RoomKey;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\User;
use App\Services\Keys\Providers\BridgeProvider;
use App\Services\Keys\Providers\LockProvider;
use App\Services\Keys\Providers\ManualProvider;
use App\Services\Keys\Providers\TtlockProvider;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Digital room keys.
 *
 * At check-in every room with a connected lock gets a door code that works from check-in to check-out (plus a
 * little either side), and a phone key that opens the door from the guest app when the locks have a gateway.
 * Key cards made on the encoder at the desk are recorded too. At check-out or cancellation every key stops
 * working; a room move moves the keys; an extended stay extends them. Every opening from here is in the door log,
 * and the locks' own records are read in every few minutes.
 *
 * None of this ever stops a check-in or check-out: if a lock can't be reached the key is marked "not set on the
 * lock", the desk is told, and they can try again or give a card.
 */
class RoomKeyService
{
    public static function register(): void
    {
        Reservation::updated(function (Reservation $r) {
            $svc = app(self::class);
            if (! $svc->enabled()) {
                return;
            }
            if ($r->wasChanged('status')) {
                $run = match ($r->status) {
                    ReservationStatus::CheckedIn => fn () => $svc->onCheckIn($r),
                    ReservationStatus::CheckedOut, ReservationStatus::Cancelled, ReservationStatus::NoShow => fn () => $svc->revokeStay($r, 'The stay ended (' . $r->status->label() . ').'),
                    default => null,
                };
            } elseif ($r->status === ReservationStatus::CheckedIn && $r->wasChanged('check_out_date')) {
                $run = fn () => $svc->onDatesChanged($r);
            }
            if (! empty($run)) {
                DB::afterCommit(fn () => $svc->safely($run, $r));
            }
        });
        ReservationRoom::updated(function (ReservationRoom $line) {
            $svc = app(self::class);
            if ($svc->enabled() && $line->wasChanged('room_id') && $line->reservation?->status === ReservationStatus::CheckedIn) {
                $old = (int) $line->getOriginal('room_id');
                DB::afterCommit(fn () => $svc->safely(fn () => $svc->onRoomMoved($line->fresh(), $old), $line->reservation));
            }
        });
    }

    /** Keys never get in the way of the front desk. */
    private function safely(\Closure $run, ?Reservation $r): void
    {
        try {
            $run();
        } catch (Throwable $e) {
            Log::warning('Room keys: ' . $e->getMessage());
            Audit::record('keys.error', 'Room keys for ' . ($r?->reference ?? 'a stay') . ': ' . $e->getMessage(), $r, flagged: true);
        }
    }

    public function enabled(): bool
    {
        return config('hotel.keys.provider', 'none') !== 'none';
    }

    public function provider(): LockProvider
    {
        return match (config('hotel.keys.provider')) {
            'ttlock' => app(TtlockProvider::class),
            'bridge' => app(BridgeProvider::class),
            default  => app(ManualProvider::class),
        };
    }

    public function connected(): bool
    {
        return $this->enabled() && ! $this->provider() instanceof ManualProvider && $this->provider()->ready();
    }

    /** From check-in time (a little before) to check-out time (a little after); never before now. */
    public function window(Reservation $r): array
    {
        $from = $r->check_in_date->copy()->setTimeFromTimeString((string) config('hotel.booking.check_in_time', '14:00'))->subMinutes((int) config('hotel.keys.early_minutes', 120));
        $until = $r->check_out_date->copy()->setTimeFromTimeString((string) config('hotel.booking.check_out_time', '12:00'))->addMinutes((int) config('hotel.keys.grace_minutes', 60));

        return [$from->min(now()), $until->max(now()->addHour())];
    }

    // ── Automatic ─────────────────────────────────────────────────────

    public function onCheckIn(Reservation $r): void
    {
        if (! config('hotel.keys.auto_issue', true) || ! $this->connected()) {
            return;
        }
        $r->loadMissing('rooms.room', 'guest');
        foreach ($r->rooms as $line) {
            if ($line->room && $line->room->lock_id) {
                $this->giveKeys($r, $line->room, null);
            }
        }
    }

    /** A code (when the locks make them) and a phone key (when doors open from here) for one room. */
    private function giveKeys(Reservation $r, Room $room, ?User $by): void
    {
        $p = $this->provider();
        if ($p->makesPins() && ! RoomKey::where('reservation_id', $r->id)->where('room_id', $room->id)->where('kind', 'pin')->active()->exists()) {
            $this->makePin($r, $room, $by);
        }
        if ($p->opensDoors() && config('hotel.keys.phone_key', true) && ! RoomKey::where('reservation_id', $r->id)->where('room_id', $room->id)->where('kind', 'phone')->active()->exists()) {
            [$from, $until] = $this->window($r);
            RoomKey::create(['reservation_id' => $r->id, 'room_id' => $room->id, 'kind' => 'phone', 'provider' => $p->key(), 'valid_from' => $from, 'valid_until' => $until, 'status' => 'active', 'issued_by' => $by?->id]);
        }
    }

    private function makePin(Reservation $r, Room $room, ?User $by): RoomKey
    {
        $p = $this->provider();
        [$from, $until] = $this->window($r);
        $key = new RoomKey(['reservation_id' => $r->id, 'room_id' => $room->id, 'kind' => 'pin', 'provider' => $p->key(), 'valid_from' => $from, 'valid_until' => $until, 'issued_by' => $by?->id]);
        try {
            $made = $p->issuePin((string) $room->lock_id, $from, $until, ($r->guest?->last_name ?? 'Guest') . ' ' . $r->reference);
            $key->fill(['secret' => $made['pin'], 'provider_ref' => $made['ref'], 'status' => 'active']);
            $r->log('room_key', "Door code made for room {$room->number}, works until " . $until->format('D j M g:i A') . '.');
        } catch (Throwable $e) {
            $key->fill(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 255)]);
            Audit::record('keys.failed', "Couldn't make a door code for room {$room->number} ({$r->reference}): {$e->getMessage()}", $r, flagged: true);
        }
        $key->save();

        return $key;
    }

    public function revokeStay(Reservation $r, string $why, ?User $by = null): int
    {
        $n = 0;
        foreach (RoomKey::where('reservation_id', $r->id)->active()->with('room')->get() as $k) {
            $this->stop($k, 'revoked', $why, $by);
            $n++;
        }

        return $n;
    }

    public function onDatesChanged(Reservation $r): void
    {
        [$from, $until] = $this->window($r);
        foreach (RoomKey::where('reservation_id', $r->id)->active()->with('room')->get() as $k) {
            if ($k->kind === 'pin' && $k->provider_ref && $k->room?->lock_id) {
                if (! $this->provider()->changePin((string) $k->room->lock_id, $k->provider_ref, (string) $k->secret, $k->valid_from, $until)) {
                    $this->stop($k, 'revoked', 'The stay changed: a new code was made.');
                    $this->makePin($r, $k->room, null);
                    continue;
                }
            }
            $k->forceFill(['valid_until' => $until])->save();
        }
        $r->log('room_key', 'Room keys now work until ' . $until->format('D j M g:i A') . '.');
    }

    public function onRoomMoved(ReservationRoom $line, int $oldRoomId): void
    {
        $r = $line->reservation;
        foreach (RoomKey::where('reservation_id', $r->id)->where('room_id', $oldRoomId)->active()->with('room')->get() as $k) {
            $this->stop($k, 'revoked', 'The guest moved room.');
        }
        if ($line->room?->lock_id && $this->connected()) {
            $this->giveKeys($r, $line->room, null);
        }
    }

    /** Codes and phone keys whose time is up. */
    public function expire(): int
    {
        $n = 0;
        foreach (RoomKey::active()->where('valid_until', '<', now())->with('room')->get() as $k) {
            $this->stop($k, 'expired', null);
            $n++;
        }

        return $n;
    }

    private function stop(RoomKey $k, string $status, ?string $why, ?User $by = null): void
    {
        if ($k->kind === 'pin' && $k->provider_ref && $k->room?->lock_id && $k->provider !== 'manual' && $status !== 'expired') {
            try {
                $this->provider()->revokePin((string) $k->room->lock_id, $k->provider_ref);
            } catch (Throwable $e) {
                // The code still ends on its own at valid_until; the desk is told it couldn't be cut short.
                Audit::record('keys.revoke_failed', "Couldn't cancel the door code on room {$k->room->number} now ({$e->getMessage()}). It stops by itself at " . $k->valid_until->format('D j M g:i A') . '.', $k->reservation, flagged: true);
            }
        }
        $k->forceFill(['status' => $status, 'revoked_at' => now(), 'revoked_by' => $by?->id, 'note' => $why ? mb_substr($why, 0, 255) : $k->note])->save();
    }

    // ── At the desk ───────────────────────────────────────────────────

    public function canManage(User $u): bool
    {
        return $u->can('Manage room keys');
    }

    /** A new door code (the guest forgot theirs, or it couldn't be made at check-in). Old ones stop working. */
    public function newPin(Reservation $r, Room $room, User $by): RoomKey
    {
        $this->authorize($by);
        $this->inHouse($r, $room);
        if (! $this->connected() || ! $this->provider()->makesPins()) {
            throw new BookingException('Door codes need connected locks (Hotel Settings → Room Keys).');
        }
        if (! $room->lock_id) {
            throw new BookingException("Room {$room->number} has no lock linked. Link it on the Room Keys page.");
        }
        foreach (RoomKey::where('reservation_id', $r->id)->where('room_id', $room->id)->whereIn('status', ['active', 'failed'])->where('kind', 'pin')->with('room')->get() as $k) {
            $this->stop($k, 'revoked', 'Replaced by a new code.', $by);
        }
        $key = $this->makePin($r, $room, $by);
        if ($key->status === 'failed') {
            throw new BookingException("The lock didn't take the code: {$key->note}");
        }
        if (! RoomKey::where('reservation_id', $r->id)->where('room_id', $room->id)->where('kind', 'phone')->active()->exists()) {
            $this->giveKeys($r, $room, $by);
        }
        Audit::record('keys.pin', "New door code for room {$room->number} ({$r->reference})", $r, by: $by);

        return $key;
    }

    /** A key card made on the encoder: recorded so the hotel knows it's out. */
    public function recordCard(Reservation $r, Room $room, string $cardNumber, User $by): RoomKey
    {
        $this->authorize($by);
        $this->inHouse($r, $room);
        [$from, $until] = $this->window($r);
        $k = RoomKey::create(['reservation_id' => $r->id, 'room_id' => $room->id, 'kind' => 'card', 'card_number' => mb_substr(trim($cardNumber), 0, 40) ?: null,
            'provider' => 'manual', 'valid_from' => $from, 'valid_until' => $until, 'status' => 'active', 'issued_by' => $by->id]);
        $r->log('room_key', "Key card" . ($k->card_number ? " {$k->card_number}" : '') . " given for room {$room->number}.");

        return $k;
    }

    public function revoke(RoomKey $k, User $by, string $why, bool $lost = false): void
    {
        $this->authorize($by);
        if ($k->status !== 'active' && $k->status !== 'failed') {
            throw new BookingException('That key isn’t in use.');
        }
        $this->stop($k->loadMissing('room'), $lost ? 'lost' : 'revoked', $why, $by);
        Audit::record($lost ? 'keys.lost' : 'keys.revoked', ($lost ? 'Reported lost: ' : 'Cancelled ') . "{$k->kind_label} for room {$k->room?->number}: {$why}", $k->reservation, by: $by, flagged: $lost);
        if ($lost && $k->kind === 'card') {
            $k->reservation?->log('room_key', "Key card for room {$k->room?->number} reported lost. Re-encode the room's cards so it no longer opens the door.");
        }
    }

    private function inHouse(Reservation $r, Room $room): void
    {
        if (! in_array($r->status, [ReservationStatus::CheckedIn, ReservationStatus::Confirmed], true)) {
            throw new BookingException('Keys are for confirmed or in-house guests.');
        }
        if (! $r->rooms()->where('room_id', $room->id)->exists()) {
            throw new BookingException("Room {$room->number} isn’t on this booking.");
        }
    }

    // ── Opening doors ─────────────────────────────────────────────────

    /** The guest taps "Unlock" in the app. */
    public function guestUnlock(RoomKey $k): void
    {
        $k->loadMissing('room', 'reservation.guest');
        if ($k->kind !== 'phone' || ! $k->isLive() || $k->reservation?->status !== ReservationStatus::CheckedIn) {
            throw new BookingException('This key doesn’t work right now. Ask at the front desk.');
        }
        if (! $this->connected() || ! $this->provider()->opensDoors()) {
            throw new BookingException('The door can’t be opened from the app at the moment. Use your door code or card.');
        }
        try {
            $this->provider()->unlock((string) $k->room->lock_id);
        } catch (Throwable $e) {
            DoorEvent::create(['room_id' => $k->room_id, 'key_id' => $k->id, 'kind' => 'phone', 'who' => $k->reservation->guest?->full_name, 'ok' => false, 'note' => mb_substr($e->getMessage(), 0, 255), 'at' => now()]);
            throw new BookingException('The door didn’t answer. Stand close to it and try again, or use your door code.');
        }
        $k->forceFill(['last_used_at' => now()])->save();
        DoorEvent::create(['room_id' => $k->room_id, 'key_id' => $k->id, 'kind' => 'phone', 'who' => $k->reservation->guest?->full_name, 'ok' => true, 'at' => now()]);
    }

    /** Staff open a door from here (a guest locked out, an emergency). Always on the record. */
    public function staffUnlock(Room $room, User $by, string $why): void
    {
        $this->authorize($by);
        if (! $room->lock_id || ! $this->connected() || ! $this->provider()->opensDoors()) {
            throw new BookingException("Room {$room->number}'s door can't be opened from here.");
        }
        try {
            $this->provider()->unlock((string) $room->lock_id);
        } catch (Throwable $e) {
            DoorEvent::create(['room_id' => $room->id, 'kind' => 'remote', 'who' => $by->name, 'user_id' => $by->id, 'ok' => false, 'note' => mb_substr($e->getMessage(), 0, 255), 'at' => now()]);
            throw new BookingException("The door didn't open: {$e->getMessage()}");
        }
        DoorEvent::create(['room_id' => $room->id, 'kind' => 'remote', 'who' => $by->name, 'user_id' => $by->id, 'ok' => true, 'note' => mb_substr($why, 0, 255), 'at' => now()]);
        Audit::record('keys.remote_unlock', "Opened room {$room->number}'s door from the system: {$why}", $room, by: $by, flagged: true);
    }

    /** Read the locks' own records into the door log. */
    public function syncRecords(): int
    {
        if (! $this->connected()) {
            return 0;
        }
        $n = 0;
        foreach (Room::whereNotNull('lock_id')->get() as $room) {
            $since = DoorEvent::where('room_id', $room->id)->whereNotNull('provider_ref')->max('at');
            try {
                foreach ($this->provider()->records((string) $room->lock_id, $since ? Carbon::parse($since) : now()->subDay()) as $x) {
                    $n += DB::table('door_events')->insertOrIgnore([
                        'hotel_id' => $room->hotel_id, 'room_id' => $room->id, 'kind' => $x['kind'], 'who' => $x['who'] ? mb_substr($x['who'], 0, 120) : null,
                        'ok' => $x['ok'], 'note' => $x['note'], 'provider_ref' => $x['ref'], 'at' => $x['at'], 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            } catch (Throwable $e) {
                Log::info("Door records for room {$room->number}: " . $e->getMessage());
            }
        }

        return $n;
    }

    private function authorize(User $by): void
    {
        if (! $this->canManage($by)) {
            throw new AuthorizationException('You are not allowed to manage room keys.');
        }
    }

    // ── For the guest ─────────────────────────────────────────────────

    /** The stay's working keys: the door code to show, and whether the app can open the door. */
    public function forGuest(Reservation $r): array
    {
        if (! $this->enabled() || $r->status !== ReservationStatus::CheckedIn) {
            return [];
        }
        $canOpen = $this->connected() && $this->provider()->opensDoors();

        return RoomKey::where('reservation_id', $r->id)->active()->whereIn('kind', ['pin', 'phone'])->where('valid_until', '>', now())->with('room:id,number')->orderBy('room_id')->get()
            ->filter(fn (RoomKey $k) => $k->kind === 'pin' ? (bool) config('hotel.keys.show_pin', true) : $canOpen)
            ->map(fn (RoomKey $k) => [
                'id' => $k->id, 'kind' => $k->kind, 'room' => $k->room?->number,
                'pin' => $k->kind === 'pin' ? $k->secret : null,
                'until' => 'Works until ' . $k->valid_until->format('D j M, g:i A'),
                'can_unlock' => $k->kind === 'phone' && $k->isLive(),
            ])->values()->all();
    }
}
