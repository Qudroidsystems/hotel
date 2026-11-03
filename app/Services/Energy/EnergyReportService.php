<?php

namespace App\Services\Energy;

use App\Enums\ReservationStatus as S;
use App\Models\Energy\EnergyAction;
use App\Models\Energy\EnergyEvent;
use App\Models\Energy\EnergyFuelDelivery;
use App\Models\Energy\EnergySample;
use App\Models\Energy\EnergySource;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fuel & energy over a period, for the owner and the control room:
 *  - the grid: hours on and off, outages and the longest one;
 *  - each generator: hours run, diesel delivered, diesel it should have burned (from the 15-minute samples) and,
 *    where the tank was read at both ends, diesel actually gone — with the difference;
 *  - energy used (estimated from what was needed), its cost, and that cost per occupied room-night;
 *  - what switching off saved, and day by day.
 * Estimates come from the watts set for rooms and places; they show the trend and the gaps, not a meter reading.
 */
class EnergyReportService
{
    public function __construct(private EnergyAdvisor $energy)
    {
    }

    public function build(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $until = $to->copy()->min(now());
        $s = $this->energy->settings();
        $sources = EnergySource::orderByRaw("kind = 'grid' DESC")->orderBy('capacity_kw')->get();
        $grid = $sources->firstWhere('kind', 'grid');
        $sets = $sources->where('kind', '!=', 'grid')->values();
        $deliveries = EnergyFuelDelivery::with('source:id,name', 'expense:id,number')->whereBetween('delivered_at', [$from, $to])->orderBy('delivered_at')->get();

        // The grid: off stretches.
        $outages = $grid ? $this->stretches($grid, $from, $until, 'off') : collect();
        $hours = max(0.01, $from->diffInMinutes($until) / 60);
        $offHours = round($outages->sum(fn ($o) => $o['hours']), 1);

        // Generators.
        $gens = $sets->map(function (EnergySource $g) use ($from, $until, $deliveries) {
            $runs = $this->stretches($g, $from, $until, 'on');
            $delivered = $deliveries->where('source_id', $g->id);
            $expected = $this->energy->expectedLitres($g, $from, $until);
            $measured = $this->measured($g, $from, $until);

            return [
                'source' => $g, 'hours' => round($runs->sum('hours'), 1), 'starts' => $runs->count(),
                'delivered_litres' => round($delivered->sum('litres'), 1), 'delivered_cost' => round($delivered->sum('total'), 2),
                'expected_litres' => $expected, 'measured' => $measured,
                'variance' => $measured && $expected > 0 ? round(($measured['used'] - $expected) / $expected * 100) : null,
                'per_hour' => $runs->sum('hours') > 0 ? round($expected / $runs->sum('hours'), 1) : null,
            ];
        });

        // Energy, from the samples.
        $samples = EnergySample::whereBetween('at', [$from, $until])->orderBy('at')->get();
        $dur = $this->durations($samples);
        $kwh = $kwhGrid = $kwhGen = $wasteKwh = 0.0;
        $daily = [];
        foreach ($samples as $i => $smp) {
            $e = $smp->kw * $dur[$i];
            $kwh += $e;
            $smp->grid_on ? $kwhGrid += $e : $kwhGen += $e;
            $wasteKwh += $smp->waste_kw * $dur[$i];
            $d = $smp->at->toDateString();
            $daily[$d]['kwh'] = ($daily[$d]['kwh'] ?? 0) + $e;
            $daily[$d]['litres'] = ($daily[$d]['litres'] ?? 0) + $smp->litres_per_hour * $dur[$i];
            $daily[$d]['peak'] = max($daily[$d]['peak'] ?? 0, $smp->kw);
        }
        $expectedLitres = round($gens->sum('expected_litres'), 1);
        $avgPrice = $deliveries->sum('litres') > 0 ? $deliveries->sum('total') / $deliveries->sum('litres') : $s['diesel_price'];
        $dieselCost = round($expectedLitres * $avgPrice);
        $gridCost = round($kwhGrid * $s['grid_price']);
        $roomNights = $this->roomNights($from, $until);

        // Day by day.
        $days = [];
        for ($d = $from->copy(); $d->lte($until); $d->addDay()) {
            $key = $d->toDateString();
            $dayEnd = $d->copy()->endOfDay()->min($until);
            $days[] = [
                'date' => $d->copy(), 'kwh' => round($daily[$key]['kwh'] ?? 0, 1), 'peak' => round($daily[$key]['peak'] ?? 0, 1),
                'litres' => round($daily[$key]['litres'] ?? 0, 1),
                'grid_off' => round($outages->sum(fn ($o) => $this->overlapHours($o['from'], $o['to'], $d, $dayEnd)), 1),
                'delivered' => round($deliveries->filter(fn ($x) => $x->delivered_at->toDateString() === $key)->sum('litres'), 1),
            ];
        }

        $saved = $this->energy->savings($from, $to);

        return [
            'from' => $from, 'to' => $to, 'until' => $until, 'settings' => $s, 'has_samples' => $samples->isNotEmpty(),
            'grid' => $grid ? [
                'on_hours' => round($hours - $offHours, 1), 'off_hours' => $offHours, 'availability' => round(($hours - $offHours) / $hours * 100),
                'outages' => $outages->count(), 'longest' => $outages->sortByDesc('hours')->first(), 'list' => $outages->sortByDesc('from')->values(),
            ] : null,
            'generators' => $gens,
            'deliveries' => $deliveries,
            'delivered_litres' => round($deliveries->sum('litres'), 1), 'delivered_cost' => round($deliveries->sum('total'), 2),
            'expected_litres' => $expectedLitres, 'avg_price' => round($avgPrice, 2),
            'kwh' => round($kwh), 'kwh_grid' => round($kwhGrid), 'kwh_gen' => round($kwhGen), 'waste_kwh' => round($wasteKwh),
            'diesel_cost' => $dieselCost, 'grid_cost' => $gridCost, 'cost' => $dieselCost + $gridCost,
            'room_nights' => $roomNights, 'per_room_night' => $roomNights > 0 ? round(($dieselCost + $gridCost) / $roomNights) : null,
            'saved' => $saved,
            'actions' => [
                'done' => $saved['done'], 'skipped' => $saved['skipped'],
                'reasons' => EnergyAction::where('status', EnergyAction::SKIPPED)->whereBetween('done_at', [$from, $to])->whereNotNull('note')
                    ->selectRaw('note, count(*) as n')->groupBy('note')->orderByDesc('n')->limit(5)->pluck('n', 'note'),
            ],
            'days' => $days,
        ];
    }

