@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php
    use App\Models\It\ItMonitor;
    $tileColor = fn ($c) => $c->down ? '#dc2626' : ($c->degraded ? '#f59e0b' : ($c->other ? '#94a3b8' : ($c->total ? '#16a34a' : '#94a3b8')));
    $tileText = fn ($c) => ! $c->total ? 'Nothing monitored' : ($c->down ? $c->down . ' down' : ($c->degraded ? $c->degraded . ' degraded' : ($c->other ? $c->other . ' not checked' : 'All up')));
    $canMonitor = auth()->user()->can('Manage IT monitoring');
    $canTickets = auth()->user()->can('Manage IT tickets');
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="IT Control Room" icon="ri-dashboard-3-line"
               :subtitle="'Network, systems, backups and the IT service desk · ' . ($lastRun ? 'checks last ran ' . $lastRun->diffForHumans() : 'checks have not run yet')">
        <x-slot:actions>
            @if ($canMonitor)
                <form method="POST" action="{{ route('it.check-now') }}" class="d-inline" data-once>@csrf<button class="cb-hero-btn" type="submit"><i class="ri-refresh-line"></i> Check everything now</button></form>
            @endif
            <a class="cb-hero-btn" href="{{ route('it.tickets.index') }}#new"><i class="ri-add-line"></i> Raise a ticket</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @include('it.partials.nav')

    @if ($stale && $monitors->where('is_active', true)->isNotEmpty())
        <div class="cb-banner danger"><i class="ri-error-warning-line"></i><div><strong>Automatic checks are not running.</strong>
            {{ $lastRun ? 'They last ran ' . $lastRun->diffForHumans() . '.' : 'They have never run.' }} The Laravel scheduler must run every minute on the server
            (<code>php artisan schedule:work</code>, or a cron entry for <code>php artisan schedule:run</code>). Until then, statuses below are out of date.</div></div>
    @endif
    @foreach ($windows as $w)
        <div class="cb-banner info"><i class="ri-tools-line"></i><div><strong>Maintenance in progress: {{ $w->title }}</strong> — until {{ $w->ends_at->format('g:i A' . ($w->ends_at->isToday() ? '' : ', D j M')) }}.
            {{ $w->affects ? 'Affects: ' . $w->affects . '.' : '' }} Alerts for {{ count($w->monitor_ids ?? []) }} monitor(s) are on hold.</div></div>
    @endforeach

    <div class="it-tiles">
        @foreach ($categories as $key => $c)
            <a href="{{ route('it.monitors.index') }}#{{ $key }}" class="it-tile text-decoration-none" style="--tile: {{ $tileColor($c) }}">
                <div class="t-head"><i class="{{ $c->icon }}"></i> {{ $c->label }}</div>
                <div class="t-main">{{ $tileText($c) }}</div>
                <div class="t-sub">{{ $c->up }} up · {{ $c->degraded }} degraded · {{ $c->down }} down{{ $c->other ? ' · ' . $c->other . ' other' : '' }}</div>
            </a>
        @endforeach
        <a href="{{ route('it.alerts.index') }}" class="it-tile text-decoration-none" style="--tile: {{ $stats['unacked'] ? '#dc2626' : ($stats['alerts'] ? '#f59e0b' : '#16a34a') }}">
            <div class="t-head"><i class="ri-alarm-warning-line"></i> Alerts &amp; tickets</div>
            <div class="t-main">{{ $stats['alerts'] ? $stats['alerts'] . ' alert' . ($stats['alerts'] === 1 ? '' : 's') : 'No alerts' }}</div>
            <div class="t-sub">{{ $stats['unacked'] }} not acknowledged · {{ $stats['tickets'] }} open tickets{{ $stats['overdue'] ? ' · ' . $stats['overdue'] . ' overdue' : '' }}</div>
        </a>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="cb-card">
                <div class="cb-card-header"><h5><i class="ri-pulse-line"></i>Live status <span class="cb-count">{{ $stats['up'] }}/{{ $stats['active'] }} up</span></h5>
                    <div class="small text-muted">30-day availability {{ $stats['uptime'] !== null ? number_format($stats['uptime'], 2) . '%' : '—' }}</div></div>
                @if ($monitors->isEmpty())
                    <div class="empty-state py-5"><i class="ri-pulse-line"></i><h6>Nothing is monitored yet</h6>
                        <p class="small text-muted">Add your internet link, routers, Wi-Fi access points, website, database and backup folder.</p>
                        @if ($canMonitor)<a href="{{ route('it.monitors.index') }}" class="action-btn btn-go"><i class="ri-add-line"></i> Add monitors</a>@endif</div>
                @else
                    <div class="it-mon d-none d-md-grid small text-muted fw-semibold" style="padding-top:6px;padding-bottom:6px"><span>What</span><span>Last 30 checks</span><span class="num">Response</span><span class="num">24 h</span></div>
                    @foreach ($monitors->groupBy('category') as $cat => $group)
                        <div class="it-sec" id="{{ $cat }}"><i class="{{ ItMonitor::CATEGORIES[$cat][1] ?? '' }}"></i> {{ ItMonitor::CATEGORIES[$cat][0] ?? $cat }}</div>
                        @foreach ($group as $m)
                            <div class="it-mon" style="{{ $m->is_active ? '' : 'opacity:.55' }}">
                                <div class="d-flex gap-2 align-items-center" style="min-width:0">
                                    <span class="it-dot {{ $m->display_status === 'down' ? 'pulse' : '' }}" style="--dot: {{ $m->status_color }}" title="{{ $m->status_label }}"></span>
                                    <div style="min-width:0">
                                        <a href="{{ route('it.monitors.show', $m) }}" class="nm text-reset">{{ $m->name }}</a>
                                        <span class="status-pill {{ $m->status_pill }} ms-1">{{ $m->status_label }}</span>
                                        <div class="tg" title="{{ $m->last_message }}"><i class="{{ $m->type_icon }}"></i> {{ $m->target_label }} · {{ $m->last_message ?: 'not checked yet' }}</div>
                                    </div>
                                </div>
                                <div class="hide-sm">@include('it.partials.bars', ['checks' => $recent[$m->id] ?? collect(), 'n' => 30])</div>
                                <div class="num hide-sm">{{ $m->last_latency_ms !== null ? number_format($m->last_latency_ms) . ' ms' : '—' }}</div>
                                <div class="num"><strong>{{ isset($uptime24[$m->id]) ? rtrim(rtrim(number_format($uptime24[$m->id], 2), '0'), '.') . '%' : '—' }}</strong></div>
                            </div>
                        @endforeach
                    @endforeach
                @endif
            </div>
        </div>

        <div class="col-xl-4">
            <x-cb.card title="Alert queue" icon="ri-alarm-warning-line" :count="$alerts->count()">
                @forelse ($alerts->take(6) as $a)
                    <div class="it-alert">
                        <span class="it-dot {{ $a->status === 'open' ? 'pulse' : '' }} mt-1" style="--dot: {{ $a->status === 'open' ? '#dc2626' : '#f59e0b' }}"></span>
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="fw-semibold">{{ $a->title }}</div>
                            <div class="small text-muted">{{ $a->number }} · <span class="status-pill {{ $a->severity_pill }}">{{ $a->severity_label }}</span> · {{ $a->duration() }}
                                @if ($a->acknowledgedBy) · {{ $a->acknowledgedBy->name }} is on it @endif</div>
                            @if ($a->message)<div class="small text-truncate" title="{{ $a->message }}">{{ $a->message }}</div>@endif
                        </div>
                        @if (($canMonitor || $canTickets) && $a->status === 'open')
                            <form method="POST" action="{{ route('it.alerts.acknowledge', $a) }}" data-once>@csrf<button class="action-btn btn-open" title="I'm on it"><i class="ri-hand-heart-line"></i></button></form>
                        @endif
                    </div>
                @empty
                    <div class="text-center py-3 text-muted"><i class="ri-checkbox-circle-line fs-3 text-success d-block"></i>No open alerts.</div>
                @endforelse
                @if ($alerts->count() > 6)<a href="{{ route('it.alerts.index') }}" class="small">All {{ $alerts->count() }} alerts →</a>@endif
            </x-cb.card>

            <x-cb.card title="Service desk queue" icon="ri-customer-service-2-line" :count="$stats['tickets']">
                <div class="d-flex gap-3 small mb-2 flex-wrap">
                    <span class="{{ $stats['overdue'] ? 'text-danger fw-semibold' : 'text-muted' }}">{{ $stats['overdue'] }} overdue</span>
                    <span class="{{ $stats['unassigned'] ? 'text-warning fw-semibold' : 'text-muted' }}">{{ $stats['unassigned'] }} unassigned</span>
                </div>
                @forelse ($queue as $t)
                    <a href="{{ route('it.tickets.show', $t) }}" class="d-flex justify-content-between gap-2 py-2 border-bottom text-reset text-decoration-none">
                        <span style="min-width:0"><span class="fw-semibold d-block text-truncate">{{ $t->title }}</span>
                            <span class="small text-muted">{{ $t->number }} · {{ $t->requester?->name }} · {{ $t->created_at->diffForHumans() }}</span></span>
                        <span class="text-end"><span class="status-pill {{ $t->priority_pill }}">{{ $t->priority_label }}</span>
                            @if ($t->responseLate() || $t->resolveLate())<span class="d-block small text-danger">overdue</span>@else<span class="d-block small text-muted">{{ $t->assignee?->name ?? 'unassigned' }}</span>@endif</span>
                    </a>
                @empty
                    <div class="text-muted small">No open tickets.</div>
                @endforelse
            </x-cb.card>

            <x-cb.card title="Planned maintenance" icon="ri-tools-line">
                @forelse ($maintenance as $w)
                    <div class="py-2 border-bottom">
                        <div class="fw-semibold">{{ $w->title }} @if ($w->isRunning())<span class="status-pill st-info">Now</span>@endif</div>
                        <div class="small text-muted">{{ $w->starts_at->format('D j M, g:i A') }} – {{ $w->ends_at->format($w->ends_at->isSameDay($w->starts_at) ? 'g:i A' : 'D j M, g:i A') }}{{ $w->affects ? ' · ' . $w->affects : '' }}</div>
                    </div>
                @empty
                    <div class="text-muted small">Nothing planned.</div>
                @endforelse
                @if ($stats['warranty'])
                    <div class="small mt-2"><i class="ri-shield-check-line text-warning"></i> <a href="{{ route('it.assets.index') }}">{{ $stats['warranty'] }} warranty(ies) ending within 60 days</a></div>
                @endif
            </x-cb.card>
        </div>
    </div>
    <p class="small text-muted mb-0">The page refreshes itself every minute. Figures are measured by this system's own checks; they cover what has been set up under Monitors, not every device in the hotel.</p>

</div>
</div>
</div>
<script>
    // Refresh the wall every minute, but not while someone is typing or a dialog is open.
    setInterval(function () {
        if (document.hidden || document.querySelector('.modal.show') || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
        location.reload();
    }, 60000);
</script>
@endsection
