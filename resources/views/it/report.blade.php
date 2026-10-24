@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php
    $a = $report['alerts']; $t = $report['tickets'];
    $mins = fn ($m) => $m === null ? '—' : ($m >= 120 ? round($m / 60, 1) . ' h' : round($m) . ' min');
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 2), '0'), '.') . '%';
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="IT Service Report" icon="ri-file-chart-line" :subtitle="$month->format('F Y') . ($month->isSameMonth(today()) ? ' (so far)' : '') . ' · availability, alerts and service desk performance'">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('it.report', ['month' => $month->format('Y-m'), 'export' => 'csv']) }}"><i class="ri-file-excel-2-line"></i> CSV</a>
            <button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line"></i> Print / PDF</button>
        </x-slot:actions>
    </x-cb.hero>

    @include('it.partials.nav')

    <div class="cb-card mb-3">
        <form method="GET" class="cb-toolbar">
            <input type="month" name="month" class="cb-select" value="{{ $month->format('Y-m') }}" max="{{ today()->format('Y-m') }}" aria-label="Month">
            <button class="action-btn btn-open"><i class="ri-filter-3-line"></i> Show</button>
            <a href="{{ route('it.report', ['month' => today()->subMonthNoOverflow()->format('Y-m')]) }}" class="action-btn btn-more">Last month</a>
        </form>
    </div>

    <div class="row g-3 mb-1">
        <div class="col-6 col-xl-3"><x-cb.stat label="Average availability" :value="$pct($report['overall'])" icon="ri-pulse-line" accent="teal" hint="across all monitors, measured" /></div>
        <div class="col-6 col-xl-3"><x-cb.stat label="Alerts" :value="$a['total']" icon="ri-alarm-warning-line" accent="rose" :hint="$a['critical'] . ' high/critical · cleared in ' . $mins($a['mttr']) . ' on average'" /></div>
        <div class="col-6 col-xl-3"><x-cb.stat label="Tickets raised" :value="$t['raised']" icon="ri-customer-service-2-line" accent="amber" :hint="$t['resolved'] . ' resolved · ' . $t['open'] . ' still open · ' . $t['escalated'] . ' escalated'" /></div>
        <div class="col-6 col-xl-3"><x-cb.stat label="Targets met" :value="$pct($t['response_met']) . ' / ' . $pct($t['resolve_met'])" icon="ri-timer-line" accent="green" hint="response / fix" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="Availability by monitor" icon="ri-pulse-line" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Monitor</th><th style="min-width:160px">Uptime</th><th class="num">Alerts</th><th class="num">Downtime</th></tr></thead>
                        <tbody>
                            @forelse ($report['availability'] as $r)
                                <tr>
                                    <td data-label="Monitor"><span class="fw-semibold">{{ $r->monitor->name }}</span><div class="small text-muted">{{ $r->monitor->type_label }}</div></td>
                                    <td data-label="Uptime">@if ($r->uptime !== null)<div class="d-flex align-items-center gap-2"><div class="progress-track flex-grow-1"><div class="progress-fill" style="width: {{ $r->uptime }}%; {{ $r->uptime < 99 ? 'background:#f59e0b' : '' }}"></div></div><span class="small fw-semibold">{{ $pct($r->uptime) }}</span></div>@else<span class="text-muted small">No checks</span>@endif</td>
                                    <td data-label="Alerts" class="num">{{ $r->alerts }}</td>
                                    <td data-label="Downtime" class="num">{{ $r->downtime ? $mins($r->downtime) : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted">No monitors.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="cb-card-body small text-muted">Uptime is the share of checks that found the service working (planned maintenance counts as available). Downtime is how long alerts were open.</div>
            </x-cb.card>

            <x-cb.card title="Service desk by priority" icon="ri-flag-line" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Priority</th><th class="num">Tickets</th><th class="num">Target (respond / fix)</th><th class="num">Avg response</th><th class="num">Avg fix</th><th class="num">Missed a target</th></tr></thead>
                        <tbody>
                            @foreach ($report['by_priority'] as $key => $p)
                                <tr>
                                    <td data-label="Priority"><span class="status-pill {{ $p->pill }}">{{ $p->label }}</span></td>
                                    <td data-label="Tickets" class="num">{{ $p->count }}</td>
                                    <td data-label="Target" class="num small text-muted">{{ $mins(config("hotel.it.response_minutes.$key")) }} / {{ config("hotel.it.resolve_hours.$key") }} h</td>
                                    <td data-label="Avg response" class="num">{{ $mins($p->response) }}</td>
                                    <td data-label="Avg fix" class="num">{{ $p->resolve !== null ? round($p->resolve, 1) . ' h' : '—' }}</td>
                                    <td data-label="Missed" class="num {{ $p->late ? 'text-danger fw-semibold' : '' }}">{{ $p->late }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="cb-card-body small text-muted">Average first response {{ $mins($t['response_mins']) }}; average time to fix {{ $t['resolve_hours'] !== null ? $t['resolve_hours'] . ' h' : '—' }}. Targets are set under Hotel Settings → IT Service Desk.</div>
            </x-cb.card>
        </div>

        <div class="col-xl-5">
            <x-cb.card title="What people needed help with" icon="ri-pie-chart-2-line">
                @forelse ($report['by_category'] as $c)
                    <div class="d-flex align-items-center gap-2 py-1"><i class="{{ $c->icon }} text-muted"></i><span class="flex-grow-1">{{ $c->label }}</span>
                        <div class="progress-track" style="width:110px"><div class="progress-fill" style="width: {{ $t['raised'] ? $c->count / $t['raised'] * 100 : 0 }}%"></div></div><strong style="width:28px" class="text-end">{{ $c->count }}</strong></div>
                @empty
                    <div class="small text-muted">No tickets this month.</div>
                @endforelse
            </x-cb.card>
            <x-cb.card title="Repeat problems" icon="ri-repeat-line" :count="$report['repeats']->count()">
                @forelse ($report['repeats'] as $r)
                    <div class="py-2 border-bottom"><div class="fw-semibold">{{ $r->label }} — {{ $r->location }} <span class="status-pill st-warning">{{ $r->count }}×</span></div>
                        <div class="small {{ $r->root ? '' : 'text-danger' }}">{{ $r->root ? 'Root cause: ' . $r->root : 'No root cause recorded yet.' }}</div></div>
                @empty
                    <div class="small text-muted">No problem came back three or more times in the same place.</div>
                @endforelse
            </x-cb.card>
            <x-cb.card title="By department & technician" icon="ri-team-line">
                <div class="row">
                    <div class="col-6"><div class="small text-muted mb-1">Raised by department</div>
                        @forelse ($report['by_department'] as $d => $n)<div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $d }}</span><strong>{{ $n }}</strong></div>@empty<div class="small text-muted">—</div>@endforelse</div>
                    <div class="col-6"><div class="small text-muted mb-1">Resolved by</div>
                        @forelse ($report['by_technician'] as $d => $n)<div class="d-flex justify-content-between small py-1 border-bottom"><span>{{ $d }}</span><strong>{{ $n }}</strong></div>@empty<div class="small text-muted">—</div>@endforelse</div>
                </div>
            </x-cb.card>
            <x-cb.card title="Alerts" icon="ri-alarm-warning-line">
                <div class="d-flex justify-content-between py-1 border-bottom small"><span>Raised</span><strong>{{ $a['total'] }}</strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom small"><span>Recovered by themselves</span><strong>{{ $a['auto'] }}</strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom small"><span>Average time to acknowledge</span><strong>{{ $mins($a['ack_mins']) }}</strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom small"><span>Average time to clear</span><strong>{{ $mins($a['mttr']) }}</strong></div>
                <div class="d-flex justify-content-between py-1 small"><span>Still open</span><strong>{{ $a['open'] }}</strong></div>
            </x-cb.card>
        </div>
    </div>
    <p class="small text-muted">All figures are measured by this system's own checks and ticket records — they cover only what is set up under Monitors.</p>

</div>
</div>
</div>
@endsection
