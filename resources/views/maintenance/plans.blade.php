@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $p = $edit;
    $v = fn ($k, $d = null) => old($k, $p?->{$k} ?? $d);
    $canManage = auth()->user()->can('Manage maintenance');
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-calendar-schedule-line" :back="route('maintenance.index')" back-label="Maintenance Jobs"
               subtitle="Servicing that repeats: the generator, AC units, fire extinguishers, the pool pump. A job opens in Maintenance Jobs a few days before each due date.">
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Active plans" :value="$stats['plans']" icon="ri-calendar-schedule-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Due this month" :value="$stats['month']" icon="ri-calendar-event-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Overdue" :value="$stats['overdue']" icon="ri-alarm-warning-line" accent="rose" :hint="$stats['open'] . ' servicing job(s) open'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Serviced this month" :value="$stats['done']" icon="ri-checkbox-circle-line" accent="green" /></div>
    </div>

    <div class="row g-3">
        <div class="{{ $canManage ? 'col-xl-8' : 'col-12' }}">
            <div class="cb-card">
                @if ($plans->isEmpty())
                    <div class="empty-state py-5"><i class="ri-calendar-schedule-line"></i><h6>No servicing plans yet</h6>
                        <p class="text-muted small mb-0">Add the equipment that needs regular servicing, how often, and when it is next due.</p></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Service</th><th>Where</th><th>How often</th><th>Next due</th><th>Job</th>@if ($canManage)<th></th>@endif</tr></thead>
                            <tbody>
                                @foreach ($plans as $pl)
                                    @php $jobs = $open[$pl->id] ?? collect(); @endphp
                                    <tr class="{{ $pl->active ? '' : 'opacity-50' }}">
                                        <td data-label="Service"><span class="fw-semibold"><i class="{{ \App\Models\MaintenanceOrder::CATEGORIES[$pl->category][1] ?? 'ri-tools-line' }} text-muted"></i> {{ $pl->title }}</span>
                                            <div class="small text-muted">{{ \App\Models\MaintenanceOrder::CATEGORIES[$pl->category][0] ?? $pl->category }}{{ $pl->assignee ? ' · ' . $pl->assignee->name : '' }}{{ $pl->active ? '' : ' · paused' }}</div></td>
                                        <td data-label="Where">{{ $pl->where }}</td>
                                        <td data-label="How often">{{ $pl->interval_label }}@if ($pl->last_done_on)<div class="small text-muted">last done {{ $pl->last_done_on->format('j M Y') }}</div>@endif</td>
                                        <td data-label="Next due" class="{{ $pl->isOverdue() ? 'text-danger fw-semibold' : '' }}">{{ $pl->next_due_on->format('D j M Y') }}
                                            <div class="small {{ $pl->isOverdue() ? '' : 'text-muted' }}">{{ $pl->isOverdue() ? 'overdue' : ($pl->next_due_on->isToday() ? 'today' : $pl->next_due_on->diffForHumans(today(), ['parts' => 1, 'syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW])) }}</div></td>
                                        <td data-label="Job">
                                            @if ($jobs->count() === 1)<a href="{{ route('maintenance.show', $jobs->first()->id) }}">{{ $jobs->first()->number }}</a>
                                            @elseif ($jobs->count() > 1)<a href="{{ route('maintenance.index') }}">{{ $jobs->count() }} open</a>
                                            @elseif ($pl->active)<span class="small text-muted">{{ $pl->opensOn()->lte(today()) ? 'opens within the hour' : 'opens ' . $pl->opensOn()->format('j M') }}</span>
                                            @else — @endif</td>
                                        @if ($canManage)
                                            <td class="text-nowrap">
                                                <a class="action-btn btn-more" href="{{ route('maintenance.plans.index', ['edit' => $pl->id]) }}" title="Change"><i class="ri-edit-line"></i></a>
                                                @if ($pl->active && $jobs->isEmpty())
                                                    <form method="POST" action="{{ route('maintenance.plans.now', $pl) }}" class="d-inline" data-once>@csrf<button class="action-btn btn-open" title="Open the job now"><i class="ri-play-line"></i></button></form>
                                                @endif
                                                <form method="POST" action="{{ route('maintenance.plans.toggle', $pl) }}" class="d-inline">@csrf<button class="action-btn btn-more" title="{{ $pl->active ? 'Pause' : 'Resume' }}"><i class="{{ $pl->active ? 'ri-pause-line' : 'ri-restart-line' }}"></i></button></form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
        @if ($canManage)
            <div class="col-xl-4">
                <x-cb.card :title="$p ? 'Change “' . $p->title . '”' : 'Add a servicing plan'" icon="{{ $p ? 'ri-edit-line' : 'ri-add-line' }}">
                    <form method="POST" action="{{ $p ? route('maintenance.plans.update', $p) : route('maintenance.plans.store') }}" class="row g-2 rm-form" data-once>
                        @csrf @if ($p) @method('PUT') @endif
                        <div class="col-12"><label for="mpTitle">What is serviced</label>
                            <input id="mpTitle" name="title" class="form-control" maxlength="150" required value="{{ $v('title') }}" placeholder="e.g. Generator oil and filter change"></div>
                        <div class="col-6"><label for="mpCat">Kind</label>
                            <select id="mpCat" name="category" class="form-select">@foreach (\App\Models\MaintenanceOrder::CATEGORIES as $k => [$label])<option value="{{ $k }}" @selected($v('category', 'electrical') === $k)>{{ $label }}</option>@endforeach</select></div>
                        <div class="col-6"><label for="mpPri">Priority</label>
                            <select id="mpPri" name="priority" class="form-select">@foreach (\App\Models\MaintenanceOrder::PRIORITIES as $k => [$label])<option value="{{ $k }}" @selected($v('priority', 'normal') === $k)>{{ $label }}</option>@endforeach</select></div>
                        <div class="col-12"><label for="mpScope">Where</label>
                            <select id="mpScope" name="scope" class="form-select">
                                <option value="one" @selected($v('scope', 'one') === 'one')>One room or place</option>
                                <option value="rooms" @selected($v('scope') === 'rooms')>Every room (one job per room)</option>
                            </select></div>
                        <div class="col-5"><label for="mpRoom">Room</label>
                            <select id="mpRoom" name="room_id" class="form-select"><option value="">Not a room</option>@foreach ($rooms as $rm)<option value="{{ $rm->id }}" @selected((int) $v('room_id') === $rm->id)>{{ $rm->number }}</option>@endforeach</select></div>
                        <div class="col-7"><label for="mpLoc">Or place</label>
                            <input id="mpLoc" name="location" class="form-control" maxlength="120" value="{{ $v('location') }}" placeholder="Generator house"></div>
                        <div class="col-4"><label for="mpEvery">Every</label>
                            <input id="mpEvery" name="every" type="number" min="1" max="120" class="form-control" required value="{{ $v('every', 1) }}"></div>
                        <div class="col-8"><label for="mpUnit">&nbsp;</label>
                            <select id="mpUnit" name="unit" class="form-select">@foreach (\App\Models\MaintenancePlan::UNITS as $k => [, $many])<option value="{{ $k }}" @selected($v('unit', 'month') === $k)>{{ $many }}</option>@endforeach</select></div>
                        <div class="col-7"><label for="mpDue">Next due</label>
                            <input id="mpDue" name="next_due_on" type="date" class="form-control" required value="{{ old('next_due_on', $p?->next_due_on?->toDateString() ?? today()->addWeek()->toDateString()) }}"></div>
                        <div class="col-5"><label for="mpLead">Open the job</label>
                            <div class="input-group"><input id="mpLead" name="lead_days" type="number" min="0" max="60" class="form-control" required value="{{ $v('lead_days', 3) }}"><span class="input-group-text">days early</span></div></div>
                        <div class="col-12"><label for="mpWho">Usually done by</label>
                            <select id="mpWho" name="assigned_to" class="form-select"><option value="">Whoever takes it</option>@foreach ($technicians as $t)<option value="{{ $t->id }}" @selected((int) $v('assigned_to') === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
                        <div class="col-12"><label for="mpDesc">Checklist for the technician <span class="text-muted fw-normal">(optional)</span></label>
                            <textarea id="mpDesc" name="description" class="form-control" rows="3" maxlength="2000" placeholder="Change oil and filter&#10;Check battery and coolant&#10;Run on load for 15 minutes">{{ $v('description') }}</textarea></div>
                        <div class="col-12 d-flex gap-2 mt-2">
                            <button class="action-btn btn-open" type="submit"><i class="ri-save-line"></i> {{ $p ? 'Save' : 'Add plan' }}</button>
                            @if ($p)<a class="action-btn btn-more" href="{{ route('maintenance.plans.index') }}">Cancel</a>@endif
                        </div>
                    </form>
                    @if ($p)
                        <form method="POST" action="{{ route('maintenance.plans.destroy', $p) }}" class="mt-3" onsubmit="return confirm('Remove this plan? Jobs already done or open are kept.')">@csrf @method('DELETE')
                            <button class="btn btn-link text-danger p-0 small" type="submit"><i class="ri-delete-bin-line"></i> Remove this plan</button></form>
                    @endif
                </x-cb.card>
            </div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
