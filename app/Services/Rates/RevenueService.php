<?php

namespace App\Services\Rates;

use App\Enums\ReservationStatus as S;
use App\Models\Rates\RateDay;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Revenue tools (Rates → Revenue): for each room type and each of the next
 * nights, what is already booked, what was booked in the last week (pickup),
 * the price now, and a suggested price from the rules in Hotel Settings →
 * Price Suggestions. Suggestions can be applied by hand or every night
 * automatically; they only ever change nights nobody priced by hand.
 */
class RevenueService
{
    public function __construct(private RateService $rates)
    {
    }

    public function rules(): array
    {
        $r = (array) config('hotel.revenue', []);

        return [
            'high_occupancy' => (float) ($r['high_occupancy'] ?? 80), 'high_change' => (float) ($r['high_change'] ?? 15),
            'mid_occupancy'  => (float) ($r['mid_occupancy'] ?? 60),  'mid_change'  => (float) ($r['mid_change'] ?? 5),
            'low_days'       => (int) ($r['low_days'] ?? 7), 'low_occupancy' => (float) ($r['low_occupancy'] ?? 35), 'low_change' => (float) ($r['low_change'] ?? 10),
            'max_change'     => (float) ($r['max_change'] ?? 25), 'round_to' => max(1, (int) ($r['round_to'] ?? 500)),
            'auto'           => (bool) ($r['auto'] ?? false),
        ];
    }

    /**
     * The grid: [types => [...], nights => [date => [type_id => cell]]], each cell
     * {rooms, sold, occupancy, pickup, price, base, suggested, change, why, source}.
     */
    public function grid(int $days = 30, ?Carbon $from = null): array
    {
        $from = ($from ?? today())->copy()->startOfDay();
        $to = $from->copy()->addDays($days - 1);
        $rules = $this->rules();
        $types = RoomType::where('is_active', true)->orderBy('name')->get();
        $roomsByType = Room::active()->selectRaw('room_type_id, count(*) as n')->groupBy('room_type_id')->pluck('n', 'room_type_id');

        // Rooms on the books for each night (and the ones booked in the last 7 days).
        $lines = ReservationRoom::query()
            ->join('reservations', 'reservations.id', '=', 'reservation_rooms.reservation_id')
            ->whereIn('reservations.status', [S::Pending->value, S::Confirmed->value, S::CheckedIn->value])
            ->where('reservations.check_in_date', '<=', $to->toDateString())
            ->where('reservations.check_out_date', '>', $from->toDateString())
            ->get(['reservation_rooms.room_type_id', 'reservations.check_in_date', 'reservations.check_out_date', 'reservations.created_at']);
        $weekAgo = now()->subDays(7);

        $nights = [];
        foreach ($types as $type) {
            $daysRows = $this->rates->days($type->id, $from->toDateString(), $to->toDateString());
            $rooms = (int) ($roomsByType[$type->id] ?? 0);
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $date = $d->toDateString();
                $on = $lines->filter(fn ($l) => (int) $l->room_type_id === $type->id
                    && Carbon::parse($l->check_in_date)->toDateString() <= $date && Carbon::parse($l->check_out_date)->toDateString() > $date);
                $sold = $on->count();
                $pickup = $on->filter(fn ($l) => Carbon::parse($l->created_at)->gte($weekAgo))->count();
                $occ = $rooms ? round($sold / $rooms * 100, 1) : 0;
                $day = $daysRows[$date] ?? null;
                $price = $this->rates->nightRate($type, $date);
                // A suggested price builds on the plain price, never on an earlier suggestion.
                $base = $day && $day->source === 'suggested' ? $this->withoutDay($type, $date) : $price;
                [$change, $why] = $this->change($occ, (int) today()->diffInDays($d, false), $rules);
                $suggested = $this->round($base * (1 + $change / 100), $rules['round_to']);
                $manual = $day && $day->price !== null && $day->source !== 'suggested';

                $nights[$date][$type->id] = [
                    'rooms' => $rooms, 'sold' => $sold, 'occupancy' => $occ, 'pickup' => $pickup,
                    'price' => $price, 'base' => $base, 'suggested' => $suggested, 'change' => $change, 'why' => $why,
                    'manual' => $manual, 'closed' => (bool) $day?->closed,
                    'differs' => ! $manual && abs($suggested - $price) >= 0.01,
                ];
            }
        }

