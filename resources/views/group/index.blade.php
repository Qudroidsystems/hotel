@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $m = fn ($v, $s = null) => ($s ?? $sym) . number_format((float) $v, 0);
    $occ = $totals['rooms'] ? round($totals['occupied'] / $totals['rooms'] * 100) : 0;
@endphp
<style>
    .gp-bar { height: 6px; border-radius: 4px; background: var(--cb-border); overflow: hidden; }
    .gp-bar span { display: block; height: 100%; background: var(--cb-teal, #0d9488); }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Group overview" icon="ri-community-line" subtitle="Every hotel at a glance: who is in tonight, who is coming and going, and money taken. Switch hotel from the top bar to work in one.">
        <x-slot:actions><a class="cb-hero-btn" href="{{ route('group.reports') }}"><i class="ri-bar-chart-grouped-line"></i> Group reports</a><a class="cb-hero-btn" href="{{ route('group.commission') }}"><i class="ri-percent-line"></i> Commission</a></x-slot:actions>
    </x-cb.hero>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Occupied tonight, all hotels" :value="$occ . '%'" icon="ri-hotel-bed-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Arriving / leaving today" :value="$totals['arrivals'] . ' / ' . $totals['departures']" icon="ri-luggage-cart-line" accent="sky" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Taken today" :value="$m($totals['collected'])" icon="ri-money-dollar-circle-line" accent="green" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Revenue this month (closed days)" :value="$m($totals['month_revenue'])" icon="ri-line-chart-line" accent="violet" /></div>
    </div>

    <div class="cb-card">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Hotel</th><th>Occupied tonight</th><th class="num">In house</th><th class="num">Arriving</th><th class="num">Leaving</th><th class="num">Next 7 days</th><th class="num">Taken today</th><th class="num">This month</th><th class="num">To clean · faults</th><th></th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        @php $t = $r['today']; @endphp
                        <tr>
                            <td data-label="Hotel"><span class="fw-semibold">{{ $r['hotel']->name }}</span><div class="small text-muted">{{ $r['hotel']->location ?: ' ' }}@if ($r['last_audit']) · last day closed {{ \Illuminate\Support\Carbon::parse($r['last_audit'])->format('j M') }}@endif</div></td>
                            <td data-label="Occupied tonight" style="min-width:150px"><div class="d-flex justify-content-between small"><strong>{{ $t['occupancy'] }}%</strong><span class="text-muted">{{ $t['occupied'] }} of {{ $t['rooms'] }}</span></div><div class="gp-bar"><span style="width: {{ min(100, $t['occupancy']) }}%"></span></div></td>
                            <td data-label="In house" class="num">{{ $t['in_house'] }}</td>
                            <td data-label="Arriving" class="num">{{ $t['arrived'] }}/{{ $t['arrivals'] }}</td>
                            <td data-label="Leaving" class="num">{{ $t['departures'] }}</td>
                            <td data-label="Next 7 days" class="num">{{ $r['upcoming'] }}</td>
                            <td data-label="Taken today" class="num">{{ $m($t['collected'], $r['currency']) }}</td>
                            <td data-label="This month" class="num">{{ $m($r['month_revenue'], $r['currency']) }}@if ($r['month_occupancy'] !== null)<div class="small text-muted">{{ $r['month_occupancy'] }}% occupied</div>@endif</td>
                            <td data-label="To clean · faults" class="num">{{ $t['dirty'] }} · {{ $t['maintenance'] }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('hotels.switch') }}">@csrf<input type="hidden" name="hotel_id" value="{{ $r['hotel']->id }}"><button class="action-btn btn-open">Open</button></form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-2">“This month” adds up the days each hotel has closed with the night audit.</p>

</div>
</div>
</div>
@endsection
