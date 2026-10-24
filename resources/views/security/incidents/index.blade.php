@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $q = request()->only(['show', 'severity', 'type']); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-alarm-warning-line" subtitle="Security problems, accidents and complaints, from the first report to how they were resolved.">
        <x-slot:actions>
            @canany(['Report incidents', 'Manage incidents'])
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#incidentModal"><i class="ri-add-line"></i> Report an incident</button>
            @endcanany
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Open incidents" :value="$stats['active']" icon="ri-alarm-warning-line" accent="teal" :hint="$stats['unassigned'] . ' not assigned'" /></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('incidents.index', ['severity' => 'critical']) }}"><x-cb.stat label="High or critical" :value="$stats['serious']" icon="ri-fire-line" accent="rose" /></a></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Reported this month" :value="$stats['month']" icon="ri-file-warning-line" accent="amber" :hint="$stats['top_type'] ? 'Most: ' . \App\Models\Incident::TYPES[$stats['top_type']][0] : null" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Average time to resolve" :value="$stats['hours'] !== null ? $stats['hours'] . 'h' : '—'" icon="ri-time-line" accent="green" hint="This month" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="cb-card">
                <form method="GET" class="cb-toolbar">
                    <select name="show" class="cb-select" aria-label="Show" onchange="this.form.submit()">
                        @foreach (['active' => 'Open & investigating', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All'] as $k => $label)<option value="{{ $k }}" @selected($show === $k)>{{ $label }}</option>@endforeach
                    </select>
                    <select name="severity" class="cb-select" aria-label="Severity" onchange="this.form.submit()">
                        <option value="">Any severity</option>
                        @foreach (\App\Models\Incident::SEVERITIES as $k => [$label])<option value="{{ $k }}" @selected($severity === $k)>{{ $label }}</option>@endforeach
                    </select>
                    <select name="type" class="cb-select" aria-label="Type" onchange="this.form.submit()">
                        <option value="">Any kind</option>
                        @foreach (\App\Models\Incident::TYPES as $k => [$label])<option value="{{ $k }}" @selected($type === $k)>{{ $label }}</option>@endforeach
                    </select>
                </form>
                @if (! $seeAll)<div class="px-3 pb-2 small text-muted">Showing incidents you reported or were assigned.</div>@endif
                @if ($incidents->isEmpty())
                    <div class="empty-state py-5"><i class="ri-shield-check-line"></i><h6>No incidents here</h6></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Incident</th><th>Where</th><th>Severity</th><th>Status</th><th>Assigned</th></tr></thead>
                            <tbody>
                                @foreach ($incidents as $i)
                                    <tr>
                                        <td data-label="Incident"><a href="{{ route('incidents.show', $i) }}" class="fw-semibold text-reset"><i class="{{ $i->type_icon }} text-muted"></i> {{ $i->title }}</a>
                                            <div class="small text-muted">{{ $i->number }} · {{ $i->type_label }} · {{ $i->occurred_at->format('j M, g:i A') }}{{ $i->reporter ? ' · ' . $i->reporter->name : '' }}</div></td>
                                        <td data-label="Where">{{ $i->where }}</td>
                                        <td data-label="Severity"><span class="status-pill {{ $i->severity_pill }}">{{ $i->severity_label }}</span></td>
                                        <td data-label="Status"><span class="status-pill {{ $i->status_pill }}">{{ $i->status_label }}</span></td>
                                        <td data-label="Assigned">{{ $i->assignee?->name ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $incidents->links() }}</div>
                @endif
            </div>
        </div>

        <div class="col-xl-4">
            <x-cb.card title="Emergency contacts" icon="ri-phone-line" :count="$contacts->count()">
                @forelse ($contacts as $c)
                    <div class="d-flex justify-content-between align-items-start py-2 border-bottom gap-2">
                        <div><div class="fw-semibold">{{ $c->name }}</div>
                            <div class="small text-muted">{{ $c->role }}{{ $c->notes ? ' · ' . $c->notes : '' }}</div></div>
                        <div class="text-end text-nowrap">
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $c->phone) }}" class="fw-semibold">{{ $c->phone }}</a>
                            @if ($c->alt_phone)<div class="small"><a href="tel:{{ preg_replace('/[^0-9+]/', '', $c->alt_phone) }}">{{ $c->alt_phone }}</a></div>@endif
                            @can('Manage incidents')
                                <div class="mt-1">
                                    <button type="button" class="btn btn-link btn-sm p-0" data-bs-toggle="collapse" data-bs-target="#ec{{ $c->id }}">Edit</button>
                                    <form method="POST" action="{{ route('incidents.contacts.destroy', $c) }}" class="d-inline" data-once>@csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 text-danger ms-2">Remove</button></form>
                                </div>
                            @endcan
                        </div>
                    </div>
                    @can('Manage incidents')
                        <form method="POST" action="{{ route('incidents.contacts.update', $c) }}" class="collapse rm-form row g-2 py-2 border-bottom" id="ec{{ $c->id }}" data-once>@csrf @method('PUT')
                            @include('security.incidents.partials.contact-fields', ['c' => $c])
                            <div class="col-12"><button class="action-btn btn-open"><i class="ri-save-line"></i> Save</button></div>
                        </form>
                    @endcan
                @empty
                    <div class="small text-muted">No numbers yet.@can('Manage incidents') Add the fire service, police, nearest hospital and the duty manager.@endcan</div>
                @endforelse
                @can('Manage incidents')
                    <button type="button" class="action-btn btn-more mt-3" data-bs-toggle="collapse" data-bs-target="#ecNew"><i class="ri-add-line"></i> Add contact</button>
                    <form method="POST" action="{{ route('incidents.contacts.store') }}" class="collapse rm-form row g-2 mt-2" id="ecNew" data-once>@csrf
                        @include('security.incidents.partials.contact-fields', ['c' => null])
                        <div class="col-12"><button class="action-btn btn-open"><i class="ri-add-line"></i> Add</button></div>
                    </form>
                @endcan
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>

