@extends('layouts.master')

@section('content')
@php
    $sym = config('hotel.currency_symbol');
    $m = fn ($v) => $sym . number_format((float) $v);
    $n = fn ($v, $d = 0) => number_format((float) $v, $d);
    $from = $r['from']; $to = $r['to'];
    $chartDays = json_encode(collect($r['days'])->map(fn ($d) => ['d' => $d['date']->format('j M'), 'l' => $d['litres'], 'off' => $d['grid_off'], 'kwh' => $d['kwh']])->values());
    $presets = [
        'This month' => [today()->startOfMonth(), today()],
        'Last month' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
        'Last 7 days' => [today()->subDays(6), today()],
        'Last 30 days' => [today()->subDays(29), today()],
    ];
@endphp
<style>
    .er-var { font-weight: 700; }
    .er-var.bad { color: #b91c1c; } .er-var.ok { color: #15803d; }
    .er-range { display: flex; gap: 8px; flex-wrap: wrap; align-items: end; }
    .er-range .form-control { max-width: 170px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Fuel & energy report" icon="ri-bar-chart-box-line" :subtitle="$from->format('j M Y') . ' – ' . $to->format('j M Y') . '. Grid hours, generator hours and diesel, what energy costs per occupied room-night, and what switching off saved.'">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('energy.index') }}"><i class="ri-arrow-left-line"></i> Energy Saver</a>
            <a class="cb-hero-btn" href="{{ route('energy.fuel.index') }}"><i class="ri-gas-station-line"></i> Diesel deliveries</a>
            <a class="cb-hero-btn" href="{{ route('energy.report', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'csv' => 1]) }}"><i class="ri-download-2-line"></i> CSV</a>
        </x-slot:actions>
    </x-cb.hero>

    <form class="er-range mb-3" method="GET">
        <div><label class="form-label small mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="{{ $from->toDateString() }}"></div>
        <div><label class="form-label small mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="{{ $to->toDateString() }}"></div>
        <button class="btn btn-sm btn-primary">Show</button>
        @foreach ($presets as $label => $pair)
            @php [$a, $b] = $pair; @endphp
            <a class="btn btn-sm {{ $a->isSameDay($from) && $b->isSameDay($to) ? 'btn-dark' : 'btn-light' }}" href="{{ route('energy.report', ['from' => $a->toDateString(), 'to' => $b->toDateString()]) }}">{{ $label }}</a>
        @endforeach
    </form>

    @unless ($r['has_samples'])
        <div class="cb-banner info"><i class="ri-information-line"></i><div>No power samples in this period yet. Energy Saver notes the hotel's power every 15 minutes once the scheduler runs (<code>energy:check</code>); estimates of energy and diesel fill in from then.</div></div>
    @endunless

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Grid power available" :value="$r['grid'] ? $r['grid']['availability'] . '%' : '—'" icon="ri-plug-line" accent="teal" :hint="$r['grid'] ? $r['grid']['outages'] . ' outage(s), ' . $n($r['grid']['off_hours'], 1) . ' h off' : 'No grid recorded'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Generator hours" :value="$n($r['generators']->sum('hours'), 1) . ' h'" icon="ri-oil-line" accent="amber" :hint="'about ' . $n($r['expected_litres']) . ' L of diesel burned'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Diesel delivered" :value="$n($r['delivered_litres']) . ' L'" icon="ri-gas-station-line" accent="rose" :hint="$m($r['delivered_cost']) . ($r['delivered_litres'] > 0 ? ' · ' . $sym . $n($r['avg_price']) . '/L' : '')" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Energy per occupied room-night" :value="$r['per_room_night'] !== null ? $m($r['per_room_night']) : '—'" icon="ri-hotel-bed-line" accent="green" :hint="$n($r['room_nights']) . ' room-nights · ' . $m($r['cost']) . ' in all (est.)'" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="Diesel check, by generator" icon="ri-scales-3-line" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Generator</th><th class="num">Hours</th><th class="num">Should have burned</th><th class="num">Delivered</th><th class="num">Tank readings say</th><th class="num">Difference</th></tr></thead>
                        <tbody>
                            @forelse ($r['generators'] as $g)
                                <tr>
                                    <td data-label="Generator"><strong>{{ $g['source']->name }}</strong><div class="small text-muted">{{ $g['source']->capacity_kw }} kW · {{ $g['starts'] }} start(s){{ $g['per_hour'] ? ' · ~' . $g['per_hour'] . ' L/h' : '' }}</div></td>
                                    <td data-label="Hours" class="num">{{ $n($g['hours'], 1) }}</td>
                                    <td data-label="Should have burned" class="num">{{ $n($g['expected_litres']) }} L</td>
                                    <td data-label="Delivered" class="num">{{ $n($g['delivered_litres']) }} L</td>
                                    <td data-label="Tank readings say" class="num">
                                        @if ($g['measured'])
                                            {{ $n($g['measured']['used']) }} L gone
                                            <div class="small text-muted">{{ $n($g['measured']['start']) }} L {{ $g['measured']['from']->format('j M') }} → {{ $n($g['measured']['end']) }} L {{ $g['measured']['to']->format('j M') }}</div>
                                        @else <span class="text-muted small">Read the tank at both ends</span>@endif
                                    </td>
                                    <td data-label="Difference" class="num">
                                        @if ($g['variance'] !== null)
                                            <span class="er-var {{ $g['variance'] >= 25 ? 'bad' : ($g['variance'] <= 10 ? 'ok' : '') }}">{{ $g['variance'] > 0 ? '+' : '' }}{{ $g['variance'] }}%</span>
                                            @if ($g['variance'] >= 25)<div class="small text-danger">More gone than burned — check for leaks or diesel taken</div>@endif
                                        @else — @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted small">No generators set up. Add them on <a href="{{ route('energy.setup') }}">Places &amp; generators</a>.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2 small text-muted">"Should have burned" is worked out from what the hotel needed every 15 minutes and each set's diesel use. Read the tanks regularly (Energy Saver → Power sources) to compare.</div>
            </x-cb.card>

            <x-cb.card title="Day by day" icon="ri-calendar-line">
                <div style="height: 240px"><canvas id="er-chart" data-days="{{ $chartDays }}"></canvas></div>
            </x-cb.card>
        </div>

        <div class="col-xl-5">
            <x-cb.card title="Energy and its cost (estimated)" icon="ri-flashlight-line">
                <table class="table table-sm mb-0">
                    <tr><td>Energy needed</td><td class="text-end">{{ $n($r['kwh']) }} kWh</td></tr>
                    <tr><td class="ps-3 text-muted">on grid power</td><td class="text-end text-muted">{{ $n($r['kwh_grid']) }} kWh · {{ $m($r['grid_cost']) }}</td></tr>
                    <tr><td class="ps-3 text-muted">on generators</td><td class="text-end text-muted">{{ $n($r['kwh_gen']) }} kWh · {{ $m($r['diesel_cost']) }}</td></tr>
                    <tr class="fw-semibold"><td>Energy cost</td><td class="text-end">{{ $m($r['cost']) }}</td></tr>
                    <tr><td>Left on where not needed</td><td class="text-end">{{ $n($r['waste_kwh']) }} kWh</td></tr>
                </table>
            </x-cb.card>

            <x-cb.card title="What switching off saved" icon="ri-leaf-line">
                <div class="d-flex justify-content-between"><span>Energy saved</span><strong>{{ $n($r['saved']['kwh'], 1) }} kWh</strong></div>
                <div class="d-flex justify-content-between small text-muted"><span>worth, on grid / in diesel</span><span>{{ $m($r['saved']['grid_value']) }} / {{ $n($r['saved']['litres'], 1) }} L ({{ $m($r['saved']['diesel_value']) }})</span></div>
                <div class="d-flex justify-content-between mt-2"><span>Suggestions done / left as they were</span><strong>{{ $r['actions']['done'] }} / {{ $r['actions']['skipped'] }}</strong></div>
                @if ($r['actions']['reasons']->isNotEmpty())
                    <div class="small text-muted mt-2">Why things were left on:</div>
                    <ul class="small mb-0">@foreach ($r['actions']['reasons'] as $why => $count)<li>{{ $why }} ({{ $count }})</li>@endforeach</ul>
                @endif
            </x-cb.card>

            @if ($r['grid'])
                <x-cb.card title="Grid outages" icon="ri-shut-down-line" :count="$r['grid']['outages']" :flush="true">
                    @if ($r['grid']['longest'])<div class="px-3 pt-2 small">Longest: <strong>{{ $n($r['grid']['longest']['hours'], 1) }} h</strong> from {{ $r['grid']['longest']['from']->format('D j M, g:i A') }}</div>@endif
                    <ul class="list-unstyled mb-0 small">
                        @forelse ($r['grid']['list']->take(12) as $o)
                            <li class="px-3 py-2 border-top">{{ $o['from']->format('D j M, g:i A') }} – {{ ! empty($o['open']) && $o['to']->gte(now()->subMinute()) ? 'still off' : $o['to']->format($o['to']->isSameDay($o['from']) ? 'g:i A' : 'D j M, g:i A') }} · {{ $n($o['hours'], 1) }} h</li>
                        @empty
                            <li class="px-3 py-2 text-muted">No outages recorded.</li>
                        @endforelse
                    </ul>
                </x-cb.card>
            @endif
        </div>
    </div>
</div>
</div>
</div>
<script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
<script>
(function () {
    const el = document.getElementById('er-chart');
    if (!el || !window.Chart) return;
    const days = JSON.parse(el.dataset.days);
    const ink = getComputedStyle(document.body).getPropertyValue('--cb-muted') || '#64748b';
    new Chart(el, {
        data: {
            labels: days.map(d => d.d),
            datasets: [
                { type: 'bar', label: 'Diesel burned (L, est.)', data: days.map(d => d.l), backgroundColor: 'rgba(217,119,6,.55)', borderRadius: 3, yAxisID: 'y' },
                { type: 'line', label: 'Grid off (h)', data: days.map(d => d.off), borderColor: '#b91c1c', pointRadius: 2, borderWidth: 2, yAxisID: 'y1' },
            ],
        },
        options: { maintainAspectRatio: false, animation: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { color: ink, boxWidth: 12 } } },
            scales: { y: { beginAtZero: true, title: { display: true, text: 'Litres', color: ink }, ticks: { color: ink } },
                y1: { beginAtZero: true, max: 24, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Hours', color: ink }, ticks: { color: ink } },
                x: { ticks: { color: ink, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } } } },
    });
})();
</script>
@endsection
