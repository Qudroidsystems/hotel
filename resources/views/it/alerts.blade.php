@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php $canAct = auth()->user()->canAny(['Manage IT monitoring', 'Manage IT tickets']); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="IT Alerts" icon="ri-alarm-warning-line" subtitle="Problems the monitors found. Acknowledge to show you're handling it; alerts close themselves when the check recovers." />

    @include('rooms.partials.flash')
    @include('it.partials.nav')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Not acknowledged" :value="$stats['open']" icon="ri-alarm-warning-line" accent="rose" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Being handled" :value="$stats['acked']" icon="ri-hand-heart-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Alerts this week" :value="$stats['week']" icon="ri-calendar-line" accent="teal" :hint="$stats['auto'] . ' recovered by themselves'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Average time to clear" :value="$stats['mttr'] !== null ? ($stats['mttr'] >= 120 ? round($stats['mttr'] / 60, 1) . ' h' : $stats['mttr'] . ' min') : '—'" icon="ri-timer-line" accent="green" hint="this week" /></div>
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="show" class="cb-select" aria-label="Show" onchange="this.form.submit()">
                @foreach (['active' => 'Open & being handled', 'resolved' => 'Resolved', 'all' => 'All'] as $k => $label)<option value="{{ $k }}" @selected($show === $k)>{{ $label }}</option>@endforeach
            </select>
        </form>
        @if ($alerts->isEmpty())
            <div class="empty-state py-5"><i class="ri-checkbox-circle-line text-success"></i><h6>No alerts here</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Alert</th><th>Severity</th><th>Status</th><th>Duration</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($alerts as $a)
                            <tr>
                                <td data-label="Alert"><div class="fw-semibold">{{ $a->title }}</div>
                                    <div class="small text-muted">{{ $a->number }} · {{ $a->opened_at->format('D j M, g:i A') }}@if ($a->monitor) · <a href="{{ route('it.monitors.show', $a->monitor) }}">{{ $a->monitor->name }}</a>@endif</div>
                                    @if ($a->message)<div class="small">{{ $a->message }}</div>@endif
                                    @if ($a->resolution)<div class="small text-success"><i class="ri-check-line"></i> {{ $a->resolution }}{{ $a->resolvedBy ? ' — ' . $a->resolvedBy->name : '' }}</div>@endif</td>
                                <td data-label="Severity"><span class="status-pill {{ $a->severity_pill }}">{{ $a->severity_label }}</span></td>
                                <td data-label="Status"><span class="status-pill {{ $a->status_pill }}">{{ $a->status_label }}</span>
                                    @if ($a->acknowledgedBy && $a->isActive())<div class="small text-muted">{{ $a->acknowledgedBy->name }}, {{ $a->acknowledged_at->diffForHumans() }}</div>@endif</td>
                                <td data-label="Duration" class="small">{{ $a->duration() }}{{ $a->isActive() ? ' so far' : '' }}</td>
                                <td class="text-end text-nowrap">
                                    @if ($a->ticket)
                                        <a href="{{ route('it.tickets.show', $a->ticket) }}" class="action-btn btn-more"><i class="ri-customer-service-2-line"></i> {{ $a->ticket->number }}</a>
                                    @endif
                                    @if ($canAct && $a->isActive())
                                        @if ($a->status === 'open')
                                            <form method="POST" action="{{ route('it.alerts.acknowledge', $a) }}" class="d-inline" data-once>@csrf<button class="action-btn btn-open"><i class="ri-hand-heart-line"></i> I'm on it</button></form>
                                        @endif
                                        @unless ($a->ticket)
                                            <form method="POST" action="{{ route('it.alerts.ticket', $a) }}" class="d-inline" data-once>@csrf<button class="action-btn btn-more" title="Open a ticket to track the fix"><i class="ri-add-line"></i> Ticket</button></form>
                                        @endunless
                                        <button type="button" class="action-btn btn-go" data-bs-toggle="collapse" data-bs-target="#res{{ $a->id }}"><i class="ri-check-line"></i> Resolve</button>
                                    @endif
                                </td>
                            </tr>
                            @if ($canAct && $a->isActive())
                                <tr class="collapse" id="res{{ $a->id }}"><td colspan="5">
                                    <form method="POST" action="{{ route('it.alerts.resolve', $a) }}" class="rm-form d-flex gap-2 flex-wrap" data-once>@csrf
                                        <input type="text" name="resolution" class="form-control" style="max-width:520px" maxlength="1000" required placeholder="What was wrong and what fixed it">
                                        <button class="action-btn btn-go"><i class="ri-check-line"></i> Mark resolved</button>
                                    </form>
                                </td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $alerts->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
