@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<style>
    .hk-col { background: var(--cb-surface-2); border-radius: 14px; padding: 12px; min-height: 120px; }
    .hk-col h6 { font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 6px; margin: 2px 4px 10px; }
    .hk-card { background: var(--cb-surface); border: 1px solid var(--cb-border); border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; }
    .hk-card.urgent { border-left: 4px solid #dc2626; }
    .hk-room { font-size: 20px; font-weight: 800; letter-spacing: .5px; }
    .hk-card form { display: inline; }
    .hk-card .action-btn { padding: 6px 12px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-brush-line" subtitle="Rooms are added here automatically when a guest checks out. Clean, then a supervisor inspects before the room goes back on sale.">
        <x-slot:actions>
            @if ($everyone)
                <a class="cb-hero-btn" href="{{ route('housekeeping.index', $mine ? [] : ['mine' => 1]) }}"><i class="ri-user-line"></i> {{ $mine ? 'Everyone' : 'My tasks' }}</a>
            @endif
            @if ($manager)
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#taskModal"><i class="ri-add-line"></i> Add task</button>
                <form method="POST" action="{{ route('housekeeping.stayovers') }}" class="d-inline" data-once>@csrf<button class="cb-hero-btn" type="submit"><i class="ri-hotel-bed-line"></i> Stay-overs for occupied rooms</button></form>
            @endif
            @canany(['Report maintenance issues', 'Manage maintenance'])
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#faultModal"><i class="ri-tools-line"></i> Report a fault</button>
            @endcanany
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="To clean" :value="$stats['pending']" icon="ri-brush-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Being cleaned" :value="$stats['cleaning']" icon="ri-hand-sanitizer-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Awaiting inspection" :value="$stats['inspection']" icon="ri-search-eye-line" accent="rose" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Ready today" :value="$stats['ready']" icon="ri-checkbox-circle-line" accent="green" :hint="$stats['turnaround'] ? 'average ' . ($stats['turnaround'] >= 60 ? intdiv($stats['turnaround'], 60) . 'h ' : '') . ($stats['turnaround'] % 60) . 'm from check-out to ready' : null" /></div>
    </div>

    <div class="row g-3">
        @foreach ($columns as $status => [$title, $icon, $tasks])
            <div class="col-lg-4">
                <div class="hk-col">
                    <h6><i class="{{ $icon }}"></i> {{ $title }} <span class="cb-count">{{ $tasks->count() }}</span></h6>
                    @forelse ($tasks as $t)
                        @php $canWork = $manager || (auth()->user()->can('Do housekeeping tasks') && in_array($t->assigned_to, [null, auth()->id()], true)); @endphp
                        <div class="hk-card {{ $t->urgent ? 'urgent' : '' }}">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="hk-room">{{ $t->room->number }}</div>
                                    <div class="small text-muted">{{ $t->room->roomType?->name }}{{ $t->room->floor ? ' · ' . $t->room->floor->name : '' }}</div>
                                </div>
                                <div class="text-end">
                                    <span class="status-pill st-muted">{{ $t->type_label }}</span>
                                    @if ($t->urgent)<div class="small text-danger fw-semibold mt-1"><i class="ri-alarm-warning-line"></i> Guest arriving today</div>@endif
                                </div>
                            </div>
                            <div class="small mt-2">
                                @if ($t->assignee)<i class="ri-user-line"></i> {{ $t->assignee->name }} · @endif
                                <span class="text-muted">{{ $status === 'pending' ? 'waiting ' . $t->created_at->diffForHumans(null, true) : ($status === 'in_progress' ? 'started ' . $t->started_at?->format('g:i A') : 'finished ' . $t->finished_at?->format('g:i A')) }}</span>
                            </div>
                            @if ($t->notes)<div class="small text-muted mt-1"><i class="ri-sticky-note-line"></i> {{ $t->notes }}</div>@endif
                            @if ($t->inspection_note && $status === 'pending')<div class="small text-danger mt-1"><i class="ri-error-warning-line"></i> Redo: {{ $t->inspection_note }}</div>@endif

                            <div class="d-flex flex-wrap gap-1 mt-2 align-items-center">
                                @if ($status === 'pending' && $canWork)
                                    <form method="POST" action="{{ route('housekeeping.start', $t) }}" data-once>@csrf<button class="action-btn btn-open"><i class="ri-play-line"></i> Start</button></form>
                                @endif
                                @if ($status === 'in_progress' && $canWork)
                                    <form method="POST" action="{{ route('housekeeping.finish', $t) }}" data-once>@csrf<button class="action-btn btn-open"><i class="ri-check-line"></i> Done</button></form>
                                @endif
                                @if ($status === 'done' && $manager)
                                    <form method="POST" action="{{ route('housekeeping.inspect', $t) }}" data-once>@csrf<input type="hidden" name="result" value="pass"><button class="action-btn btn-open"><i class="ri-check-double-line"></i> Pass</button></form>
                                    <button type="button" class="action-btn btn-more" data-bs-toggle="collapse" data-bs-target="#fail{{ $t->id }}"><i class="ri-close-line"></i> Redo</button>
                                @endif
                                @canany(['Report maintenance issues', 'Manage maintenance'])
                                    <button type="button" class="action-btn btn-more" data-bs-toggle="modal" data-bs-target="#faultModal" data-room="{{ $t->room_id }}" data-task="{{ $t->id }}" title="Report a fault"><i class="ri-tools-line"></i></button>
                                @endcanany
                                @if ($manager && $status !== 'done')
                                    <form method="POST" action="{{ route('housekeeping.assign', $t) }}" class="ms-auto">@csrf
                                        <select name="assigned_to" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Assign">
                                            <option value="">Unassigned</option>
                                            @foreach ($staff as $s)<option value="{{ $s->id }}" @selected($t->assigned_to === $s->id)>{{ $s->name }}</option>@endforeach
                                        </select></form>
                                @endif
                            </div>
                            @if ($status === 'done' && $manager)
                                <form method="POST" action="{{ route('housekeeping.inspect', $t) }}" class="collapse mt-2" id="fail{{ $t->id }}" data-once>@csrf
                                    <input type="hidden" name="result" value="fail">
                                    <div class="input-group input-group-sm"><input type="text" name="note" class="form-control" maxlength="500" required placeholder="What needs redoing?"><button class="action-btn btn-more">Send back</button></div>
                                </form>
                            @endif
                        </div>
                    @empty
                        <div class="small text-muted px-1">Nothing here.</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    @if ($readyToday->isNotEmpty())
        <x-cb.card title="Ready today" icon="ri-checkbox-circle-line" :count="$readyToday->count()" class="mt-3" :flush="true">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Room</th><th>Task</th><th>Cleaned by</th><th>Inspected by</th><th class="num">Turnaround</th></tr></thead>
                    <tbody>
                        @foreach ($readyToday as $t)
                            @php $mins = $t->turnaroundMinutes(); @endphp
                            <tr>
                                <td data-label="Room" class="fw-bold">{{ $t->room->number }}</td>
                                <td data-label="Task">{{ $t->type_label }}@if ($t->failed_inspections)<span class="small text-danger"> · redone {{ $t->failed_inspections }}×</span>@endif</td>
                                <td data-label="Cleaned by">{{ $t->finishedBy?->name ?? '—' }}</td>
                                <td data-label="Inspected by">{{ $t->inspectedBy?->name ?? '—' }}{{ $t->inspected_at ? ' · ' . $t->inspected_at->format('g:i A') : '' }}</td>
                                <td data-label="Turnaround" class="num">{{ $mins !== null ? ($mins >= 60 ? intdiv($mins, 60) . 'h ' : '') . ($mins % 60) . 'm' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-cb.card>
    @endif

</div>
</div>
</div>

@if ($manager)
<div class="modal fade" id="taskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('housekeeping.store') }}" data-once>
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-add-line me-1"></i> Add a housekeeping task</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-6"><label for="tkRoom">Room</label>
                    <select name="room_id" id="tkRoom" class="form-select" required>@foreach ($rooms as $r)<option value="{{ $r->id }}">{{ $r->number }} — {{ $r->status->label() }}</option>@endforeach</select></div>
                <div class="col-6"><label for="tkType">Task</label>
                    <select name="type" id="tkType" class="form-select">@foreach (\App\Models\HousekeepingTask::TYPES as $k => $label)<option value="{{ $k }}" @selected($k === 'deep')>{{ $label }}</option>@endforeach</select></div>
                <div class="col-12"><label for="tkAssign">Assign to</label>
                    <select name="assigned_to" id="tkAssign" class="form-select"><option value="">Anyone</option>@foreach ($staff as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                <div class="col-12"><label for="tkNotes">Notes</label><input type="text" name="notes" id="tkNotes" class="form-control" maxlength="500" placeholder="e.g. Extra towels, guest allergic to feathers"></div>
            </div>
            <div class="modal-footer"><button type="button" class="action-btn btn-more" data-bs-dismiss="modal">Cancel</button><button type="submit" class="action-btn btn-open">Add task</button></div>
        </form>
    </div>
</div>
@endif
@canany(['Report maintenance issues', 'Manage maintenance'])
    @include('maintenance.partials.report-modal', ['back' => 'housekeeping'])
@endcanany
@endsection
