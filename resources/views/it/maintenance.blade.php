@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php $canManage = auth()->user()->can('Manage IT monitoring'); $pills = ['planned' => 'st-info', 'running' => 'st-warning', 'done' => 'st-success', 'cancelled' => 'st-muted']; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Planned Maintenance" icon="ri-tools-line" subtitle="Tell staff before you take something offline. Alerts for the selected monitors are held while the work runs.">
        <x-slot:actions>@if ($canManage)<button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#mwModal"><i class="ri-add-line"></i> Plan maintenance</button>@endif</x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @include('it.partials.nav')

    <x-cb.card title="Coming up" icon="ri-calendar-event-line" :count="$upcoming->count()" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Work</th><th>When</th><th>Staff will notice</th><th>Alerts held for</th><th></th></tr></thead>
                <tbody>
                    @forelse ($upcoming as $w)
                        <tr>
                            <td data-label="Work"><span class="fw-semibold">{{ $w->title }}</span> <span class="status-pill {{ $pills[$w->state] }}">{{ ucfirst($w->state) }}</span>
                                @if ($w->description)<div class="small text-muted" style="white-space:pre-line">{{ $w->description }}</div>@endif
                                <div class="small text-muted">Planned by {{ $w->createdBy?->name ?? '—' }}</div></td>
                            <td data-label="When" class="small text-nowrap">{{ $w->starts_at->format('D j M, g:i A') }}<div>to {{ $w->ends_at->format($w->ends_at->isSameDay($w->starts_at) ? 'g:i A' : 'D j M, g:i A') }}</div></td>
                            <td data-label="Staff will notice" class="small">{{ $w->affects ?: '—' }}</td>
                            <td data-label="Alerts held" class="small">{{ $monitors->whereIn('id', $w->monitor_ids ?? [])->pluck('name')->join(', ') ?: 'None' }}</td>
                            <td class="text-end">@if ($canManage)
                                <form method="POST" action="{{ route('it.maintenance.cancel', $w) }}" onsubmit="return confirm('{{ $w->isRunning() ? 'End this maintenance now?' : 'Cancel this maintenance?' }}');">@csrf
                                    <button class="action-btn btn-danger-soft">{{ $w->isRunning() ? 'End now' : 'Cancel' }}</button></form>
                            @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">Nothing planned.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-cb.card>

    <x-cb.card title="Past" icon="ri-history-line" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Work</th><th>When</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($past as $w)
                        <tr><td data-label="Work">{{ $w->title }}<div class="small text-muted">{{ $w->affects }}</div></td>
                            <td data-label="When" class="small">{{ $w->starts_at->format('D j M Y, g:i A') }} – {{ $w->ends_at->format('g:i A') }}</td>
                            <td data-label="Status"><span class="status-pill {{ $pills[$w->state] }}">{{ ucfirst($w->state) }}</span></td></tr>
                    @empty
                        <tr><td colspan="3" class="text-muted">No past maintenance.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-cb.card>

</div>
</div>
</div>

@if ($canManage)
<div class="modal fade" id="mwModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content rm-form" method="POST" action="{{ route('it.maintenance.store') }}">@csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-tools-line me-1"></i> Plan maintenance</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                @if ($errors->any())<div class="col-12"><div class="cb-banner danger mb-0"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div></div>@endif
                <div class="col-12"><label for="mw_title">What <span class="text-danger">*</span></label><input name="title" id="mw_title" class="form-control" maxlength="150" required value="{{ old('title') }}" placeholder="e.g. Router firmware update"></div>
                <div class="col-sm-6"><label for="mw_starts">Starts <span class="text-danger">*</span></label><input type="datetime-local" name="starts_at" id="mw_starts" class="form-control" required value="{{ old('starts_at', now()->addDay()->setTime(2, 0)->format('Y-m-d\TH:i')) }}"></div>
                <div class="col-sm-6"><label for="mw_ends">Ends <span class="text-danger">*</span></label><input type="datetime-local" name="ends_at" id="mw_ends" class="form-control" required value="{{ old('ends_at', now()->addDay()->setTime(3, 0)->format('Y-m-d\TH:i')) }}"></div>
                <div class="col-12"><label for="mw_affects">What staff will notice</label><input name="affects" id="mw_affects" class="form-control" maxlength="255" value="{{ old('affects') }}" placeholder="e.g. No internet or Wi-Fi for about 15 minutes"></div>
                <div class="col-12"><label for="mw_desc">Details</label><textarea name="description" id="mw_desc" class="form-control" rows="2" maxlength="2000">{{ old('description') }}</textarea></div>
                <div class="col-12"><label>Hold alerts for</label>
                    <div class="border rounded p-2" style="max-height:200px;overflow-y:auto">
                        @forelse ($monitors as $m)
                            <label class="form-check d-block"><input type="checkbox" class="form-check-input" name="monitor_ids[]" value="{{ $m->id }}" @checked(in_array($m->id, old('monitor_ids', [])))> <span class="form-check-label">{{ $m->name }}</span></label>
                        @empty
                            <span class="small text-muted">No monitors set up.</span>
                        @endforelse
                    </div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Schedule</button></div>
        </form>
    </div>
</div>
@if ($errors->any())<script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('mwModal')).show());</script>@endif
@endif
@endsection
