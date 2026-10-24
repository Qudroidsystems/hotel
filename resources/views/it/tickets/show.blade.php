@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php
    use App\Models\It\ItTicket;
    $me = auth()->user();
    $isRequester = $me->id === $ticket->requested_by;
    $canReply = $isIt || $isRequester || $me->id === $ticket->assigned_to;
@endphp
<style>
    .tk-timeline { list-style: none; padding: 0; margin: 0; }
    .tk-timeline li { position: relative; padding: 0 0 14px 22px; border-left: 2px solid var(--cb-border); margin-left: 6px; }
    .tk-timeline li:last-child { border-left-color: transparent; }
    .tk-timeline li::before { content: ''; position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 50%; background: #94a3b8; border: 2px solid #fff; }
    .tk-timeline li.has-status::before { background: #0d9488; }
    .tk-timeline li.internal { background: rgba(245, 158, 11, .06); border-radius: 0 8px 8px 0; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$ticket->title" :icon="$ticket->category_icon" :back="route('it.tickets.index')" back-label="Service desk"
               :subtitle="$ticket->number . ' · ' . $ticket->category_label . ($ticket->location ? ' · ' . $ticket->location : '') . ' · raised by ' . ($ticket->requester?->name ?? '—') . ' ' . $ticket->created_at->diffForHumans()">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $ticket->status_label }}</span>
            <span class="cb-meta-pill">{{ $ticket->priority_label }} priority</span>
            <span class="cb-meta-pill"><i class="ri-user-line"></i> {{ $ticket->assignee?->name ?? 'Not assigned yet' }}</span>
        </x-slot:pills>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($ticket->status === 'resolved' && $isRequester)
        <div class="cb-banner success"><i class="ri-check-line"></i><div><strong>IT marked this fixed:</strong> {{ $ticket->resolution }} — please confirm it works, or reopen it below.</div></div>
    @elseif (! $ticket->isActive() && $ticket->resolution)
        <div class="cb-banner success"><i class="ri-check-line"></i><div><strong>{{ $ticket->status_label }}</strong>{{ $ticket->resolvedBy ? ' by ' . $ticket->resolvedBy->name : '' }}{{ $ticket->resolved_at ? ', ' . $ticket->resolved_at->format('D j M, g:i A') : '' }}: {{ $ticket->resolution }}</div></div>
    @elseif ($ticket->escalated_at && $ticket->isActive())
        <div class="cb-banner danger"><i class="ri-timer-flash-line"></i><div><strong>Escalated {{ $ticket->escalated_at->diffForHumans() }}</strong> — this ticket missed its {{ $ticket->first_response_at ? 'fix' : 'response' }} target.</div></div>
    @endif

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="The problem" icon="ri-information-line">
                <p style="white-space: pre-line" class="mb-3">{{ $ticket->description }}</p>
                <div class="row g-2 small">
                    <div class="col-6"><span class="text-muted">Department</span><div>{{ $ticket->department ?: '—' }}</div></div>
                    <div class="col-6"><span class="text-muted">Device</span><div>{{ $ticket->asset ? $ticket->asset->name . ($ticket->asset->ip_address ? ' (' . $ticket->asset->ip_address . ')' : '') : '—' }}</div></div>
                    @if ($alert)<div class="col-6"><span class="text-muted">From alert</span><div>{{ $alert->number }} · {{ $alert->status_label }}</div></div>@endif
                    @if ($isIt && $ticket->root_cause)<div class="col-12"><span class="text-muted">Root cause</span><div>{{ $ticket->root_cause }}</div></div>@endif
                </div>
            </x-cb.card>

            <x-cb.card title="Updates" icon="ri-chat-3-line" :count="$ticket->updates->count()">
                <ul class="tk-timeline">
                    @foreach ($ticket->updates as $u)
                        <li class="{{ $u->status ? 'has-status' : '' }} {{ $u->is_internal ? 'internal' : '' }}">
                            <div class="small text-muted">{{ $u->created_at->format('D j M, g:i A') }} · {{ $u->user?->name ?? 'System' }}
                                @if ($u->status)<span class="status-pill {{ ItTicket::STATUSES[$u->status][1] ?? 'st-muted' }} ms-1">{{ ItTicket::STATUSES[$u->status][0] ?? $u->status }}</span>@endif
                                @if ($u->is_internal)<span class="status-pill st-warning ms-1">IT only</span>@endif</div>
                            <div style="white-space: pre-line">{{ $u->note }}</div>
                        </li>
                    @endforeach
                </ul>

                @if ($canReply && ($ticket->status !== 'closed' || $isIt))
                    <form method="POST" action="{{ route('it.tickets.update', $ticket) }}" class="rm-form row g-2 border-top pt-3 mt-1" data-once>@csrf
                        <div class="col-12"><label for="upNote">{{ $isIt ? 'Reply or note' : 'Add a comment' }}</label><textarea name="note" id="upNote" class="form-control" rows="3" maxlength="3000" required placeholder="{{ $isIt ? 'What you found / did, or a question for the requester' : 'More details, or answer IT\'s question' }}">{{ old('note') }}</textarea></div>
                        @if ($isIt)
                            <div class="col-sm-6"><label for="upStatus">Status</label>
                                <select name="status" id="upStatus" class="form-select">@foreach (ItTicket::STATUSES as $k => [$label])<option value="{{ $k }}" @selected($ticket->status === $k)>{{ $label }}</option>@endforeach</select></div>
                            <div class="col-sm-6 d-flex align-items-end"><label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="internal" value="1"> <span class="form-check-label">IT only (requester won't see it)</span></label></div>
                            <div class="col-12 form-text">Choosing Resolved records your note as the fix. Your first reply or status change counts as the response.</div>
                        @elseif ($ticket->status === 'resolved')
                            <div class="col-12"><label for="upStatus">Is it fixed?</label>
                                <select name="status" id="upStatus" class="form-select"><option value="">Just add the comment</option><option value="closed">Yes — close the ticket</option><option value="open">No — reopen it</option></select></div>
                        @endif
                        <div class="col-12"><button class="action-btn btn-open"><i class="ri-send-plane-line"></i> Send</button></div>
                    </form>
                @endif
            </x-cb.card>
        </div>

        <div class="col-xl-5">
            <x-cb.card title="Targets" icon="ri-timer-line">
                <div class="d-flex justify-content-between py-2 border-bottom"><span>Respond by<div class="small text-muted">{{ $ticket->response_due_at?->format('D j M, g:i A') }}</div></span>
                    <span class="text-end">@if ($ticket->first_response_at)<span class="{{ $ticket->responseLate() ? 'text-danger' : 'text-success' }}"><i class="ri-check-line"></i> {{ $ticket->created_at->diffForHumans($ticket->first_response_at, true) }}</span><div class="small text-muted">{{ $ticket->responseLate() ? 'late' : 'on time' }}</div>
                        @else<span class="{{ $ticket->responseLate() ? 'text-danger fw-semibold' : '' }}">{{ $ticket->response_due_at?->diffForHumans() }}</span>@endif</span></div>
                <div class="d-flex justify-content-between py-2"><span>Fix by<div class="small text-muted">{{ $ticket->resolve_due_at?->format('D j M, g:i A') }}</div></span>
                    <span class="text-end">@if ($ticket->resolved_at)<span class="{{ $ticket->resolveLate() ? 'text-danger' : 'text-success' }}"><i class="ri-check-line"></i> {{ $ticket->created_at->diffForHumans($ticket->resolved_at, true) }}</span><div class="small text-muted">{{ $ticket->resolveLate() ? 'late' : 'on time' }}</div>
                        @else<span class="{{ $ticket->resolveLate() ? 'text-danger fw-semibold' : '' }}">{{ $ticket->resolve_due_at?->diffForHumans() }}</span>@endif</span></div>
            </x-cb.card>

            @if ($isIt)
                <x-cb.card title="Assign" icon="ri-user-settings-line">
                    <form method="POST" action="{{ route('it.tickets.assign', $ticket) }}" class="rm-form d-flex gap-2" data-once>@csrf
                        <select name="assigned_to" class="form-select" aria-label="Technician"><option value="">Nobody</option>@foreach ($technicians as $u)<option value="{{ $u->id }}" @selected($ticket->assigned_to === $u->id)>{{ $u->name }}{{ $u->id === $me->id ? ' (me)' : '' }}</option>@endforeach</select>
                        <button class="action-btn btn-open"><i class="ri-save-line"></i> Save</button>
                    </form>
                </x-cb.card>
                <x-cb.card title="Priority & root cause" icon="ri-flag-line">
                    <form method="POST" action="{{ route('it.tickets.triage', $ticket) }}" class="rm-form row g-2" data-once>@csrf
                        <div class="col-12"><label for="trPriority">Priority</label><select name="priority" id="trPriority" class="form-select">@foreach (ItTicket::PRIORITIES as $k => [$label])<option value="{{ $k }}" @selected($ticket->priority === $k)>{{ $label }} — {{ ItTicket::PRIORITY_HELP[$k] }}</option>@endforeach</select>
                            <div class="form-text">Changing priority resets the targets, counted from when the ticket was raised.</div></div>
                        <div class="col-12"><label for="trRoot">Root cause</label><textarea name="root_cause" id="trRoot" class="form-control" rows="2" maxlength="1000" placeholder="Why it happened — fill in for repeat problems">{{ $ticket->root_cause }}</textarea></div>
                        <div class="col-12"><button class="action-btn btn-open"><i class="ri-save-line"></i> Save</button></div>
                    </form>
                </x-cb.card>
                <x-cb.card title="Similar tickets (30 days)" icon="ri-repeat-line" :count="$similar->count()">
                    @if ($similar->count() >= 2)<div class="cb-banner warning mb-2"><i class="ri-repeat-line"></i><div>This keeps happening. Consider finding and recording the root cause.</div></div>@endif
                    @forelse ($similar as $s)
                        <a href="{{ route('it.tickets.show', $s) }}" class="d-flex justify-content-between py-1 border-bottom small text-reset text-decoration-none"><span>{{ $s->number }} · {{ str($s->title)->limit(40) }}</span><span class="status-pill {{ $s->status_pill }}">{{ $s->status_label }}</span></a>
                    @empty
                        <div class="small text-muted">None — same device, or same kind of problem in the same place.</div>
                    @endforelse
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
