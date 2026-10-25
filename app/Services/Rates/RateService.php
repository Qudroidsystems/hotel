<?php

namespace App\Services\Rates;

use App\Exceptions\BookingException;
use App\Models\Rates\RateDay;
use App\Models\Rates\RatePlan;
use App\Models\Rates\RateSeason;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a night costs.
 *
 *  1. The night's rate: the rate calendar's price for that room type and date if one is set; otherwise the
 *     room type's rate (weekend rate on Friday and Saturday nights) — or the room's own custom rate — moved
 *     up or down by the season covering that night (the season with the highest priority wins).
 *  2. A rate plan then works from that: its fixed price for the room type, or the night's rate less its
 *     discount; plus its nightly extra (breakfast, say).
 *
 * Stay rules: a plan's own rules (dates, arrival days, nights, booking ahead, room types, where it is sold,
 * promo code, company) apply everywhere. Closed nights and minimum stays from seasons and the rate calendar
 * apply to online bookings; the front desk sees them as warnings and may still sell.
 */
class RateService
{
    public const FRONT_DESK = 'front_desk';
    public const ONLINE = 'online';

    /** Nightly prices for a stay: {"2026-12-24": 65000.0, …}. */
    public function quote(RoomType $type, $in, $out, ?Room $room = null, ?RatePlan $plan = null): array
    {
        $nights = Reservation::nightsBetween($in, $out);
        if (! $nights) {
            return [];
        }
        $days = $this->days($type->id, $nights[0], end($nights));
        $seasons = $this->seasons($nights[0], end($nights));

        $rates = [];
        foreach ($nights as $night) {
            $rate = $this->baseRate($type, $night, $room, $days, $seasons);
            $rates[$night] = $plan ? $this->applyPlan($plan, $type, $rate) : $rate;
        }

        return $rates;
    }

    /** The night's rate before any plan. */
    public function nightRate(RoomType $type, string $date, ?Room $room = null): float
    {
        return $this->baseRate($type, $date, $room, $this->days($type->id, $date, $date), $this->seasons($date, $date));
    }

    public function applyPlan(RatePlan $plan, RoomType $type, float $rate): float
    {
        $fixed = $plan->fixed_prices[$type->id] ?? $plan->fixed_prices[(string) $type->id] ?? null;
        $price = $fixed !== null && $fixed !== '' ? (float) $fixed : $rate * (1 - (float) $plan->discount_percent / 100);

        return max(0, round($price + (float) $plan->nightly_extra, 2));
    }

    /** The season deciding a night's price for a room type, if any. */
    public function seasonFor(int $roomTypeId, string $date, ?Collection $seasons = null): ?RateSeason
    {
        return ($seasons ?? $this->seasons($date, $date))
            ->filter(fn (RateSeason $s) => $s->covers($date) && $s->appliesToType($roomTypeId))
            ->sortByDesc(fn (RateSeason $s) => [$s->priority, $s->starts_on->toDateString()])
            ->first();
    }

    // ── Stay rules ────────────────────────────────────────────────────

    /**
     * Why a plan can't be used for this stay (empty when it can).
     *
     * @return string[]
     */
    public function problems(RatePlan $plan, RoomType $type, Carbon $in, Carbon $out, string $channel, ?string $promo = null, ?int $companyId = null): array
    {
        $nights = $in->diffInDays($out);
        $p = [];
        if (! $plan->is_active) {
            $p[] = "The {$plan->name} rate is switched off.";
        }
        if ($channel === self::ONLINE && ! $plan->online) {
            $p[] = "The {$plan->name} rate is not sold online.";
        }
        if ($channel === self::FRONT_DESK && ! $plan->front_desk) {
            $p[] = "The {$plan->name} rate is for online bookings only.";
        }
        if ($channel === self::ONLINE && $plan->promo_code && strcasecmp(trim((string) $promo), $plan->promo_code) !== 0) {
            $p[] = "The {$plan->name} rate needs its promo code.";
        }
        if ($plan->company_id && $plan->company_id !== $companyId) {
            $p[] = "The {$plan->name} rate is for " . ($plan->company?->name ?? 'one company') . '’s bookings.';
        }
        if (! $plan->appliesToType($type->id)) {
            $p[] = "The {$plan->name} rate isn’t offered on {$type->name} rooms.";
        }
        if ($plan->valid_from && $in->lt($plan->valid_from)) {
            $p[] = "The {$plan->name} rate starts on " . $plan->valid_from->format('j M Y') . '.';
        }
        if ($plan->valid_to && $in->gt($plan->valid_to)) {
            $p[] = "The {$plan->name} rate ended on " . $plan->valid_to->format('j M Y') . '.';
        }
        if (! empty($plan->arrival_days) && ! in_array($in->dayOfWeek, array_map('intval', $plan->arrival_days), true)) {
            $p[] = "The {$plan->name} rate is for arrivals on " . collect(RatePlan::DAYS)->only(array_map('intval', $plan->arrival_days))->implode(', ') . '.';
        }
        if ($plan->min_nights && $nights < $plan->min_nights) {
            $p[] = "The {$plan->name} rate is for stays of {$plan->min_nights} nights or more.";
        }
        if ($plan->max_nights && $nights > $plan->max_nights) {
            $p[] = "The {$plan->name} rate is for stays of up to {$plan->max_nights} nights.";
        }
        if ($plan->min_advance_days && today()->diffInDays($in, false) < $plan->min_advance_days) {
            $p[] = "The {$plan->name} rate must be booked at least {$plan->min_advance_days} days ahead.";
        }

        return $p;
    }

