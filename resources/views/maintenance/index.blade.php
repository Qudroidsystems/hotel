@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $sym = config('hotel.currency_symbol'); $q = request()->only(['show', 'priority', 'category', 'mine']); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-tools-line" subtitle="Faults and work orders. A job can keep its room off sale until it's fixed; the room then goes to housekeeping.">
        <x-slot:actions>
            @canany(['Report maintenance issues', 'Manage maintenance'])
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#faultModal"><i class="ri-add-line"></i> Report a fault</button>
            @endcanany
            @if ($seeAll)<a class="cb-hero-btn" href="{{ route('maintenance.index', ['mine' => $mine ? 0 : 1] + $q) }}"><i class="ri-user-line"></i> {{ $mine ? 'Everyone' : 'My jobs' }}</a>@endif
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Open jobs" :value="$stats['active']" icon="ri-tools-line" accent="teal" :hint="$stats['urgent'] . ' urgent or high'" /></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('maintenance.index', ['show' => 'active']) }}"><x-cb.stat label="Overdue" :value="$stats['overdue']" icon="ri-alarm-warning-line" accent="rose" /></a></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Rooms off sale for repairs" :value="$stats['blocked']" icon="ri-door-lock-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Fixed this month" :value="$stats['done']" icon="ri-checkbox-circle-line" accent="green" :hint="$sym . number_format($stats['cost'], 2) . ' spent' . ($stats['hours'] !== null ? ' · ' . $stats['hours'] . 'h average to fix' : '')" /></div>
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="show" class="cb-select" aria-label="Show" onchange="this.form.submit()">
                @foreach (['active' => 'Open jobs', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $label)<option value="{{ $k }}" @selected($show === $k)>{{ $label }}</option>@endforeach
            </select>
            <select name="priority" class="cb-select" aria-label="Priority" onchange="this.form.submit()">
                <option value="">Any priority</option>
                @foreach (\App\Models\MaintenanceOrder::PRIORITIES as $k => [$label])<option value="{{ $k }}" @selected($priority === $k)>{{ $label }}</option>@endforeach
            </select>
            <select name="category" class="cb-select" aria-label="Category" onchange="this.form.submit()">
                <option value="">Any kind</option>
                @foreach (\App\Models\MaintenanceOrder::CATEGORIES as $k => [$label])<option value="{{ $k }}" @selected($category === $k)>{{ $label }}</option>@endforeach
            </select>
            @if ($mine)<input type="hidden" name="mine" value="1">@endif
        </form>
        @if ($orders->isEmpty())
            <div class="empty-state py-5"><i class="ri-tools-line"></i><h6>No work orders here</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Job</th><th>Where</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Due</th></tr></thead>
                    <tbody>
                        @foreach ($orders as $o)
                            <tr>
                                <td data-label="Job"><a href="{{ route('maintenance.show', $o) }}" class="fw-semibold text-reset"><i class="{{ $o->category_icon }} text-muted"></i> {{ $o->title }}</a>
                                    <div class="small text-muted">{{ $o->number }} · {{ $o->category_label }} · reported {{ $o->created_at->diffForHumans() }}{{ $o->reporter ? ' by ' . $o->reporter->name : '' }}</div></td>
                                <td data-label="Where">{{ $o->where }}@if ($o->blocks_room)<div class="small text-danger"><i class="ri-door-lock-line"></i> off sale</div>@endif</td>
                                <td data-label="Priority"><span class="status-pill {{ $o->priority_pill }}">{{ $o->priority_label }}</span></td>
                                <td data-label="Status"><span class="status-pill {{ $o->status_pill }}">{{ $o->status_label }}</span>@if ($o->status === 'on_hold' && $o->hold_reason)<div class="small text-muted">{{ $o->hold_reason }}</div>@endif</td>
                                <td data-label="Assigned">{{ $o->assignee?->name ?? '—' }}</td>
                                <td data-label="Due" class="{{ $o->isOverdue() ? 'text-danger fw-semibold' : '' }}">
                                    @if ($o->completed_at)<span class="text-muted">done {{ $o->completed_at->format('j M') }}</span>
                                    @elseif ($o->due_at){{ $o->due_at->format('D j M, g:i A') }}@if ($o->isOverdue())<div class="small">overdue</div>@endif
                                    @else — @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $orders->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>
@canany(['Report maintenance issues', 'Manage maintenance'])
    @include('maintenance.partials.report-modal')
@endcanany
@endsection
