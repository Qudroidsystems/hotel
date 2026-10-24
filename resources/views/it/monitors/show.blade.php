@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php use App\Models\It\ItMonitor; $S = ItMonitor::STATUSES; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$monitor->name" :icon="$monitor->type_icon" :back="route('it.monitors.index')" back-label="Monitors"
               :subtitle="$monitor->type_label . ' · ' . $monitor->target_label . ' · checked every ' . $monitor->interval_minutes . ' min'">
        <x-slot:pills>
            <span class="cb-meta-pill"><span class="it-dot" style="--dot: {{ $monitor->status_color }}"></span> {{ $monitor->status_label }}{{ $monitor->status_since ? ' for ' . $monitor->status_since->diffForHumans(null, true) : '' }}</span>
            @if ($monitor->asset)<span class="cb-meta-pill"><i class="{{ $monitor->asset->type_icon }}"></i> {{ $monitor->asset->name }}{{ $monitor->asset->location ? ' · ' . $monitor->asset->location : '' }}</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @can('Manage IT monitoring')
                <form method="POST" action="{{ route('it.monitors.check', $monitor) }}" class="d-inline" data-once>@csrf<button class="cb-hero-btn"><i class="ri-refresh-line"></i> Check now</button></form>
            @endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($monitor->last_message)
        <div class="cb-banner {{ ['up' => 'success', 'degraded' => 'warning', 'down' => 'danger'][$monitor->status] ?? 'info' }}"><i class="ri-information-line"></i><div><strong>Last check {{ $monitor->last_checked_at?->diffForHumans() }}:</strong> {{ $monitor->last_message }}</div></div>
    @endif

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Availability" :value="$stats['uptime'] !== null ? rtrim(rtrim(number_format($stats['uptime'], 2), '0'), '.') . '%' : '—'" icon="ri-pulse-line" accent="teal" :hint="'last ' . $days . ' day' . ($days === 1 ? '' : 's')" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Failed checks" :value="$stats['failed']" icon="ri-close-circle-line" accent="rose" :hint="'of ' . number_format($stats['checks'])" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Average response" :value="$stats['avg_ms'] !== null ? number_format($stats['avg_ms']) . ' ms' : '—'" icon="ri-timer-line" accent="green" :hint="$stats['max_ms'] ? 'slowest ' . number_format($stats['max_ms']) . ' ms' : null" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Alerts" :value="$alerts->where('opened_at', '>=', $since)->count()" icon="ri-alarm-warning-line" accent="amber" :hint="'raised after ' . $monitor->alert_after . ' failed check' . ($monitor->alert_after === 1 ? '' : 's') . ' in a row'" /></div>
    </div>

    <x-cb.card title="History" icon="ri-history-line">
        <x-slot:tools>
            @foreach ([1 => '24 h', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $label)
                <a href="{{ route('it.monitors.show', [$monitor, 'days' => $d]) }}" class="action-btn {{ $days === $d ? 'btn-open' : 'btn-more' }}">{{ $label }}</a>
            @endforeach
        </x-slot:tools>
        <div class="it-strip">
            @for ($i = 0; $i < $slots; $i++)
                @php $s = $strip[$i] ?? null; @endphp
                <span style="background: {{ $s ? ($S[$s['status']][2] ?? '#e2e8f0') : '#e2e8f0' }}" title="{{ $since->copy()->addMinutes($i * $size)->format('D j M, g:i A') }}{{ $s ? ' — ' . ($S[$s['status']][0] ?? '') . ': ' . $s['message'] : ' — no checks' }}"></span>
            @endfor
        </div>
        <div class="d-flex justify-content-between small text-muted mt-1"><span>{{ $since->format('D j M, g:i A') }}</span><span>now</span></div>
        <div class="d-flex gap-3 small mt-2 flex-wrap">@foreach (['up', 'degraded', 'down', 'maintenance'] as $k)<span><span class="it-dot" style="--dot: {{ $S[$k][2] }}"></span> {{ $S[$k][0] }}</span>@endforeach<span><span class="it-dot" style="--dot:#e2e8f0"></span> No checks</span></div>
    </x-cb.card>

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="Recent problems" icon="ri-error-warning-line" :count="$problems->count()" :flush="true">
                <div class="table-responsive" style="max-height: 460px; overflow-y: auto;">
                    <table class="cb-table stack">
                        <thead><tr><th>When</th><th>Status</th><th>What the check found</th></tr></thead>
                        <tbody>
                            @forelse ($problems as $c)
                                <tr><td data-label="When" class="small text-nowrap">{{ $c->checked_at->format('D j M, g:i A') }}</td>
                                    <td data-label="Status"><span class="status-pill {{ $S[$c->status][1] }}">{{ $S[$c->status][0] }}</span></td>
                                    <td data-label="Found" class="small">{{ $c->message }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">No problems in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-cb.card>
        </div>
        <div class="col-xl-5">
            <x-cb.card title="Alerts" icon="ri-alarm-warning-line" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Alert</th><th>Lasted</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse ($alerts as $a)
                                <tr><td data-label="Alert"><span class="rs-ref">{{ $a->number }}</span><div class="small text-muted">{{ $a->opened_at->format('D j M, g:i A') }}</div></td>
                                    <td data-label="Lasted" class="small">{{ $a->duration() }}</td>
                                    <td data-label="Status"><span class="status-pill {{ $a->status_pill }}">{{ $a->status_label }}</span>@if ($a->auto_resolved)<div class="small text-muted">recovered by itself</div>@endif</td></tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">No alerts raised.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
