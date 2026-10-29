@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<style>.tk-files { display: flex; gap: 8px; flex-wrap: wrap; } .tk-files img { width: 110px; height: 90px; object-fit: cover; border-radius: 10px; border: 1px solid var(--cb-border); }
.tk-doc { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border: 1px solid var(--cb-border); border-radius: 10px; font-size: 13px; }</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$task->title" icon="ri-task-line" :back="route('tasks.index')" back-label="Tasks"
               :subtitle="$task->number . ' · for ' . $task->for_label . ($task->where ? ' · ' . $task->where : '')">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $task->status_label }}</span>
            <span class="cb-meta-pill">{{ $task->priority_label }} priority</span>
            @if ($task->needs_check)<span class="cb-meta-pill">Needs a check</span>@endif
        </x-slot:pills>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($task->isOverdue())
        <div class="cb-banner danger"><i class="ri-alarm-warning-line"></i><div>Overdue: this was due {{ $task->due_at->diffForHumans() }}.@if ($task->escalation_level === 2) Management has been told.@elseif ($task->escalation_level === 1) The department manager has been reminded.@endif</div></div>
    @endif

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="Details" icon="ri-information-line">
                @if ($task->details)<p class="mb-3" style="white-space: pre-line">{{ $task->details }}</p>@endif
                <div class="row g-2 small">
                    <div class="col-6"><span class="text-muted">Given</span><div>{{ $task->created_at->format('D j M Y, g:i A') }}{{ $task->creator ? ' by ' . $task->creator->name : '' }}</div></div>
                    <div class="col-6"><span class="text-muted">Due</span><div>{{ $task->due_at?->format('D j M Y, g:i A') ?? 'No deadline' }}</div></div>
                    <div class="col-6"><span class="text-muted">Department</span><div>{{ $task->department?->name ?? '—' }}{{ $task->department?->manager ? ' (manager: ' . $task->department->manager->name . ')' : '' }}</div></div>
                    <div class="col-6"><span class="text-muted">Doing it</span><div>{{ $task->assignee?->name ?? 'Anyone in the department' }}</div></div>
                    @if ($task->started_at)<div class="col-6"><span class="text-muted">Started</span><div>{{ $task->started_at->format('D j M, g:i A') }}</div></div>@endif
                    @if ($task->completed_at)<div class="col-6"><span class="text-muted">Done</span><div>{{ $task->completed_at->format('D j M, g:i A') }}{{ $task->completedBy ? ' by ' . $task->completedBy->name : '' }}</div></div>@endif
                    @if ($task->checked_at)<div class="col-6"><span class="text-muted">Checked</span><div>{{ $task->checked_at->format('D j M, g:i A') }}{{ $task->checkedBy ? ' by ' . $task->checkedBy->name : '' }}</div></div>@endif
                </div>
                @if ($task->completion_note)<div class="cb-banner success mt-3 mb-0"><i class="ri-check-line"></i><div><strong>Done:</strong> {{ $task->completion_note }}</div></div>@endif
                @if ($files = $task->files())
                    <div class="mt-3 small text-muted">Photos and documents</div>
                    <div class="tk-files">
                        @foreach ($files as $f)
                            @if ($f['image'])<a href="{{ $f['url'] }}" target="_blank" rel="noopener" title="{{ $f['name'] }}"><img src="{{ $f['url'] }}" alt="{{ $f['name'] }}"></a>
                            @else<a href="{{ $f['url'] }}" target="_blank" rel="noopener" class="tk-doc text-reset"><i class="ri-file-text-line"></i> {{ \Illuminate\Support\Str::limit($f['name'], 28) }}</a>@endif
                        @endforeach
                    </div>
                @endif
            </x-cb.card>

            <x-cb.card title="History" icon="ri-history-line" :count="$task->updates->count()">
                @if (! in_array($task->status, ['done', 'cancelled'], true))
                    <form method="POST" action="{{ route('tasks.comment', $task) }}" class="rm-form d-flex gap-2 mb-3 flex-wrap" enctype="multipart/form-data" data-once>@csrf
                        <input type="text" name="note" class="form-control" style="flex:1 1 240px" maxlength="1000" required placeholder="Add a note…" aria-label="Note">
                        <input type="file" name="file" class="form-control" style="flex:0 1 220px" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt" aria-label="Attach a file">
                        <button class="action-btn btn-open"><i class="ri-chat-1-line"></i> Add</button>
                    </form>
                @endif
                @foreach ($task->updates as $u)
                    <div class="d-flex gap-2 py-2 border-bottom small">
                        <i class="{{ ['note' => 'ri-chat-1-line', 'status' => 'ri-flag-line', 'assigned' => 'ri-user-shared-line', 'escalated' => 'ri-alarm-warning-line text-danger', 'returned' => 'ri-arrow-go-back-line text-warning'][$u->kind] ?? 'ri-flag-line' }} text-muted"></i>
                        <div class="flex-grow-1">{{ $u->body }}@if ($u->attachment) <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($u->attachment) }}" target="_blank" rel="noopener">(file)</a>@endif
                            <div class="text-muted">{{ $u->user?->name ?? 'System' }} · {{ $u->created_at->format('D j M, g:i A') }}</div></div>
                    </div>
                @endforeach
            </x-cb.card>
        </div>

        <div class="col-xl-5">
            @if ($can['check'])
                <x-cb.card title="Check the work" icon="ri-shield-check-line">
                    <form method="POST" action="{{ route('tasks.check', $task) }}" class="rm-form row g-2" data-once>@csrf
                        <div class="col-12"><textarea name="note" class="form-control" rows="2" maxlength="1000" placeholder="Comment (needed if it must be redone)" aria-label="Comment"></textarea></div>
                        <div class="col-12 d-flex gap-2">
                            <button name="decision" value="ok" class="action-btn btn-open"><i class="ri-check-double-line"></i> Confirm done</button>
                            <button name="decision" value="redo" class="action-btn btn-more"><i class="ri-arrow-go-back-line"></i> Send back</button>
                        </div>
                    </form>
                </x-cb.card>
            @endif

            @if ($can['work'])
                <x-cb.card title="Work on it" icon="ri-hammer-line">
                    <div class="d-flex gap-2 flex-wrap mb-3">
                        @if (! $task->assigned_to)<form method="POST" action="{{ route('tasks.take', $task) }}" data-once>@csrf<button class="action-btn btn-more"><i class="ri-hand-heart-line"></i> I'll do it</button></form>@endif
                        @if ($task->status === 'open')<form method="POST" action="{{ route('tasks.start', $task) }}" data-once>@csrf<button class="action-btn btn-open"><i class="ri-play-line"></i> Start</button></form>@endif
                    </div>
                    <form method="POST" action="{{ route('tasks.finish', $task) }}" class="rm-form row g-2 border-top pt-3" enctype="multipart/form-data" data-once>@csrf
                        <div class="col-12"><label for="fnNote">What was done <span class="text-muted fw-normal">(optional)</span></label><textarea name="note" id="fnNote" class="form-control" rows="2" maxlength="1000"></textarea></div>
                        <div class="col-12"><label for="fnFiles">Photos of the finished job</label><input type="file" name="files[]" id="fnFiles" class="form-control" accept="image/*,.pdf" multiple capture="environment"></div>
                        <div class="col-12"><button class="action-btn btn-open"><i class="ri-check-double-line"></i> Mark as done</button>
                            @if ($task->needs_check)<div class="form-text">A supervisor will check it before it counts as done.</div>@endif</div>
                    </form>
                </x-cb.card>
            @endif

            @if ($can['manage'] && $task->isActive())
                <x-cb.card title="Change" icon="ri-user-settings-line">
                    <form method="POST" action="{{ route('tasks.change', $task) }}" class="rm-form row g-2" data-once>@csrf
                        <div class="col-6"><label for="chDept">Department</label><select name="department_id" id="chDept" class="form-select"><option value="">—</option>@foreach ($departments as $d)<option value="{{ $d->id }}" @selected($task->department_id === $d->id)>{{ $d->name }}</option>@endforeach</select></div>
                        <div class="col-6"><label for="chPerson">Person</label><select name="assigned_to" id="chPerson" class="form-select"><option value="">Anyone in the department</option>@foreach ($people as $p)<option value="{{ $p->id }}" @selected($task->assigned_to === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
                        <div class="col-6"><label for="chPriority">Priority</label><select name="priority" id="chPriority" class="form-select">@foreach (\App\Models\StaffTask::PRIORITIES as $k => [$label])<option value="{{ $k }}" @selected($task->priority === $k)>{{ $label }}</option>@endforeach</select></div>
                        <div class="col-6"><label for="chDue">Due</label><input type="datetime-local" name="due_at" id="chDue" class="form-control" value="{{ $task->due_at?->format('Y-m-d\TH:i') }}"></div>
                        <div class="col-12"><button class="action-btn btn-open"><i class="ri-save-line"></i> Save</button></div>
                    </form>
                </x-cb.card>
            @endif
            @if ($can['manage'] && ! in_array($task->status, ['done', 'cancelled'], true))
                <form method="POST" action="{{ route('tasks.cancel', $task) }}" class="rm-form cb-card p-3" data-once>@csrf
                    <label for="cnReason">Cancel this task</label>
                    <div class="input-group"><input type="text" name="reason" id="cnReason" class="form-control" maxlength="255" required placeholder="e.g. No longer needed"><button class="action-btn btn-more">Cancel task</button></div>
                </form>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
