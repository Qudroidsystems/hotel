<?php

namespace App\Services\Mobile\Modules;

use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Keys\RoomKey;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Services\Keys\RoomKeyService;
use Illuminate\Database\Eloquent\Model;

/** Each occupied room's keys: a new door code, a card, opening the door, cancelling a lost key. */
class RoomKeysModule extends StaffModule
{
    public function __construct(private RoomKeyService $keys)
    {
    }

    public function key(): string { return 'room_keys'; }
    public function title(): string { return 'Room keys'; }
    public function icon(): string { return 'key'; }
    public function section(): string { return 'Front desk'; }
    public function blurb(): string { return 'Door codes, cards, opening a door'; }

    public function allowed(User $user): bool
    {
        return $this->keys->enabled() && $this->keys->canManage($user);
    }

    public function searchable(): bool
    {
        return true;
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        return ReservationRoom::with(['room:id,number,lock_id', 'reservation.guest:id,first_name,last_name,is_vip'])
            ->whereNotNull('room_id')
            ->whereHas('reservation', fn ($q) => $q->where('status', ReservationStatus::CheckedIn->value)
                ->when($search, fn ($w) => $w->whereHas('guest', fn ($g) => $g->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))))
            ->when($search && ctype_digit($search), fn ($q) => $q->orWhereHas('room', fn ($r) => $r->where('number', $search))->whereHas('reservation', fn ($w) => $w->where('status', ReservationStatus::CheckedIn->value)))
            ->get()->sortBy(fn ($l) => $l->room?->number, SORT_NATURAL)->values()
            ->map(fn ($l) => $this->item($user, $l))->all();
    }

    public function find(int $id): ?Model
    {
        return ReservationRoom::with(['room:id,number,lock_id', 'reservation.guest'])->find($id);
    }

    private function liveKeys(ReservationRoom $l)
    {
        return RoomKey::where('reservation_id', $l->reservation_id)->where('room_id', $l->room_id)->whereIn('status', ['active', 'failed'])->orderBy('kind')->get();
    }

    public function item(User $user, Model $l): array
    {
        /** @var ReservationRoom $l */
        $keys = $this->liveKeys($l);

        return [
            'id' => $l->id,
            'title' => 'Room ' . $l->room?->number . ' · ' . ($l->reservation?->guest?->full_name ?? 'Guest'),
            'subtitle' => $l->reservation?->reference . ' · until ' . $l->reservation?->check_out_date?->format('D j M'),
            'badge' => $keys->where('status', 'active')->isNotEmpty() ? ['label' => $keys->where('status', 'active')->count() . ' key(s)', 'tone' => 'success'] : ['label' => 'No key', 'tone' => 'warning'],
            'chips' => array_values(array_filter([! $l->room?->lock_id ? ['label' => 'No lock linked', 'tone' => 'danger'] : null])),
            'lines' => $keys->map(fn (RoomKey $k) => ['label' => $k->kind_label, 'value' => $k->status_label . ($k->kind === 'pin' && $k->status === 'active' ? ' · ' . $k->secret : '') . ($k->card_number ? ' · ' . $k->card_number : '')])->values()->all(),
            'actions' => $this->actions($user, $l),
        ];
    }

    public function actions(User $user, Model $l): array
    {
        /** @var ReservationRoom $l */
        if (! $this->keys->canManage($user)) {
            return [];
        }
        $a = [
            ['id' => 'pin', 'label' => 'New door code', 'tone' => 'primary', 'confirm' => 'Make a new door code? The old one stops working.'],
            ['id' => 'card', 'label' => 'Record key card', 'tone' => 'neutral', 'fields' => [self::field('card_number', 'Card number', 'text', ['required' => true, 'max' => 40])]],
            ['id' => 'unlock', 'label' => 'Open the door', 'tone' => 'neutral', 'fields' => [self::field('reason', 'Why are you opening it?', 'text', ['required' => true])]],
        ];
        foreach ($this->liveKeys($l)->where('status', 'active') as $k) {
            $a[] = ['id' => 'revoke_' . $k->id, 'label' => 'Cancel ' . mb_strtolower($k->kind_label), 'tone' => 'danger', 'fields' => [
                self::field('reason', 'Why', 'text'), self::field('lost', 'The guest lost it', 'switch'),
            ]];
        }

        return $a;
    }

    public function perform(User $user, Model $l, string $action, array $input, array $files): string
    {
        /** @var ReservationRoom $l */
        abort_unless($this->keys->canManage($user), 403);
        $r = $l->reservation;
        $room = $l->room ?? throw new BookingException('No room on this line.');
        if (str_starts_with($action, 'revoke_')) {
            $k = RoomKey::where('reservation_id', $l->reservation_id)->where('room_id', $l->room_id)->find((int) substr($action, 7)) ?? throw new BookingException('That key is gone.');
            $lost = (bool) ($input['lost'] ?? false);
            $this->keys->revoke($k, $user, trim((string) ($input['reason'] ?? '')) ?: ($lost ? 'Reported lost' : 'Cancelled at the desk'), $lost);

            return $lost ? 'Marked lost.' : 'Key cancelled.';
        }

        return match ($action) {
            'pin' => 'New door code for room ' . $room->number . ': ' . $this->keys->newPin($r, $room, $user)->secret . '. Tell the guest — it is also in their app.',
            'card' => tap("Key card recorded for room {$room->number}.", fn () => $this->keys->recordCard($r, $room, (string) $input['card_number'], $user)),
            'unlock' => tap("Room {$room->number}'s door is open.", fn () => $this->keys->staffUnlock($room, $user, (string) $input['reason'])),
            default => throw new BookingException('Unknown action.'),
        };
    }

    public function emptyText(?string $tab): string
    {
        return 'Nobody in house.';
    }
}
