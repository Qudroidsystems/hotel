@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<style>
    .mt-photos { display: flex; gap: 8px; flex-wrap: wrap; } .mt-photos a img { width: 110px; height: 90px; object-fit: cover; border-radius: 10px; border: 1px solid var(--cb-border); }
    .inc-timeline { list-style: none; padding: 0; margin: 0; }
    .inc-timeline li { position: relative; padding: 0 0 14px 22px; border-left: 2px solid var(--cb-border); margin-left: 6px; }
    .inc-timeline li:last-child { border-left-color: transparent; }
    .inc-timeline li::before { content: ''; position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 50%; background: #94a3b8; border: 2px solid #fff; }
    .inc-timeline li.has-status::before { background: #0d9488; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$incident->title" icon="{{ $incident->type_icon }}" :back="route('incidents.index')" back-label="Incidents"
               :subtitle="$incident->number . ' · ' . $incident->type_label . ' · ' . $incident->where">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $incident->status_label }}</span>
            <span class="cb-meta-pill">{{ $incident->severity_label }} severity</span>
            @if ($incident->police_reference)<span class="cb-meta-pill">Police ref {{ $incident->police_reference }}</span>@endif
        </x-slot:pills>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($incident->resolution && ! $incident->isActive())
        <div class="cb-banner success"><i class="ri-check-line"></i><div><strong>{{ $incident->status_label }}</strong>{{ $incident->resolvedBy ? ' by ' . $incident->resolvedBy->name : '' }}{{ $incident->resolved_at ? ', ' . $incident->resolved_at->format('j M Y, g:i A') : '' }}: {{ $incident->resolution }}</div></div>
    @elseif ($incident->isSerious() && $incident->isActive())
        <div class="cb-banner danger"><i class="ri-alarm-warning-line"></i><div>{{ $incident->severity_label }} incident, open for {{ $incident->occurred_at->diffForHumans(null, true) }}.</div></div>
    @endif

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="What happened" icon="ri-information-line">
                <p class="mb-3" style="white-space: pre-line">{{ $incident->description }}</p>
                <div class="row g-2 small">
                    <div class="col-6"><span class="text-muted">When</span><div>{{ $incident->occurred_at->format('D j M Y, g:i A') }}</div></div>
                    <div class="col-6"><span class="text-muted">Reported</span><div>{{ $incident->created_at->format('D j M Y, g:i A') }}{{ $incident->reporter ? ' by ' . $incident->reporter->name : '' }}</div></div>
                    <div class="col-6"><span class="text-muted">Assigned to</span><div>{{ $incident->assignee?->name ?? 'Nobody yet' }}</div></div>
                    @if ($incident->reservation)
                        <div class="col-6"><span class="text-muted">Guest in the room</span><div>
                            @can('View reservations')<a href="{{ route('reservations.show', $incident->reservation) }}">{{ $incident->reservation->guest?->full_name }}</a>@else{{ $incident->reservation->guest?->full_name }}@endcan</div></div>
                    @endif
                    @if ($incident->people_involved)<div class="col-12"><span class="text-muted">People involved</span><div>{{ $incident->people_involved }}</div></div>@endif
                </div>
                @if ($photos = $incident->photoUrls())
                    <div class="mt-3 small text-muted">Photos</div>
                    <div class="mt-photos">@foreach ($photos as $p)<a href="{{ $p }}" target="_blank" rel="noopener"><img src="{{ $p }}" alt="Photo"></a>@endforeach</div>
                @endif
            </x-cb.card>

            <x-cb.card title="Timeline" icon="ri-history-line" :count="$incident->updates->count()">
                <ul class="inc-timeline">
                    @foreach ($incident->updates as $u)
                        <li class="{{ $u->status ? 'has-status' : '' }}">
                            <div class="small text-muted">{{ $u->created_at->format('D j M, g:i A') }} · {{ $u->user?->name ?? 'System' }}
                                @if ($u->status)<span class="status-pill {{ \App\Models\Incident::STATUSES[$u->status][1] ?? 'st-muted' }} ms-1">{{ \App\Models\Incident::STATUSES[$u->status][0] ?? $u->status }}</span>@endif</div>
                            <div style="white-space: pre-line">{{ $u->note }}</div>
                        </li>
                    @endforeach
                </ul>
            </x-cb.card>
        </div>

        <div class="col-xl-5">
            @can('Manage incidents')
                @if ($incident->isActive())
                    <x-cb.card title="Assign" icon="ri-user-settings-line">
                        <form method="POST" action="{{ route('incidents.assign', $incident) }}" class="rm-form d-flex gap-2" data-once>@csrf
                            <select name="assigned_to" class="form-select" aria-label="Officer"><option value="">Nobody</option>@foreach ($officers as $o)<option value="{{ $o->id }}" @selected($incident->assigned_to === $o->id)>{{ $o->name }}</option>@endforeach</select>
                            <button class="action-btn btn-open"><i class="ri-save-line"></i> Save</button>
                        </form>
                    </x-cb.card>
                @endif
            @endcan
            @if ($canWork && $incident->status !== 'closed')
                <x-cb.card title="{{ $incident->isActive() ? 'Add an update' : 'Reopen or close' }}" icon="ri-chat-1-line">
                    <form method="POST" action="{{ route('incidents.updates.store', $incident) }}" class="rm-form row g-2" data-once>@csrf
                        <div class="col-12"><label for="upNote">Note</label><textarea name="note" id="upNote" class="form-control" rows="3" maxlength="2000" required placeholder="What was done, who was contacted, what was found"></textarea></div>
                        <div class="col-6"><label for="upStatus">Status</label>
                            <select name="status" id="upStatus" class="form-select">
                                @foreach (\App\Models\Incident::STATUSES as $k => [$label])
                                    @continue($k === 'closed' && ! auth()->user()->can('Manage incidents'))
                                    <option value="{{ $k }}" @selected($incident->status === $k)>{{ $label }}</option>
                                @endforeach
                            </select></div>
                        <div class="col-6"><label for="upPolice">Police reference</label><input type="text" name="police_reference" id="upPolice" class="form-control" maxlength="60" value="{{ $incident->police_reference }}"></div>
                        <div class="col-12"><button class="action-btn btn-open"><i class="ri-send-plane-line"></i> Save update</button>
                            <div class="form-text">Choosing Resolved or Closed records the note as the resolution.</div></div>
                    </form>
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
