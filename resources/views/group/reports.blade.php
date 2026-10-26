@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $m = fn ($v, $s) => $s . number_format((float) $v, 0);
    $sym = config('hotel.currency_symbol');
    $maxRev = max(1, (float) $rows->max(fn ($r) => $r['daily']['revenue']));
    $sumDays = $rows->sum(fn ($r) => $r['daily']['days']);
@endphp
<style>
    .gr-bar { height: 6px; border-radius: 4px; background: var(--cb-border); overflow: hidden; margin-top: 4px; }
    .gr-bar span { display: block; height: 100%; background: var(--cb-teal, #0d9488); }
    .gr-table th, .gr-table td { white-space: nowrap; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Group reports" icon="ri-bar-chart-grouped-line" subtitle="Each hotel side by side for a period: the figures from its closed days (night audit), the bookings made, and where they came from.">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('group.reports', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'export' => 'csv']) }}"><i class="ri-download-2-line"></i> Download CSV</a>
            <a class="cb-hero-btn" href="{{ route('group.index') }}"><i class="ri-community-line"></i> Group overview</a>
        </x-slot:actions>
    </x-cb.hero>

    <div class="cb-card mb-3">
        <form method="GET" class="cb-toolbar">
            <label class="small text-muted mb-0">From</label><input type="date" name="from" value="{{ $from->toDateString() }}" class="cb-select" aria-label="From">
            <label class="small text-muted mb-0">to</label><input type="date" name="to" value="{{ $to->toDateString() }}" max="{{ today()->toDateString() }}" class="cb-select" aria-label="To">
            <button class="action-btn btn-open" type="submit"><i class="ri-search-line"></i> Show</button>
            @foreach (['This month' => [today()->startOfMonth(), today()], 'Last month' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()], 'This year' => [today()->startOfYear(), today()]] as $label => [$a, $b])
                <a class="small" href="{{ route('group.reports', ['from' => $a->toDateString(), 'to' => $b->toDateString()]) }}">{{ $label }}</a>
            @endforeach
        </form>
    </div>

    <x-cb.card title="How each hotel did" icon="ri-line-chart-line" :flush="true">
        <div class="table-responsive">
            <table class="cb-table gr-table">
                <thead><tr><th>Hotel</th><th class="num">Days closed</th><th class="num">Occupancy</th><th class="num">Average rate</th><th class="num">RevPAR</th><th class="num">Room revenue</th><th>Total revenue</th><th class="num">Money taken</th><th class="num">No-shows</th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        @php $d = $r['daily']; $c = $r['currency']; @endphp
                        <tr>
                            <td class="fw-semibold">{{ $r['hotel']->name }}@if ($d['missing'])<div class="small text-warning fw-normal">{{ $d['missing'] }} {{ $d['missing'] == 1 ? 'day' : 'days' }} not closed</div>@endif</td>
                            <td class="num">{{ $d['days'] }}</td>
                            <td class="num">{{ $d['occupancy'] }}%</td>
                            <td class="num">{{ $m($d['adr'], $c) }}</td>
                            <td class="num">{{ $m($d['revpar'], $c) }}</td>
                            <td class="num">{{ $m($d['room_revenue'], $c) }}</td>
                            <td style="min-width:170px"><strong>{{ $m($d['revenue'], $c) }}</strong><div class="gr-bar"><span style="width: {{ round($d['revenue'] / $maxRev * 100) }}%"></span></div></td>
                            <td class="num">{{ $m($d['collected'], $c) }}</td>
                            <td class="num">{{ $d['no_shows'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-semibold"><td>All hotels</td><td class="num">{{ $sumDays }}</td><td></td><td></td><td></td>
                        <td class="num">{{ $m($rows->sum(fn ($r) => $r['daily']['room_revenue']), $sym) }}</td><td>{{ $m($rows->sum(fn ($r) => $r['daily']['revenue']), $sym) }}</td>
                        <td class="num">{{ $m($rows->sum(fn ($r) => $r['daily']['collected']), $sym) }}</td><td class="num">{{ $rows->sum(fn ($r) => $r['daily']['no_shows']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </x-cb.card>

    <x-cb.card title="Bookings arriving in the period" icon="ri-calendar-check-line" :flush="true">
        <div class="table-responsive">
            <table class="cb-table gr-table">
                <thead><tr><th>Hotel</th><th class="num">Stays</th><th class="num">Room nights</th><th class="num">Booked value</th><th class="num">Cancelled / no-show</th><th class="num">Via group site</th><th class="num">Events</th>
                    @foreach ($sources as $s)<th class="num">{{ $s }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        @php $c = $r['currency']; @endphp
                        <tr>
                            <td class="fw-semibold">{{ $r['hotel']->name }}</td>
                            <td class="num">{{ $r['bookings'] }}</td>
                            <td class="num">{{ $r['nights'] }}</td>
                            <td class="num">{{ $m($r['booked_value'], $c) }}</td>
                            <td class="num">{{ $r['lost'] }}</td>
                            <td class="num">{{ $m($r['group_site'], $c) }}</td>
                            <td class="num">{{ $r['events'] }}@if ($r['events'])<div class="small text-muted">{{ $m($r['events_revenue'], $c) }}</div>@endif</td>
                            @foreach ($sources as $s)<td class="num">{{ isset($r['sources'][$s]) ? $m($r['sources'][$s], $c) : '—' }}</td>@endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-cb.card>
    <p class="small text-muted">Revenue, occupancy and money taken come from days closed with the night audit; close every day for complete figures. Bookings are counted by arrival date.</p>

</div>
</div>
</div>
@endsection
