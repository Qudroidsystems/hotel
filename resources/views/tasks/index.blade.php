@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $q = request()->only(['view', 'department', 'priority', 'done']); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-task-line" subtitle="Jobs for people and departments: who is doing what, by when, and what is late.">
        <x-slot:actions>
            @if ($canGive)
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#taskModal"><i class="ri-add-line"></i> Give a task</button>
            @endif
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('tasks.index', ['view' => 'mine']) }}"><x-cb.stat label="For me" :value="$stats['mine']" icon="ri-user-star-line" accent="teal" hint="mine, and my department's unassigned" /></a></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('tasks.index', ['view' => 'overdue']) }}"><x-cb.stat label="Overdue" :value="$stats['overdue']" icon="ri-alarm-warning-line" accent="rose" :hint="$stats['urgent'] . ' urgent or high open'" /></a></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('tasks.index', ['view' => 'check']) }}"><x-cb.stat label="Waiting for a check" :value="$stats['check']" icon="ri-shield-check-line" accent="violet" /></a></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Done in the last 7 days" :value="$stats['week']" icon="ri-checkbox-circle-line" accent="green" /></div>
    </div>

    <div class="rs-tabs">
        @foreach ($views as $k => $label)
            <a href="{{ route('tasks.index', ['view' => $k]) }}" class="rs-tab {{ $view === $k ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <input type="hidden" name="view" value="{{ $view }}">
            @if ($view !== 'check')
                <select name="done" class="cb-select" aria-label="Show" onchange="this.form.submit()">
                    <option value="0">Still to do</option><option value="1" @selected($done)>Done or cancelled</option>
                </select>
            @endif
            <select name="department" class="cb-select" aria-label="Department" onchange="this.form.submit()">
                <option value="">Any department</option>
                @foreach ($departments as $d)<option value="{{ $d->id }}" @selected((int) request('department') === $d->id)>{{ $d->name }}</option>@endforeach
            </select>
            <select name="priority" class="cb-select" aria-label="Priority" onchange="this.form.submit()">
                <option value="">Any priority</option>
                @foreach (\App\Models\StaffTask::PRIORITIES as $k => [$label])<option value="{{ $k }}" @selected(request('priority') === $k)>{{ $label }}</option>@endforeach
            </select>
        </form>
        @if ($list->isEmpty())
            <div class="empty-state py-5"><i class="ri-task-line"></i><h6>Nothing here</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Task</th><th>For</th><th>Priority</th><th>Status</th><th>Due</th></tr></thead>
                    <tbody>
                        @foreach ($list as $t)
                            <tr>
                                <td data-label="Task"><a href="{{ route('tasks.show', $t) }}" class="fw-semibold text-reset">{{ $t->title }}</a>
                                    <div class="small text-muted">{{ $t->number }}{{ $t->where ? ' · ' . $t->where : '' }} · from {{ $t->creator?->name ?? 'system' }}@if ($t->needs_check) · <i class="ri-shield-check-line"></i> checked @endif</div></td>
                                <td data-label="For">@if ($t->department)<span class="d-inline-block rounded-circle me-1" style="width:8px;height:8px;background:{{ $t->department->color }}"></span>@endif{{ $t->for_label }}</td>
                                <td data-label="Priority"><span class="status-pill {{ $t->priority_pill }}">{{ $t->priority_label }}</span></td>
                                <td data-label="Status"><span class="status-pill {{ $t->status_pill }}">{{ $t->status_label }}</span>@if ($t->escalation_level === 2 && $t->isActive())<div class="small text-danger">escalated</div>@endif</td>
                                <td data-label="Due" class="{{ $t->isOverdue() ? 'text-danger fw-semibold' : '' }}">
                                    @if ($t->completed_at)<span class="text-muted">done {{ $t->completed_at->format('j M') }}</span>
                                    @elseif ($t->due_at){{ $t->due_at->format('D j M, g:i A') }}@if ($t->isOverdue())<div class="small">overdue {{ $t->due_at->diffForHumans(null, true) }}</div>@endif
                                    @else — @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $list->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>
@if ($canGive)
    @include('tasks.partials.give-modal')
    @if ($errors->any())<script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('taskModal')).show());</script>@endif
@endif
@endsection
