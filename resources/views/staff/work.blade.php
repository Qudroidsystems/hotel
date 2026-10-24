@extends('layouts.staff')

@section('content')
@php
    use App\Models\HousekeepingTask as HK;
    use App\Models\MaintenanceOrder as MO;
    $me = auth()->user();
    $hkManager = $me->can('Manage housekeeping');
    $needInspect = config('hotel.housekeeping.require_inspection', true);
    $groups = ['housekeeping' => 'Rooms to clean', 'maintenance' => 'Repairs', 'ticket' => 'IT tickets'];
@endphp

@if ($work->isEmpty())
    <div class="sa-empty"><i class="ri-checkbox-circle-line"></i><div class="fw-bold">All done</div><div class="small">Nothing is assigned to you right now.</div></div>
@endif

@foreach ($groups as $kind => $title)
    @php $items = $work->where('kind', $kind); @endphp
    @continue($items->isEmpty())
    <div class="sa-sec">{{ $title }} · {{ $items->count() }}</div>

    @foreach ($items as $it)
        @php $m = $it->model; @endphp

        @if ($kind === 'housekeeping')
            <div class="sa-item {{ $it->urgent ? 'urgent' : '' }}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div><div class="big">{{ $m->room?->number }}</div><div class="m">{{ $m->type_label }}{{ $m->room?->floor ? ' · ' . $m->room->floor->name : '' }}{{ $m->urgent ? ' · URGENT' : '' }}</div></div>
                    <span class="status-pill {{ $m->status_pill }}">{{ $m->status_label }}</span>
                </div>
                @if ($m->notes)<div class="small mt-2"><i class="ri-sticky-note-line text-muted"></i> {{ $m->notes }}</div>@endif
                @if ($m->assignee && $m->assignee->id !== $me->id)<div class="m">Assigned to {{ $m->assignee->name }}</div>@elseif (! $m->assignee)<div class="m">Not assigned — anyone on the team can take it</div>@endif
                <div class="sa-btns">
                    @if ($m->status === HK::PENDING)
                        <form method="POST" action="{{ route('housekeeping.start', $m) }}" data-once>@csrf<button class="sa-btn go"><i class="ri-play-fill"></i> Start cleaning</button></form>
                    @elseif ($m->status === HK::IN_PROGRESS)
                        <form method="POST" action="{{ route('housekeeping.finish', $m) }}" data-once>@csrf<button class="sa-btn go"><i class="ri-check-double-line"></i> {{ $m->type === 'checkout' && $needInspect ? 'Done — ready for inspection' : 'Done' }}</button></form>
                    @elseif ($m->status === HK::DONE && $hkManager)
                        <form method="POST" action="{{ route('housekeeping.inspect', $m) }}" data-once>@csrf<input type="hidden" name="result" value="pass"><button class="sa-btn go"><i class="ri-thumb-up-line"></i> Passed</button></form>
                        <form method="POST" action="{{ route('housekeeping.inspect', $m) }}" data-once>@csrf<input type="hidden" name="result" value="fail"><input type="hidden" name="note" value="Sent back from inspection"><button class="sa-btn bad"><i class="ri-thumb-down-line"></i> Clean again</button></form>
                    @endif
                    @if ($me->canAny(['Report maintenance issues', 'Manage maintenance']))
                        <a href="{{ route('staff.report', ['room' => $m->room_id, 'task' => $m->id]) }}#fault" class="sa-btn" style="flex:0 0 auto"><i class="ri-tools-line"></i> Fault</a>
                    @endif
                </div>
            </div>

        @elseif ($kind === 'maintenance')
            <div class="sa-item {{ $it->urgent ? 'urgent' : '' }}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div><div class="t"><i class="{{ $m->category_icon }} text-muted"></i> {{ $m->title }}</div><div class="m">{{ $m->where }} · {{ $m->number }}{{ $m->due_at ? ' · due ' . $m->due_at->diffForHumans() : '' }}</div></div>
                    <span class="status-pill {{ $m->priority_pill }}">{{ $m->priority_label }}</span>
                </div>
                @if ($m->description)<div class="small mt-2">{{ \Illuminate\Support\Str::limit($m->description, 160) }}</div>@endif
                <div class="m">{{ $m->status_label }}{{ $m->assigned_to ? '' : ' · not assigned' }}{{ $m->blocks_room ? ' · room off sale' : '' }}</div>
                <div class="sa-btns">
                    @if ($m->status === MO::OPEN || $m->status === MO::ON_HOLD)
                        @if ($m->assigned_to === $me->id || $me->can('Manage maintenance'))
                            <form method="POST" action="{{ route('maintenance.start', $m) }}" data-once>@csrf<button class="sa-btn go"><i class="ri-play-fill"></i> Start</button></form>
                        @endif
                    @endif
                    @if ($m->status === MO::IN_PROGRESS && ($m->assigned_to === $me->id || $me->can('Manage maintenance')))
                        <button type="button" class="sa-btn go" data-bs-toggle="collapse" data-bs-target="#done{{ $m->id }}"><i class="ri-check-double-line"></i> Mark fixed</button>
                    @endif
                    <a href="{{ route('maintenance.show', $m) }}" class="sa-btn" style="flex:0 0 auto">Details</a>
                </div>
                @if ($m->status === MO::IN_PROGRESS)
                    <form method="POST" action="{{ route('maintenance.complete', $m) }}" enctype="multipart/form-data" class="collapse mt-3" id="done{{ $m->id }}" data-once>@csrf
                        <label for="note{{ $m->id }}">What did you do?</label>
                        <textarea name="completion_note" id="note{{ $m->id }}" class="form-control mb-2" rows="2" maxlength="1000" required placeholder="e.g. Replaced the capacitor, AC cooling again"></textarea>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label for="cost{{ $m->id }}">Parts cost</label><input type="number" name="cost" id="cost{{ $m->id }}" class="form-control" min="0" step="0.01" inputmode="decimal"></div>
                            <div class="col-6"><label for="ph{{ $m->id }}">Photo</label><input type="file" name="photos[]" id="ph{{ $m->id }}" class="form-control" accept="image/*" capture="environment"></div>
                        </div>
                        <button class="sa-btn go w-100"><i class="ri-check-line"></i> Save as fixed</button>
                    </form>
                @endif
            </div>

        @else
            <a href="{{ route('it.tickets.show', $m) }}" class="sa-item d-block text-decoration-none {{ $it->urgent ? 'urgent' : '' }}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div><div class="t"><i class="{{ $m->category_icon }} text-muted"></i> {{ $m->title }}</div>
                        <div class="m">{{ $m->number }} · {{ $m->requester?->name }}{{ $m->location ? ' · ' . $m->location : '' }} · {{ $m->created_at->diffForHumans() }}</div></div>
                    <span class="status-pill {{ $m->priority_pill }}">{{ $m->priority_label }}</span>
                </div>
                <div class="m">{{ $m->status_label }}{{ $m->assigned_to ? '' : ' · not assigned' }}
                    @if ($m->responseLate() || $m->resolveLate())<span class="text-danger fw-semibold"> · overdue</span>@elseif (! $m->first_response_at)<span> · respond {{ $m->response_due_at->diffForHumans() }}</span>@endif</div>
            </a>
        @endif
    @endforeach
@endforeach
@endsection
