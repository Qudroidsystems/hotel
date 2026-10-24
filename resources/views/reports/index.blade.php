@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $k = $report['kpi']; $p = $previous['kpi']; $m = $report['money']; $b = $report['bookings'];
    $q = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    $fmt = fn ($v) => $sym . number_format((float) $v, 2);
    // "▲ 12% vs previous period" (or points for percentages)
    $vs = function ($now, $before, bool $points = false) {
        if ($points) {
            $d = round($now - $before, 1);
            return abs($d) < 0.05 ? 'same as previous period' : ($d > 0 ? '▲ ' : '▼ ') . abs($d) . ' pts vs previous period';
        }
        if ((float) $before == 0.0) return $now > 0 ? 'nothing in previous period' : null;
        $d = round(($now - $before) / abs($before) * 100);
        return $d == 0 ? 'same as previous period' : ($d > 0 ? '▲ ' : '▼ ') . abs($d) . '% vs previous period';
    };
    $maxOcc = max(1, collect($report['daily'])->max('occupancy'));
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-line-chart-line"
               :subtitle="($from->isSameDay($to) ? $from->format('l j F Y') : $from->format('j M') . ' – ' . $to->format('j M Y')) . ' · ' . $report['days'] . ' day' . ($report['days'] === 1 ? '' : 's') . ' · ' . $report['rooms'] . ' rooms in use'">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('reports.advanced', $q) }}"><i class="ri-bar-chart-box-line"></i> Advanced reports</a>
            <a class="cb-hero-btn" href="{{ route('reports.index', $q + ['export' => 'daily']) }}"><i class="ri-file-excel-2-line"></i> Daily CSV</a>
            <a class="cb-hero-btn" href="{{ route('reports.index', $q + ['export' => 'departments']) }}"><i class="ri-file-excel-2-line"></i> Revenue CSV</a>
            <button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line"></i> Print</button>
        </x-slot:actions>
    </x-cb.hero>

    <div class="cb-card mb-3">
        <form method="GET" class="cb-toolbar">
            <input type="date" name="from" class="cb-select" value="{{ $from->toDateString() }}" aria-label="From">
            <input type="date" name="to" class="cb-select" value="{{ $to->toDateString() }}" aria-label="To">
            <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Show</button>
            @foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year'] as $key => $label)
                <a href="{{ route('reports.index', ['period' => $key]) }}" class="action-btn btn-more">{{ $label }}</a>
            @endforeach
        </form>
    </div>

    <div class="row g-3 mb-1">
        <div class="col-6 col-xl-3"><x-cb.stat label="Occupancy" :value="$k['occupancy'] . '%'" icon="ri-hotel-bed-line" accent="teal" :hint="$k['sold'] . ' of ' . number_format($k['available']) . ' room nights · ' . $vs($k['occupancy'], $p['occupancy'], true)" /></div>
        <div class="col-6 col-xl-3"><x-cb.stat label="Average daily rate (ADR)" :value="$fmt($k['adr'])" icon="ri-price-tag-3-line" accent="green" :hint="$vs($k['adr'], $p['adr'])" /></div>
        <div class="col-6 col-xl-3"><x-cb.stat label="RevPAR" :value="$fmt($k['revpar'])" icon="ri-funds-line" accent="amber" :hint="'room revenue per available room · ' . $vs($k['revpar'], $p['revpar'])" /></div>
        <div class="col-6 col-xl-3"><x-cb.stat label="Total revenue" :value="$fmt($k['revenue'])" icon="ri-money-dollar-circle-line" accent="rose" :hint="'incl. ' . $fmt($k['tax']) . ' tax · ' . $vs($k['revenue'], $p['revenue'])" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-4">
            <x-cb.card title="Earned, collected, owed" icon="ri-scales-3-line">
                <div class="small text-muted mb-2">These are different measures. Revenue is what was sold in the period; collected is money received in the period (it can be for earlier stays or deposits for later ones); owed is open bills right now.</div>
                <div class="d-flex justify-content-between py-2 border-bottom"><span>Revenue earned</span><strong>{{ $fmt($m['revenue']) }}</strong></div>
                <div class="d-flex justify-content-between py-2 border-bottom"><span>Money collected <div class="small text-muted">bills {{ $fmt($m['collected_bills']) }} (after {{ $fmt($m['refunds']) }} refunds) · bar {{ $fmt($m['collected_pos']) }}</div></span><strong>{{ $fmt($m['collected']) }}</strong></div>
                <div class="d-flex justify-content-between py-2 border-bottom"><span>Guests owe now</span><strong class="text-danger">{{ $fmt($m['owed_now']) }}</strong></div>
                <div class="d-flex justify-content-between py-2"><span>Credit owed to guests now</span><strong>{{ $fmt($m['credit_now']) }}</strong></div>
                @can('View folios')<a href="{{ route('billing.outstanding') }}" class="action-btn btn-more mt-2"><i class="ri-error-warning-line"></i> Outstanding bills</a>@endcan
            </x-cb.card>

            <x-cb.card title="Bookings arriving in the period" icon="ri-calendar-check-line">
                <div class="row g-2 text-center mb-2">
                    <div class="col-3"><div class="fs-5 fw-bold">{{ $b['arrivals'] }}</div><div class="small text-muted">booked</div></div>
                    <div class="col-3"><div class="fs-5 fw-bold">{{ $b['stayed'] }}</div><div class="small text-muted">stayed</div></div>
                    <div class="col-3"><div class="fs-5 fw-bold">{{ $b['cancelled'] }}</div><div class="small text-muted">cancelled</div></div>
                    <div class="col-3"><div class="fs-5 fw-bold">{{ $b['no_show'] }}</div><div class="small text-muted">no-show</div></div>
                </div>
                <div class="small text-muted mb-2">Average stay {{ $b['avg_nights'] }} night(s).</div>
                @forelse ($b['by_source'] as $s)
                    <div class="d-flex justify-content-between py-1 border-bottom small"><span>{{ $s['label'] }} <span class="text-muted">({{ $s['count'] }}, {{ $s['stayed'] }} stayed)</span></span><span>{{ $fmt($s['value']) }}</span></div>
                @empty
                    <div class="small text-muted">No arrivals in this period.</div>
                @endforelse
            </x-cb.card>

            <x-cb.card title="Best sellers (bar & restaurant)" icon="ri-goblet-line">
                @forelse ($report['top_items'] as $i)
                    <div class="d-flex justify-content-between py-1 border-bottom small"><span>{{ $i->name }} <span class="text-muted">× {{ rtrim(rtrim(number_format((float) $i->qty, 2), '0'), '.') }}</span></span><span>{{ $fmt($i->amount) }}</span></div>
                @empty
                    <div class="small text-muted">No sales in this period.</div>
                @endforelse
            </x-cb.card>
        </div>

        <div class="col-xl-8">
            <x-cb.card title="Revenue by department" icon="ri-pie-chart-2-line" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Department / outlet</th><th>From</th><th class="num">Lines / sales</th><th class="num">Of which tax</th><th class="num">Revenue</th><th class="num">Share</th></tr></thead>
                        <tbody>
                            @forelse ($report['departments']->concat($report['outlets']) as $row)
                                <tr>
                                    <td data-label="Department" class="fw-semibold">{{ $row['label'] }}
                                        @if (! empty($row['room']))<div class="small text-muted fw-normal">{{ $fmt($row['room']) }} charged to rooms</div>@endif</td>
                                    <td data-label="From" class="small text-muted">{{ $row['source'] }}</td>
                                    <td data-label="Lines / sales" class="num">{{ $row['count'] }}</td>
                                    <td data-label="Of which tax" class="num">{{ $fmt($row['tax']) }}</td>
                                    <td data-label="Revenue" class="num fw-semibold {{ $row['amount'] < 0 ? 'text-danger' : '' }}">{{ $row['amount'] < 0 ? '−' : '' }}{{ $fmt(abs($row['amount'])) }}</td>
                                    <td data-label="Share" class="num small text-muted">{{ $k['revenue'] > 0 && $row['amount'] > 0 ? round($row['amount'] / $k['revenue'] * 100, 1) . '%' : '' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted">No revenue in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-cb.card>

            <x-cb.card title="Day by day" icon="ri-calendar-2-line" :flush="true">
                <div class="table-responsive" style="max-height: 560px; overflow-y: auto;">
                    <table class="cb-table stack">
                        <thead><tr><th>Date</th><th class="num">Rooms sold</th><th style="min-width:140px">Occupancy</th><th class="num">Room revenue</th><th class="num">Bar &amp; rest.</th><th class="num">Collected</th></tr></thead>
                        <tbody>
                            @foreach ($report['daily'] as $d)
                                <tr>
                                    <td data-label="Date" class="text-nowrap">{{ $d['date']->format('D j M') }}</td>
                                    <td data-label="Rooms sold" class="num">{{ $d['sold'] }}</td>
                                    <td data-label="Occupancy"><div class="d-flex align-items-center gap-2"><div class="progress-track flex-grow-1"><div class="progress-fill" style="width: {{ min(100, $d['occupancy']) }}%"></div></div><span class="small" style="min-width:44px">{{ $d['occupancy'] }}%</span></div></td>
                                    <td data-label="Room revenue" class="num">{{ $fmt($d['room']) }}</td>
                                    <td data-label="Bar & rest." class="num">{{ $fmt($d['pos']) }}</td>
                                    <td data-label="Collected" class="num">{{ $fmt($d['collected']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-cb.card>
            <div class="small text-muted">Rooms sold come from room nights posted to guest bills (posted each night for guests in house), so tonight appears after the night audit. Availability uses today's {{ $report['rooms'] }} active rooms. More detail: @can('View finance reports')<a href="{{ route('billing.payments', $q) }}">collections</a>, @endcan @can('View POS reports')<a href="{{ route('pos.orders', $q) }}">bar sales</a>, @endcan @can('View stock reports')<a href="{{ route('inventory.reports') }}">stock reports</a>, @endcan @can('View attendance')<a href="{{ route('attendance.timesheet') }}">timesheet</a>.@endcan</div>
        </div>
    </div>

</div>
</div>
</div>
@endsection