    /** A short version for the weekly / monthly report e-mail; null when there's nothing to say. */
    public function summary(Carbon $from, Carbon $to): ?array
    {
        if (! $this->energy->enabled() || ! EnergySource::where('kind', '!=', 'grid')->exists() && ! EnergySample::exists()) {
            return null;
        }
        $r = $this->build($from, $to);
        if (! $r['has_samples'] && $r['deliveries']->isEmpty()) {
            return null;
        }

        return [
            'grid_availability' => $r['grid']['availability'] ?? null, 'outages' => $r['grid']['outages'] ?? 0, 'grid_off_hours' => $r['grid']['off_hours'] ?? 0,
            'generator_hours' => round($r['generators']->sum('hours'), 1), 'delivered_litres' => $r['delivered_litres'], 'delivered_cost' => $r['delivered_cost'],
            'expected_litres' => $r['expected_litres'], 'cost' => $r['cost'], 'per_room_night' => $r['per_room_night'],
            'saved_kwh' => $r['saved']['kwh'], 'saved_value' => $r['saved']['diesel_value'],
            'flags' => $r['generators']->filter(fn ($g) => ($g['variance'] ?? 0) >= 25)->map(fn ($g) => "{$g['source']->name}: {$g['measured']['used']} L gone, about {$g['expected_litres']} L expected")->values()->all(),
        ];
    }

    /** CSV of the days. */
    public function csv(array $r): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Energy needed (kWh, est.)', 'Peak (kW)', 'Grid off (h)', 'Diesel burned (L, est.)', 'Diesel delivered (L)']);
        foreach ($r['days'] as $d) {
            fputcsv($out, [$d['date']->toDateString(), $d['kwh'], $d['peak'], $d['grid_off'], $d['litres'], $d['delivered']]);
        }
        rewind($out);