        return ['types' => $types, 'nights' => $nights, 'rules' => $rules, 'from' => $from, 'to' => $to];
    }

    /** Totals per night across room types, for the pickup report. */
    public function pickup(array $grid): Collection
    {
        return collect($grid['nights'])->map(function ($cells, $date) {
            $rooms = array_sum(array_column($cells, 'rooms'));
            $sold = array_sum(array_column($cells, 'sold'));

            return [
                'date' => Carbon::parse($date), 'rooms' => $rooms, 'sold' => $sold,
                'occupancy' => $rooms ? round($sold / $rooms * 100, 1) : 0,
                'pickup' => array_sum(array_column($cells, 'pickup')),
                'revenue' => array_sum(array_map(fn ($c) => $c['sold'] * $c['price'], $cells)),
            ];
        })->values();
    }

    /**
     * Set suggested prices: $cells is ["typeId|date", ...] or null for every
     * night that differs. Nights priced by hand are left alone. Answers how many changed.
     */
    public function apply(?array $cells = null, ?int $by = null, bool $auto = false): int
    {
        $grid = $this->grid();
        $want = $cells === null ? null : array_flip($cells);
        $n = 0;
        foreach ($grid['nights'] as $date => $byType) {
            foreach ($byType as $typeId => $c) {
                if (($want !== null && ! isset($want["{$typeId}|{$date}"])) || $c['manual'] || ! $c['differs']) {
                    continue;
                }
                $day = RateDay::firstOrNew(['room_type_id' => $typeId, 'date' => $date]);
                $same = abs($c['suggested'] - $c['base']) < 0.01;
                if ($same && $day->exists && ! $day->closed && $day->min_nights === null) {
                    $day->delete();   // back to the plain price
                } else {
                    $day->forceFill([
                        'price' => $same ? null : $c['suggested'], 'source' => $same ? null : 'suggested',
                        'note' => $same ? $day->note : mb_substr("Suggested: {$c['why']}", 0, 150), 'updated_by' => $by,
                    ])->save();
                }
                $n++;
            }
        }
        if ($n) {
            Audit::record('rates.suggestions_applied', ($auto ? 'Automatic: ' : '') . "{$n} night price(s) set from the suggestions");
        }

        return $n;
    }

    /** % change and the reason in words. */
    private function change(float $occ, int $daysAway, array $r): array
    {
        $pct = match (true) {
            $occ >= $r['high_occupancy'] => [$r['high_change'], round($occ) . '% booked'],
            $occ >= $r['mid_occupancy']  => [$r['mid_change'], round($occ) . '% booked'],
            $daysAway <= $r['low_days'] && $occ < $r['low_occupancy'] => [-$r['low_change'], round($occ) . "% booked, {$daysAway} day" . ($daysAway === 1 ? '' : 's') . ' away'],
            default                      => [0, round($occ) . '% booked'],
        };
        $pct[0] = max(-$r['max_change'], min($r['max_change'], $pct[0]));

        return $pct;
    }

    private function round(float $v, int $to): float
    {
        return max(0, round($v / $to) * $to);
    }

    /** The night's price as if no calendar entry existed (rate, room-type weekday price, season). */
    private function withoutDay(RoomType $type, string $date): float
    {
        return $this->rates->quote($type, $date, Carbon::parse($date)->addDay()->toDateString(), null, null, false)[$date] ?? $this->rates->nightRate($type, $date);
    }
}
