<?php

namespace App\Services\Loyalty;

use App\Enums\ChargeType;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Folio;
use App\Models\FolioCharge;
use App\Models\Guest;
use App\Models\Loyalty\LoyaltyEntry;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Support\Audit;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Guest loyalty (Hotel Settings → Guest Loyalty).
 *
 * Earning follows the bill: when a stay's folio closes (stay over and nothing
 * owed) the guest earns points on what they spent before tax; if the bill is
 * reopened or changed, the points follow it. Spending points puts a credit
 * line on the bill; voiding that line gives the points back. Every change is a
 * new ledger line, so the history always adds up to the balance.
 */
class LoyaltyService
{
    public const TIERS = [
        'member' => ['Member', '#64748b'],
        'silver' => ['Silver', '#94a3b8'],
        'gold'   => ['Gold', '#b7832f'],
    ];

    public function __construct(private FolioService $folios)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('hotel.loyalty.enabled', true);
    }

    // ── Group loyalty (several hotels) ────────────────────────────────

    /** One membership across every hotel of the group: shared number, points and tier. */
    public function grouped(): bool
    {
        return CurrentHotel::multi() && (bool) config('hotel.group_loyalty', true);
    }

    /** The same member's guest records at the other hotels (empty with one hotel). */
    public function siblings(Guest $guest): Collection
    {
        if (! $this->grouped() || ! $guest->loyalty_number) {
            return new Collection();
        }

        return Guest::withoutGlobalScope('hotel')->where('loyalty_number', $guest->loyalty_number)->whereKeyNot($guest->id)->get();
    }

    /** Ids of all the member's guest records, this one first. */
    public function memberIds(Guest $guest): array
    {
        return array_merge([$guest->id], $this->siblings($guest)->modelKeys());
    }

    /**
     * The member record at another hotel this guest is the same person as:
     * same email, or the same phone number (last 9 digits). Only members with
     * a number, so there is something to share.
     */
    public function findMember(Guest $guest): ?Guest
    {
        if (! $this->grouped()) {
            return null;
        }
        $others = fn () => Guest::withoutGlobalScope('hotel')->whereNotNull('loyalty_number')->where('hotel_id', '!=', (int) $guest->hotel_id);
        if ($email = mb_strtolower(trim((string) $guest->email))) {
            if ($found = $others()->whereRaw('LOWER(email) = ?', [$email])->orderBy('id')->first()) {
                return $found;
            }
        }
        $digits = substr(preg_replace('/\D+/', '', (string) $guest->phone), -9);
        if (strlen($digits) === 9) {
            return $others()->where('phone', 'like', '%' . substr($digits, -4))->orderBy('id')->get()
                ->first(fn (Guest $g) => substr(preg_replace('/\D+/', '', (string) $g->phone), -9) === $digits);
        }

        return null;
    }

    /**
     * Join a guest with no member number to their membership at another hotel:
     * they take its number, points and tier. Answers whether they were linked.
     */
    public function link(Guest $guest): bool
    {
        if ($guest->loyalty_number || ! ($member = $this->findMember($guest)) || $this->numberTaken($member->loyalty_number, (int) $guest->hotel_id)) {
            return false;
        }
        $guest->forceFill([
            'loyalty_number' => $member->loyalty_number,
            'loyalty_points' => (int) $member->loyalty_points,
            'loyalty_tier'   => $member->loyalty_tier ?: 'member',
        ])->saveQuietly();

        return true;
    }

    /** Another guest record at that hotel already has the number (a duplicate guest record). */
    private function numberTaken(string $number, int $hotelId): bool
    {
        return Guest::withoutGlobalScope('hotel')->where('hotel_id', $hotelId)->where('loyalty_number', $number)->exists();
    }

    /**
     * Turning group loyalty on: join up members who already have a separate
     * number at more than one hotel. The older number is kept and the points
     * are added together (each side gets a history line for what came over).
     * Answers how many memberships were joined.
     */
    public function linkAll(): int
    {
        if (! $this->grouped()) {
            return 0;
        }

        return CurrentHotel::get()->across(function () {
            $joined = 0;
            foreach (Guest::whereNotNull('loyalty_number')->orderBy('id')->lazyById(200) as $guest) {
                $guest->refresh();
                $member = $this->findMember($guest);
                if (! $member || $member->loyalty_number === $guest->loyalty_number || $member->id > $guest->id) {
                    continue;   // nothing to join, already joined, or joined when the older record comes round
                }
                $from = Guest::where('loyalty_number', $guest->loyalty_number)->get();
                $into = Guest::where('loyalty_number', $member->loyalty_number)->get();
                if ($from->pluck('hotel_id')->intersect($into->pluck('hotel_id'))->isNotEmpty()) {
                    continue;   // the same hotel on both sides: duplicate guest records to merge by hand
                }
                DB::transaction(function () use ($from, $into, $member) {
                    $a = (int) $into->first()->loyalty_points;
                    $b = (int) $from->first()->loyalty_points;
                    $total = $a + $b;
                    foreach ([[$from, $a, 'Joined group membership ' . $member->loyalty_number], [$into, $b, 'Points brought from another hotel of the group']] as [$records, $add, $note]) {
                        foreach ($records as $g) {
                            $g->forceFill(['loyalty_number' => $member->loyalty_number, 'loyalty_points' => $total])->saveQuietly();
                            if ($add !== 0) {
                                (new LoyaltyEntry())->forceFill([
                                    'hotel_id' => $g->hotel_id, 'guest_id' => $g->id, 'type' => 'adjust', 'points' => $add,
                                    'balance_after' => $total, 'note' => $note,
                                ])->save();
                            }
                        }
                    }
                });
                $this->refreshTier($member->fresh());
                $joined++;
            }

            return $joined;
        });
    }

    // ── Members and tiers ─────────────────────────────────────────────

    public function ensureNumber(Guest $guest): string
    {
        if (! $guest->loyalty_number && ! $this->link($guest)) {
            $guest->forceFill(['loyalty_number' => strtoupper((string) config('hotel.loyalty.prefix', 'SH')) . str_pad((string) $guest->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
        }

        return $guest->loyalty_number;
    }

    /** Nights from stays that ended in the last 12 months (at every hotel of the group, with group loyalty). */
    public function recentNights(Guest $guest): int
    {
        return (int) Reservation::withoutGlobalScope('hotel')->whereIn('guest_id', $this->memberIds($guest))
            ->where('status', ReservationStatus::CheckedOut->value)
            ->where('check_out_date', '>=', today()->subYear())
            ->get(['check_in_date', 'check_out_date', 'checked_out_at'])
            ->sum(fn ($r) => self::nightsStayed($r));
    }

    /** Nights actually stayed (a guest who leaves early stayed fewer than booked; a day use counts as one). */
    public static function nightsStayed(Reservation $r): int
    {
        return (int) max(1, $r->check_in_date->diffInDays(($r->checked_out_at ?? $r->check_out_date)->copy()->startOfDay()));
    }

    public function tierFor(Guest $guest): string
    {
        $nights = $this->recentNights($guest);

        return match (true) {
            $nights >= (int) config('hotel.loyalty.gold_nights', 15)   => 'gold',
            $nights >= (int) config('hotel.loyalty.silver_nights', 5)  => 'silver',
            default                                                    => 'member',
        };
    }

    public function refreshTier(Guest $guest): string
    {
        $tier = $this->tierFor($guest);
        if ($guest->loyalty_tier !== $tier) {
            $old = $guest->loyalty_tier;
            $guest->forceFill(['loyalty_tier' => $tier])->saveQuietly();
            Audit::record('guests.loyalty_tier', "{$guest->full_name} is now " . self::TIERS[$tier][0] . ' (was ' . (self::TIERS[$old][0] ?? $old) . ')', $guest);
        }
        foreach ($this->siblings($guest) as $other) {
            if ($other->loyalty_tier !== $tier) {
                $other->forceFill(['loyalty_tier' => $tier])->saveQuietly();
            }
        }

        return $tier;
    }

    /** % off room rates booked online for the guest's tier. */
    public function discountPercent(Guest $guest): float
    {
        if (! $this->enabled()) {
            return 0.0;
        }

        return match ($guest->loyalty_tier) {
            'gold'   => (float) config('hotel.loyalty.gold_discount', 0),
            'silver' => (float) config('hotel.loyalty.silver_discount', 0),
            default  => 0.0,
        };
    }

    /** What the next tier needs, for the profile: [tier, nights to go] or null at the top. */
    public function nextTier(Guest $guest): ?array
    {
        $nights = $this->recentNights($guest);
        foreach (['silver', 'gold'] as $t) {
            $need = (int) config("hotel.loyalty.{$t}_nights");
            if ($nights < $need) {
                return [$t, $need - $nights, $need];
            }
        }

        return null;
    }

    public function pointsValue(int $points): float
    {
        return round($points * (float) config('hotel.loyalty.point_value', 1), 2);
    }

    // ── Earning ───────────────────────────────────────────────────────

    /** Points this stay's bill is worth now: 0 until it is settled after check-out. */
    public function earnableFor(Folio $folio): array
    {
        $r = $folio->reservation;
        if (! $r || $r->status !== ReservationStatus::CheckedOut || $folio->isOpen()) {
            return [0, 0.0];
        }
        $spend = round((float) $folio->charges()->whereNull('voided_at')->sum('amount'), 2);
        $points = (int) floor(max(0, $spend) / 1000 * (int) config('hotel.loyalty.points_per_1000', 10));

        return [$points, $spend];
    }

    /** Bring the stay's earned points in line with its bill. Safe to call any number of times. */
    public function syncEarn(Folio $folio): int
    {
        $r = $folio->reservation;
        $guest = $r?->guest;
        if (! $guest || ! $this->enabled()) {
            return 0;
        }
        [$target, $spend] = $this->earnableFor($folio);

        return DB::transaction(function () use ($guest, $r, $target, $spend) {
            $guest = Guest::whereKey($guest->id)->lockForUpdate()->first();
            $have = (int) LoyaltyEntry::where('reservation_id', $r->id)->where('type', 'earn')->sum('points');
            $diff = $target - $have;
            if ($diff !== 0) {
                $note = $have === 0
                    ? "Stay {$r->reference}"
                    : ($target === 0 ? "Stay {$r->reference}: bill reopened, points held until it is settled" : "Stay {$r->reference}: bill changed");
                $this->write($guest, 'earn', $diff, $note, ['reservation_id' => $r->id, 'amount' => $spend]);
            }
            $this->ensureNumber($guest);
            $this->refreshTier($guest);

            return $diff;
        });
    }

    // ── Spending ──────────────────────────────────────────────────────

    /** Spend points on an open bill: a credit line for their value. Answers the line posted. */
    public function redeem(Folio $folio, int $points, User $by): FolioCharge
    {
        if (! $this->enabled()) {
            throw new BookingException('The loyalty programme is switched off.');
        }
        $guest = $folio->guest ?? $folio->reservation?->guest;
        if (! $guest) {
            throw new BookingException('This bill has no guest.');
        }
        $min = (int) config('hotel.loyalty.min_redeem', 500);
        if ($points < $min) {
            throw new BookingException("Spend at least {$min} points at a time.");
        }
        if (! $folio->isOpen()) {
            throw new BookingException('This bill is closed.');
        }

        return DB::transaction(function () use ($folio, $guest, $points, $by) {
            $guest = Guest::whereKey($guest->id)->lockForUpdate()->first();
            if ($points > $guest->loyalty_points) {
                throw new BookingException("{$guest->first_name} has " . number_format($guest->loyalty_points) . ' points.');
            }
            $value = $this->pointsValue($points);
            $owed = $folio->balance();
            if ($value > $owed + 0.001) {
                throw new BookingException('That is worth more than the bill (' . $this->folios->money(max(0, $owed)) . ' due). Spend fewer points.');
            }

            $entry = $this->write($guest, 'redeem', -$points, 'Spent on bill ' . $folio->number, ['reservation_id' => $folio->reservation_id, 'amount' => $value], $by);
            $charge = $this->folios->postCharge($folio, ChargeType::Adjustment, 'Paid with loyalty points (' . number_format($points) . ' pts)', 1, -$value, null,
                ['source_type' => LoyaltyEntry::class, 'source_id' => $entry->id]);
            $entry->forceFill(['folio_charge_id' => $charge->id])->save();
            Audit::record('guests.loyalty_redeemed', "{$guest->full_name} spent " . number_format($points) . ' points (' . $this->folios->money($value) . ") on {$folio->number}", $guest, by: $by);

            return $charge;
        });
    }

    /** A points line on a bill was voided: give the points back (once). */
    public function chargeVoided(FolioCharge $charge): void
    {
        if ($charge->source_type !== LoyaltyEntry::class || ! $charge->voided_at) {
            return;
        }
        $spent = LoyaltyEntry::find($charge->source_id);
        if (! $spent || LoyaltyEntry::where('folio_charge_id', $charge->id)->where('type', 'adjust')->exists()) {
            return;
        }
        DB::transaction(function () use ($spent, $charge) {
            $guest = Guest::whereKey($spent->guest_id)->lockForUpdate()->first();
            $this->write($guest, 'adjust', abs($spent->points), 'Points returned: bill line voided', ['reservation_id' => $spent->reservation_id, 'folio_charge_id' => $charge->id]);
        });
    }

    // ── By hand ───────────────────────────────────────────────────────

    public function adjust(Guest $guest, int $points, string $reason, User $by): LoyaltyEntry
    {
        if ($points === 0) {
            throw new BookingException('Enter the points to add or take away.');
        }

        return DB::transaction(function () use ($guest, $points, $reason, $by) {
            $guest = Guest::whereKey($guest->id)->lockForUpdate()->first();
            if ($guest->loyalty_points + $points < 0) {
                throw new BookingException("{$guest->first_name} only has " . number_format($guest->loyalty_points) . ' points.');
            }
            $this->ensureNumber($guest);
            $entry = $this->write($guest, 'adjust', $points, $reason, [], $by);
            Audit::record('guests.loyalty_adjusted', ($points > 0 ? 'Added ' : 'Took away ') . number_format(abs($points)) . " points for {$guest->full_name}", $guest,
                ['points' => $guest->loyalty_points - $points], ['points' => $guest->loyalty_points], $reason, by: $by);

            return $entry;
        });
    }

    private function write(Guest $guest, string $type, int $points, string $note, array $extra = [], ?User $by = null): LoyaltyEntry
    {
        $siblings = $this->siblings($guest);
        if ($siblings->isNotEmpty()) {   // the shared balance: lock every hotel's copy and start from the newest
            Guest::withoutGlobalScope('hotel')->whereKey($siblings->modelKeys())->lockForUpdate()->get();
        }
        $balance = (int) $guest->loyalty_points + $points;
        $guest->forceFill(['loyalty_points' => $balance])->saveQuietly();
        if ($siblings->isNotEmpty()) {
            Guest::withoutGlobalScope('hotel')->whereKey($siblings->modelKeys())->update(['loyalty_points' => $balance]);
        }

        return LoyaltyEntry::create($extra + [
            'guest_id' => $guest->id, 'type' => $type, 'points' => $points, 'balance_after' => $balance,
            'note' => mb_substr($note, 0, 255), 'created_by' => $by?->id ?? auth()->id(),
        ]);
    }

    // ── Profile figures ───────────────────────────────────────────────

    /** The member's points history, from every hotel of the group with group loyalty. */
    public function history(Guest $guest, int $limit = 12): Collection
    {
        return LoyaltyEntry::withoutGlobalScope('hotel')->whereIn('guest_id', $this->memberIds($guest))
            ->with(['reservation' => fn ($q) => $q->withoutGlobalScope('hotel')->select('id', 'reference'), 'creator:id,name', 'hotel:id,name'])
            ->latest('id')->limit($limit)->get();
    }

    /** Where the member has stayed across the group: [hotel name => stays], for the profile. */
    public function groupStays(Guest $guest): array
    {
        if (! $this->grouped()) {
            return [];
        }

        return Reservation::withoutGlobalScope('hotel')->whereIn('guest_id', $this->memberIds($guest))
            ->where('status', ReservationStatus::CheckedOut->value)
            ->selectRaw('hotel_id, count(*) as n')->groupBy('hotel_id')->pluck('n', 'hotel_id')
            ->mapWithKeys(fn ($n, $id) => [\App\Models\Hotel::find($id)?->name ?? '—' => (int) $n])->all();
    }

    /** Completed stays, nights, money spent, average per stay, last stay, favourite room type. */
    public function stats(Guest $guest): array
    {
        $stays = $guest->reservations()->reorder()->where('status', ReservationStatus::CheckedOut->value)->with('rooms.roomType:id,name', 'folio')->get();
        $spent = $stays->sum(fn (Reservation $r) => $r->folio ? $r->folio->chargesTotal() : (float) $r->total_amount);
        $nights = $stays->sum(fn (Reservation $r) => self::nightsStayed($r));
        $types = $stays->flatMap(fn ($r) => $r->rooms->pluck('roomType.name'))->filter()->countBy()->sortDesc();

        return [
            'stays'     => $stays->count(),
            'nights'    => $nights,
            'spent'     => round($spent, 2),
            'average'   => $stays->count() ? round($spent / $stays->count(), 2) : 0,
            'last'      => ($last = $stays->sortByDesc(fn ($r) => $r->checked_out_at ?? $r->check_out_date)->first()) ? ($last->checked_out_at ?? $last->check_out_date) : null,
            'favourite' => $types->keys()->first(),
            'upcoming'  => $guest->reservations()->reorder()->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value])
                ->where('check_in_date', '>=', today())->orderBy('check_in_date')->first(),
        ];
    }
}