@canany(['Report incidents', 'Manage incidents'])
<div class="modal fade" id="incidentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content rm-form" method="POST" action="{{ route('incidents.store') }}" enctype="multipart/form-data" data-once>
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-alarm-warning-line me-1"></i> Report an incident</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-6"><label for="inType">What happened</label>
                    <select name="type" id="inType" class="form-select" required>@foreach (\App\Models\Incident::TYPES as $k => [$label])<option value="{{ $k }}" @selected(old('type') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-md-6"><label for="inSeverity">How serious</label>
                    <select name="severity" id="inSeverity" class="form-select" required>@foreach (array_reverse(\App\Models\Incident::SEVERITIES) as $k => [$label])<option value="{{ $k }}" @selected(old('severity', 'medium') === $k)>{{ $label }}</option>@endforeach</select>
                    <div class="form-text">High and critical incidents are flagged to management.</div></div>
                <div class="col-12"><label for="inTitle">Short summary</label><input type="text" name="title" id="inTitle" class="form-control" maxlength="150" required value="{{ old('title') }}" placeholder="e.g. Guest's phone missing from room, fight at pool bar"></div>
                <div class="col-12"><label for="inDesc">What happened, in detail</label><textarea name="description" id="inDesc" class="form-control" rows="4" maxlength="5000" required>{{ old('description') }}</textarea></div>
                <div class="col-md-3"><label for="inRoom">Room</label>
                    <select name="room_id" id="inRoom" class="form-select"><option value="">—</option>@foreach ($rooms as $r)<option value="{{ $r->id }}" @selected((int) old('room_id') === $r->id)>{{ $r->number }}</option>@endforeach</select></div>
                <div class="col-md-5"><label for="inLocation">Or where</label><input type="text" name="location" id="inLocation" class="form-control" maxlength="120" value="{{ old('location') }}" placeholder="e.g. Car park, pool bar"></div>
                <div class="col-md-4"><label for="inWhen">When</label><input type="datetime-local" name="occurred_at" id="inWhen" class="form-control" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" max="{{ now()->format('Y-m-d\TH:i') }}"></div>
                <div class="col-md-8"><label for="inPeople">People involved <span class="text-muted fw-normal">(names, witnesses)</span></label><input type="text" name="people_involved" id="inPeople" class="form-control" maxlength="500" value="{{ old('people_involved') }}"></div>
                <div class="col-md-4"><label for="inPolice">Police reference</label><input type="text" name="police_reference" id="inPolice" class="form-control" maxlength="60" value="{{ old('police_reference') }}"></div>
                <div class="col-md-6"><label for="inPhotos">Photos <span class="text-muted fw-normal">(up to 4)</span></label><input type="file" name="photos[]" id="inPhotos" class="form-control" accept="image/*" multiple capture="environment"></div>
                @can('Manage incidents')
                    <div class="col-md-6"><label for="inAssign">Assign to</label>
                        <select name="assigned_to" id="inAssign" class="form-select"><option value="">Not yet</option>@foreach ($officers as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></div>
                @endcan
            </div>
            <div class="modal-footer"><button type="button" class="action-btn btn-more" data-bs-dismiss="modal">Cancel</button><button type="submit" class="action-btn btn-open"><i class="ri-send-plane-line"></i> Report</button></div>
        </form>
    </div>
</div>
@if ($errors->any() && old('title'))
<script>document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('incidentModal')).show(); });</script>
@endif
@endcanany
@endsection