    public function check(RatePlan $plan, RoomType $type, Carbon $in, Carbon $out, string $channel, ?string $promo = null, ?int $companyId = null): void
    {
        if ($p = $this->problems($plan, $type, $in, $out, $channel, $promo, $companyId)) {
            throw new BookingException($p[0]);
        }
    }

    /**
     * Closed nights and minimum stays from seasons and the rate calendar: the reason a stay can't be sold
     * online, or null. The front desk shows the same text as a warning.
     */
    public function restriction(RoomType $type, Carbon $in, Carbon $out): ?string
    {
        $nights = Reservation::nightsBetween($in, $out);
        if (! $nights) {
            return null;
        }
        $days = $this->days($type->id, $nights[0], end($nights));
        foreach ($nights as $night) {
            if (($days[$night] ?? null)?->closed) {
                return "{$type->name} rooms aren’t available online on the night of " . Carbon::parse($night)->format('D j M') . '.';
            }
        }
        $arrival = $in->toDateString();
        $min = max(
            (int) (($days[$arrival] ?? null)?->min_nights ?? 0),
            (int) ($this->seasonFor($type->id, $arrival)?->min_nights ?? 0),
        );
        if ($min > count($nights)) {
            return "Stays arriving on " . $in->format('D j M') . " are for {$min} nights or more.";
        }

        return null;
    }

    /** Plans that can be used for this stay and room type, cheapest first, each with its prices. */
    public function options(RoomType $type, Carbon $in, Carbon $out, string $channel, ?string $promo = null, ?int $companyId = null): Collection
    {
        return RatePlan::active()->ordered()->get()
            ->filter(fn (RatePlan $plan) => ! $this->problems($plan, $type, $in, $out, $channel, $promo, $companyId))
            ->map(function (RatePlan $plan) use ($type, $in, $out) {
                $nights = $this->quote($type, $in, $out, null, $plan);

                return (object) ['plan' => $plan, 'nights' => $nights, 'amount' => round(array_sum($nights), 2)];
            })
            ->sortBy('amount')->values();
    }

    /** An online promo code's plan, if the code is real and in use. */
    public function promo(?string $code): ?RatePlan
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : RatePlan::active()->where('online', true)->whereRaw('upper(promo_code) = ?', [$code])->first();
    }

    // ── Internals ─────────────────────────────────────────────────────

    private function baseRate(RoomType $type, string $date, ?Room $room, Collection $days, Collection $seasons): float
    {
        $day = $days[$date] ?? null;
        if ($day && $day->price !== null) {
            return round((float) $day->price, 2);
        }
        $rate = $room?->rate_override !== null ? (float) $room->rate_override : $type->rateFor(Carbon::parse($date));
        if ($season = $this->seasonFor($type->id, $date, $seasons)) {
            $rate = $rate * (1 + (float) $season->change_percent / 100) + (float) $season->change_amount;
        }

        return max(0, round($rate, 2));
    }

    /** Rate calendar entries for a room type, keyed by date. */
    private function days(int $roomTypeId, string $from, string $to): Collection
    {
        return RateDay::where('room_type_id', $roomTypeId)
            ->where('date', '>=', $from)->where('date', '<=', $to . ' 23:59:59')->get()
            ->keyBy(fn (RateDay $d) => $d->date->toDateString());
    }

    private function seasons(string $from, string $to): Collection
    {
        return RateSeason::active()
            ->where('starts_on', '<=', $to . ' 23:59:59')->where('ends_on', '>=', $from)->get();
    }
}
