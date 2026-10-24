@extends('layouts.staff')

@section('content')
@php
    $me = auth()->user();
    $hour = now()->hour;
    $hello = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $sym = config('hotel.currency_symbol');
    $short = fn ($v) => $sym . ((float) $v >= 1000000 ? round($v / 1000000, 1) . 'm' : ((float) $v >= 10000 ? round($v / 1000) . 'k' : number_format((float) $v)));
    $tiles = array_filter([
        $me->canAny(['Report maintenance issues', 'Manage maintenance']) ? [route('staff.report') . '#fault', 'ri-tools-line', 'Report a fault', '#f59e0b'] : null,
        [route('it.tickets.index') . '#new', 'ri-customer-service-2-line', 'IT help', '#6366f1'],
        $me->can('Record expenses') ? [route('expenses.index') . '#new', 'ri-wallet-3-line', 'Record expense', '#0d9488'] : null,
        $me->canAny(['Report incidents', 'Manage incidents']) ? [route('staff.report') . '#incident', 'ri-alarm-warning-line', 'Report incident', '#dc2626'] : null,
        $me->canAny(['View reservations', 'Check in guests']) ? [route('staff.front-desk'), 'ri-door-open-line', 'Front desk', '#0284c7'] : null,
        $me->can('Check in guests') ? [route('reservations.scan'), 'ri-qr-scan-2-line', 'Scan guest QR', '#7c3aed'] : null,
        $me->canAny(['View visitors', 'Manage visitors']) ? [route('visitors.check'), 'ri-shield-user-line', 'Check visitor pass', '#334155'] : null,
        $me->can('View stock') ? [route('staff.stock'), 'ri-archive-line', 'Stock lookup', '#16a34a'] : null,
        $me->canAny(['View IT control room', 'Manage IT monitoring']) ? [route('it.index'), 'ri-dashboard-3-line', 'IT status', '#0f172a'] : null,
    ]);
@endphp

<div class="mb-3">
    <div style="font-size:21px;font-weight:800">{{ $hello }}, {{ \Illuminate\Support\Str::before($me->name, ' ') }}</div>
    <div class="text-muted small">{{ now()->format('l j F') }}
        @if ($attendance)
            · @if ($attendance->is_in)<span class="text-success fw-semibold"><i class="ri-checkbox-blank-circle-fill" style="font-size:9px"></i> Clocked in {{ $attendance->first_in?->format('g:i A') }}</span>
              @else<span>Clocked out {{ $attendance->last_out?->format('g:i A') }}</span>@endif
            @if ($attendance->late_minutes) · <span class="text-warning">{{ $attendance->late_minutes }} min late</span>@endif
        @endif
    </div>
</div>

@if ($work->isNotEmpty() || $approvals->isNotEmpty())
    <a href="{{ $approvals->isNotEmpty() && $work->isEmpty() ? route('staff.approvals') : route('staff.work') }}" class="sa-card d-flex align-items-center gap-3 text-decoration-none" style="background:linear-gradient(135deg,#0f766e,#0d9488);border:0;color:#fff">
        <i class="ri-notification-3-line" style="font-size:30px"></i>
        <div class="flex-grow-1">
            <div style="font-weight:800;font-size:17px">{{ $work->count() ? $work->count() . ' job' . ($work->count() === 1 ? '' : 's') . ' for you' : '' }}{{ $work->count() && $approvals->count() ? ' · ' : '' }}{{ $approvals->count() ? $approvals->count() . ' to approve' : '' }}</div>
            <div style="opacity:.85;font-size:13px">{{ $work->where('urgent', true)->count() ? $work->where('urgent', true)->count() . ' urgent · ' : '' }}Tap to see them</div>
        </div>
        <i class="ri-arrow-right-s-line" style="font-size:26px"></i>
    </a>
@else
    <div class="sa-card d-flex align-items-center gap-3"><i class="ri-checkbox-circle-line text-success" style="font-size:30px"></i><div><div class="fw-bold">Nothing waiting for you</div><div class="small text-muted">New jobs and approvals appear here.</div></div></div>
@endif

<div class="sa-sec">Quick actions</div>
<div class="sa-grid">
    @foreach ($tiles as [$url, $icon, $label, $color])
        <a href="{{ $url }}" class="sa-tile" style="--tile: {{ $color }}"><i class="{{ $icon }}"></i>{{ $label }}</a>
    @endforeach
</div>

@if ($today)
    <div class="sa-sec">Hotel today</div>
    <div class="sa-kpis">
        <div class="sa-kpi"><div class="v">{{ $today['occupancy'] }}%</div><div class="l">Occupied · {{ $today['occupied'] }} of {{ $today['rooms'] }} rooms</div></div>
        <div class="sa-kpi"><div class="v">{{ $today['arrived'] }}/{{ $today['arrived'] + $today['arrivals'] }}</div><div class="l">Arrivals checked in · {{ $today['departures'] }} still to leave</div></div>
        @if ($isManager)
            <div class="sa-kpi"><div class="v">{{ $short($today['collected']) }}</div><div class="l">Money in today (bills + bar)</div></div>
            <div class="sa-kpi"><div class="v">{{ $short($today['pos_sales']) }}</div><div class="l">Bar &amp; restaurant sales today</div></div>
        @endif
        <div class="sa-kpi"><div class="v {{ $today['dirty'] ? 'text-warning' : '' }}">{{ $today['dirty'] }}</div><div class="l">Rooms to clean / being cleaned</div></div>
        <div class="sa-kpi"><div class="v {{ $today['maintenance'] ? 'text-warning' : '' }}">{{ $today['maintenance'] }}</div><div class="l">Open repair jobs</div></div>
        @if ($isManager && $today['it_alerts'])
            <a href="{{ route('it.alerts.index') }}" class="sa-kpi text-decoration-none" style="grid-column: span 2; border-color:#fecaca"><div class="v text-danger">{{ $today['it_alerts'] }} IT alert{{ $today['it_alerts'] === 1 ? '' : 's' }}</div><div class="l">{{ $today['it_down'] }} high or critical not yet acknowledged</div></a>
        @endif
    </div>
@endif

@if ($myTickets->isNotEmpty())
    <div class="sa-sec">My IT requests</div>
    @foreach ($myTickets as $t)
        <a href="{{ route('it.tickets.show', $t) }}" class="sa-item d-block text-decoration-none"><div class="d-flex justify-content-between gap-2"><span class="t" style="font-size:15px">{{ $t->title }}</span><span class="status-pill {{ $t->status_pill }}">{{ $t->status_label }}</span></div>
            <div class="m">{{ $t->number }} · {{ $t->assignee?->name ? 'with ' . $t->assignee->name : 'waiting for IT' }}</div></a>
    @endforeach
@endif

<div class="text-center small text-muted mt-4 mb-2" id="installHint" style="display:none">
    <button type="button" class="sa-btn go" id="installBtn" style="width:auto"><i class="ri-download-2-line"></i> Add Staff App to home screen</button>
</div>
@endsection

@push('scripts')
<script>
    // Offer "Add to home screen" where the browser supports it (Android Chrome / Edge).
    let deferred;
    window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; document.getElementById('installHint').style.display = ''; });
    document.getElementById('installBtn')?.addEventListener('click', async () => { if (!deferred) return; deferred.prompt(); await deferred.userChoice; deferred = null; document.getElementById('installHint').style.display = 'none'; });
</script>
@endpush