        return stream_get_contents($out);
    }

    // ------------------------------------------------------------------

    /**
     * Stretches a source was on (generator running / grid on) — or, with $state 'off', off — within the period,
     * from its on/off log.
     * @return Collection<array{from: Carbon, to: Carbon, hours: float}>
     */
    public function stretches(EnergySource $g, Carbon $from, Carbon $to, string $state = 'on'): Collection
    {
        $events = EnergyEvent::where('source_id', $g->id)->whereIn('kind', ['on', 'off'])->whereBetween('created_at', [$from, $to])->orderBy('created_at')->orderBy('id')->get(['kind', 'created_at']);
        $before = EnergyEvent::where('source_id', $g->id)->whereIn('kind', ['on', 'off'])->where('created_at', '<', $from)->latest('created_at')->latest('id')->value('kind');
        // No history before: what it is now, if nothing changed in the period; else the opposite of the first change.
        $current = $before ?? ($events->isNotEmpty() ? ($events->first()->kind === 'on' ? 'off' : 'on') : ($g->running ? 'on' : 'off'));
        $out = collect();
        $start = $current === $state ? $from->copy() : null;
        foreach ($events as $e) {
            if ($e->kind === $current) {
                continue;
            }
            $current = $e->kind;
            if ($current === $state) {
                $start = $e->created_at->copy();
            } elseif ($start) {
                $out->push(['from' => $start, 'to' => $e->created_at->copy(), 'hours' => round($start->diffInMinutes($e->created_at) / 60, 2)]);
                $start = null;
            }
        }
        if ($start) {
            $out->push(['from' => $start, 'to' => $to->copy(), 'hours' => round($start->diffInMinutes($to) / 60, 2), 'open' => true]);
        }

        return $out->filter(fn ($x) => $x['hours'] > 0)->values();
    }

    /** Diesel actually gone, from the tank readings nearest the start and the end of the period. */
    private function measured(EnergySource $g, Carbon $from, Carbon $to): ?array
    {
        $readings = EnergyEvent::where('source_id', $g->id)->where('kind', 'fuel')->whereNotNull('value');
        $first = (clone $readings)->where('created_at', '<=', $from->copy()->addHours(12))->latest('created_at')->first()
            ?? (clone $readings)->whereBetween('created_at', [$from, $to])->oldest('created_at')->first();
        $last = (clone $readings)->whereBetween('created_at', [$from, $to])->latest('created_at')->first();
        if (! $first || ! $last || $first->id === $last->id || $last->created_at->lte($first->created_at)) {
            return null;
        }
        $delivered = (float) EnergyFuelDelivery::where('source_id', $g->id)->whereBetween('delivered_at', [$first->created_at, $last->created_at])->sum('litres');
        $expected = $this->energy->expectedLitres($g, $first->created_at, $last->created_at);

        return ['from' => $first->created_at, 'to' => $last->created_at, 'start' => (float) $first->value, 'end' => (float) $last->value,
            'delivered' => round($delivered, 1), 'used' => round((float) $first->value + $delivered - (float) $last->value, 1), 'expected' => $expected];
    }

    /** Hours each sample stands for: until the next one, at most 30 minutes. */
    private function durations(Collection $samples): array
    {
        $out = [];
        $list = $samples->values();
        foreach ($list as $i => $smp) {
            $next = $list[$i + 1] ?? null;
            $out[$i] = min(30, $next ? $smp->at->diffInMinutes($next->at) : 15) / 60;
        }

        return $out;
    }

    /** Rooms occupied each night of the period (a two-room booking for three nights is six). */
    private function roomNights(Carbon $from, Carbon $to): int
    {
        $n = 0;
        Reservation::whereIn('status', [S::CheckedIn->value, S::CheckedOut->value])->where('check_in_date', '<=', $to->toDateString())->where('check_out_date', '>', $from->toDateString())
            ->withCount('rooms')->chunk(500, function ($list) use ($from, $to, &$n) {
                foreach ($list as $r) {
                    $start = $r->check_in_date->copy()->max($from->copy()->startOfDay());
                    $end = $r->check_out_date->copy()->min($to->copy()->addDay()->startOfDay());
                    $n += max(0, (int) $start->diffInDays($end)) * max(1, $r->rooms_count);
                }
            });

        return $n;
    }

    private function overlapHours(Carbon $a, Carbon $b, Carbon $from, Carbon $to): float
    {
        $s = $a->copy()->max($from);
        $e = $b->copy()->min($to);

        return $e->gt($s) ? $s->diffInMinutes($e) / 60 : 0.0;
    }
}
