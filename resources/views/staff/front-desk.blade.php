@extends('layouts.staff')

@section('content')
<div class="sa-kpis mb-2">
    <div class="sa-kpi"><div class="v">{{ $today['arrivals'] }}</div><div class="l">Still to arrive · {{ $today['arrived'] }} checked in today</div></div>
    <div class="sa-kpi"><div class="v">{{ $today['departures'] }}</div><div class="l">Still to check out</div></div>
    <div class="sa-kpi"><div class="v">{{ $today['in_house'] }}</div><div class="l">Bookings in house · {{ $today['occupancy'] }}% occupied</div></div>
    <div class="sa-kpi"><div class="v">{{ $today['room_status']['available'] ?? 0 }}</div><div class="l">Rooms ready to sell · {{ $today['dirty'] }} to clean</div></div>
</div>

<div class="sa-btns mb-2">
    @can('Check in guests')<a href="{{ route('reservations.scan') }}" class="sa-btn go"><i class="ri-qr-scan-2-line"></i> Scan guest QR</a>@endcan
    <a href="{{ route('reservations.index') }}" class="sa-btn" style="flex:0 0 auto"><i class="ri-search-line"></i> Find booking</a>
</div>

<div class="sa-sec">Arriving today · {{ $desk['arrivals']->count() }}</div>
@forelse ($desk['arrivals'] as $r)
    <a href="{{ route('reservations.show', $r) }}" class="sa-item d-block text-decoration-none">
        <div class="d-flex justify-content-between gap-2"><span class="t">{{ $r->guest?->full_name }}@if ($r->guest?->is_vip) <span class="status-pill st-warning">VIP</span>@endif</span><span class="rs-ref">{{ $r->reference }}</span></div>
        <div class="m">{{ $r->rooms->map(fn ($l) => $l->room?->number)->filter()->implode(', ') ?: 'Room not assigned yet' }} · {{ $r->check_in_date->diffInDays($r->check_out_date) }} night(s){{ $r->guest?->phone ? ' · ' . $r->guest->phone : '' }}</div>
    </a>
@empty
    <div class="small text-muted px-1">Everyone due today has arrived.</div>
@endforelse

<div class="sa-sec">Leaving today · {{ $desk['departures']->count() }}</div>
@forelse ($desk['departures'] as $r)
    <a href="{{ route('reservations.show', $r) }}" class="sa-item d-block text-decoration-none">
        <div class="d-flex justify-content-between gap-2"><span class="t">{{ $r->guest?->full_name }}</span><span class="rs-ref">{{ $r->reference }}</span></div>
        <div class="m">Room {{ $r->rooms->map(fn ($l) => $l->room?->number)->filter()->implode(', ') }}</div>
    </a>
@empty
    <div class="small text-muted px-1">No more check-outs due today.</div>
@endforelse
@endsection
