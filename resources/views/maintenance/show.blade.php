@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $sym = config('hotel.currency_symbol'); @endphp
<style>.mt-photos { display: flex; gap: 8px; flex-wrap: wrap; } .mt-photos a img { width: 110px; height: 90px; object-fit: cover; border-radius: 10px; border: 1px solid var(--cb-border); }</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$order->title" icon="{{ $order->category_icon }}" :back="route('maintenance.index')" back-label="Maintenance"
               :subtitle="$order->number . ' · ' . $order->where . ' · ' . $order->category_label">
        <x-slot:pills>
            <span class="cb-meta-pill">{{ $order->status_label }}</span>
            <span class="cb-meta-pill">{{ $order->priority_label }} priority</span>
            @if ($order->blocks_room)<span class="cb-meta-pill">Room off sale</span>@endif
        </x-slot:pills>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($order->isOverdue())<div class="cb-banner danger"><i class="ri-alarm-warning-line"></i><div>Overdue — this was due {{ $order->due_at->diffForHumans() }}.</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="Details" icon="ri-information-line">
                @if ($order->description)<p class="mb-3" style="white-space: pre-line">{{ $order->description }}</p>@endif
                <div class="row g-2 small">
                    <div class="col-6"><span class="text-muted">Reported</span><div>{{ $order->created_at->format('D j M Y, g:i A') }}{{ $order->reporter ? ' by ' . $order->reporter->name : '' }}</div></div>
                    <div class="col-6"><span class="text-muted">Due</span><div>{{ $order->due_at?->format('D j M Y, g:i A') ?? '—' }}</div></div>
                    <div class="col-6"><span class="text-muted">Assigned to</span><div>{{ $order->assignee?->name ?? 'Nobody yet' }}</div></div>
                    <div class="col-6"><span class="text-muted">Started</span><div>{{ $order->started_at?->format('D j M, g:i A') ?? '—' }}</div></div>
                    @if ($order->room)<div class="col-6"><span class="text-muted">Room status now</span><div>@include('rooms.partials.status-pill', ['status' => $order->room->status])</div></div>@endif
                    @if ($order->completed_at)
                        <div class="col-6"><span class="text-muted">Completed</span><div>{{ $order->completed_at->format('D j M Y, g:i A') }}{{ $order->completedBy ? ' by ' . $order->completedBy->name : '' }}</div></div>
                        <div class="col-6"><span class="text-muted">Cost</span><div>{{ $order->cost !== null ? $sym . number_format((float) $order->cost, 2) : '—' }}</div></div>
                        <div class="col-6"><span class="text-muted">Time to fix</span><div>{{ $order->created_at->diffForHumans($order->completed_at, true) }}</div></div>
                    @endif
                </div>
                @if ($order->completion_note)<div class="cb-banner success mt-3 mb-0"><i class="ri-check-line"></i><div><strong>Work done:</strong> {{ $order->completion_note }}</div></div>@endif
                @if ($order->hold_reason && $order->status === 'on_hold')<div class="cb-banner warning mt-3 mb-0"><i class="ri-pause-line"></i><div><strong>On hold:</strong> {{ $order->hold_reason }}</div></div>@endif
                @if ($order->cancel_reason)<div class="cb-banner danger mt-3 mb-0"><i class="ri-close-line"></i><div><strong>Cancelled:</strong> {{ $order->cancel_reason }}</div></div>@endif
                @if ($photos = $order->photoUrls())
                    <div class="mt-3 small text-muted">Photos</div>
                    <div class="mt-photos">@foreach ($photos as $p)<a href="{{ $p['url'] }}" target="_blank" rel="noopener" title="{{ $p['stage'] === 'completion' ? 'After repair' : 'When reported' }}"><img src="{{ $p['url'] }}" alt="Photo"></a>@endforeach</div>
                @endif
            </x-cb.card>

            @if ($history->isNotEmpty())
                <x-cb.card title="Earlier jobs in this room" icon="ri-history-line" :count="$history->count()">
                    @foreach ($history as $h)
                        <div class="d-flex justify-content-between py-2 border-bottom small"><a href="{{ route('maintenance.show', $h) }}" class="text-reset">{{ $h->number }} · {{ $h->title }}</a>
                            <span><span class="status-pill {{ $h->status_pill }}">{{ $h->status_label }}</span> {{ $h->created_at->format('j M Y') }}</span></div>
                    @endforeach
                </x-cb.card>
            @endif
        </div>

        <div class="col-xl-5">
            @if ($order->isActive())
                @can('Manage maintenance')
                    <x-cb.card title="Assign" icon="ri-user-settings-line">
                        <form method="POST" action="{{ route('maintenance.assign', $order) }}" class="rm-form row g-2" data-once>@csrf
                            <div class="col-7"><select name="assigned_to" class="form-select" aria-label="Technician"><option value="">Nobody</option>@foreach ($technicians as $t)<option value="{{ $t->id }}" @selected($order->assigned_to === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
                            <div class="col-5"><input type="datetime-local" name="due_at" class="form-control" value="{{ $order->due_at?->format('Y-m-d\TH:i') }}" aria-label="Due"></div>
                            <div class="col-12"><button class="action-btn btn-open"><i class="ri-save-line"></i> Save</button></div>
                        </form>
                    </x-cb.card>
                @endcan
                @if ($canWork)
                    <x-cb.card title="Work on this job" icon="ri-hammer-line">
                        <div class="d-flex gap-2 flex-wrap mb-3">
                            @if ($order->status !== 'in_progress')<form method="POST" action="{{ route('maintenance.start', $order) }}" data-once>@csrf<button class="action-btn btn-open"><i class="ri-play-line"></i> {{ $order->status === 'on_hold' ? 'Resume' : 'Start' }}</button></form>@endif
                            <button type="button" class="action-btn btn-more" data-bs-toggle="collapse" data-bs-target="#holdForm"><i class="ri-pause-line"></i> On hold</button>
                        </div>
                        <form method="POST" action="{{ route('maintenance.hold', $order) }}" class="rm-form collapse mb-3" id="holdForm" data-once>@csrf
                            <div class="input-group"><input type="text" name="reason" class="form-control" maxlength="255" required placeholder="e.g. Waiting for compressor part"><button class="action-btn btn-more">Hold</button></div>
                        </form>
                        <form method="POST" action="{{ route('maintenance.complete', $order) }}" class="rm-form row g-2 border-top pt-3" enctype="multipart/form-data" data-once>@csrf
                            <div class="col-12"><label for="cpNote">What was done</label><textarea name="completion_note" id="cpNote" class="form-control" rows="2" maxlength="1000" required></textarea></div>
                            <div class="col-6"><label for="cpCost">Cost (parts + labour)</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" name="cost" id="cpCost" class="form-control" min="0" step="0.01"></div></div>
                            <div class="col-6"><label for="cpPhotos">After photos</label><input type="file" name="photos[]" id="cpPhotos" class="form-control" accept="image/*" multiple capture="environment"></div>
                            <div class="col-12"><button class="action-btn btn-open"><i class="ri-check-double-line"></i> Mark as fixed</button>
                                @if ($order->blocks_room && $order->room)<div class="form-text">Room {{ $order->room->number }} will go to housekeeping for cleaning, then back on sale.</div>@endif</div>
                        </form>
                    </x-cb.card>
                @endif
                @can('Manage maintenance')
                    <form method="POST" action="{{ route('maintenance.cancel', $order) }}" class="rm-form cb-card p-3" data-once>@csrf
                        <label for="cnReason">Cancel this job</label>
                        <div class="input-group"><input type="text" name="reason" id="cnReason" class="form-control" maxlength="255" required placeholder="e.g. Reported twice, see WO-000012"><button class="action-btn btn-more">Cancel job</button></div>
                    </form>
                @endcan
            @endif
        </div>
    </div>

</div>
</div>
</div>
@endsection
