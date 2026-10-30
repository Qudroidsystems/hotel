@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php use App\Models\Facility\FacilityDevice; $canManage = auth()->user()->can('Manage room equipment'); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Room & Area Equipment" icon="ri-plug-line" subtitle="What is in each room and the hotel's other areas, and whether it is on or off — shown on the call console's board">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('it.console.index') }}"><i class="ri-headphone-line"></i> Open the console</a>
            @if ($canManage)
                <form method="POST" action="{{ route('it.facilities.standard') }}" class="d-inline" data-once>@csrf<button class="cb-hero-btn" type="submit" title="Air conditioner, lights, TV, minibar, water heater and door lock for every room that has nothing listed"><i class="ri-magic-line"></i> Add the usual to every room</button></form>
                <button type="button" class="cb-hero-btn" data-device="new"><i class="ri-add-line"></i> Add equipment</button>
            @endif
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @include('it.partials.nav')

    @if (session('hub_key'))
        <div class="cb-banner info"><i class="ri-key-2-line"></i><div><strong>Copy this hub key now — it is shown only once.</strong><br>
            <code style="font-size:15px;user-select:all">{{ session('hub_key') }}</code><br>
            <span class="small">The hardware sends it as <code>Authorization: Bearer …</code> to <code>{{ url('/api/v1/facility/report') }}</code>.</span></div></div>
    @endif

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="place" class="cb-select" aria-label="Where" onchange="this.form.submit()">
                <option value="">Rooms and areas</option><option value="rooms" @selected($place === 'rooms')>Rooms only</option><option value="areas" @selected($place === 'areas')>Other areas only</option>
            </select>
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Room, area, name or code…" aria-label="Search"></div>
            <button class="action-btn btn-open"><i class="ri-search-line"></i> Search</button>
        </form>
        @if ($devices->isEmpty())
            <div class="empty-state py-5"><i class="ri-plug-line"></i><h6>No equipment listed yet</h6>
                <p class="small text-muted">Press “Add the usual to every room”, then add the generator, pumps, lifts and other areas’ equipment.</p></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Where</th><th>Equipment</th><th>Code (for hardware)</th><th>State</th><th>Fed by</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($devices as $d)
                            @php $s = $d->live_state; @endphp
                            <tr style="{{ $d->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Where" class="fw-semibold">{{ $d->place }}</td>
                                <td data-label="Equipment"><i class="{{ $d->kind_icon }} text-muted"></i> {{ $d->name }}
                                    <div class="small text-muted">{{ $d->kind_label }}@if ($d->is_critical) · <span class="text-danger">critical</span>@endif @if ($d->should_be_on) · always on @endif</div></td>
                                <td data-label="Code"><span class="rs-ref">{{ $d->code }}</span></td>
                                <td data-label="State"><span class="status-pill {{ ['on' => 'st-success', 'off' => 'st-muted', 'fault' => 'st-danger', 'offline' => 'st-warning'][$s] }}">{{ FacilityDevice::STATES[$s][0] }}</span>
                                    @if ($d->reading)<span class="small text-muted ms-1">{{ $d->reading }}</span>@endif
                                    @if ($d->state_changed_at)<div class="small text-muted">since {{ $d->state_changed_at->diffForHumans() }}</div>@endif</td>
                                <td data-label="Fed by" class="small">{{ $d->source === 'hub' ? 'Hardware' . ($d->last_seen_at ? ' · ' . $d->last_seen_at->diffForHumans() : '') : 'Staff' }}</td>
                                <td class="text-end text-nowrap">@if ($canManage)
                                    @foreach (['on' => ['ri-toggle-fill', 'btn-go'], 'off' => ['ri-toggle-line', 'btn-more']] as $to => [$ic, $cls])
                                        @if ($d->state !== $to)<form method="POST" action="{{ route('it.facilities.state', $d) }}" class="d-inline">@csrf<input type="hidden" name="state" value="{{ $to }}"><button class="action-btn {{ $cls }}" title="Switch {{ $to }}"><i class="{{ $ic }}"></i> {{ ucfirst($to) }}</button></form>@endif
                                    @endforeach
                                    <button type="button" class="action-btn btn-more" data-device="{{ json_encode(['id' => $d->id, 'room_id' => $d->room_id, 'area' => $d->area, 'name' => $d->name, 'kind' => $d->kind, 'code' => $d->code, 'is_critical' => $d->is_critical, 'should_be_on' => $d->should_be_on, 'is_active' => $d->is_active, 'notes' => $d->notes]) }}"><i class="ri-pencil-line"></i></button>
                                    <form method="POST" action="{{ route('it.facilities.destroy', $d) }}" class="d-inline" onsubmit="return confirm('Remove {{ $d->name }} ({{ $d->place }})?');">@csrf @method('DELETE')<button class="action-btn btn-danger-soft" title="Remove"><i class="ri-delete-bin-line"></i></button></form>
                                @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-2">{{ $devices->links() }}</div>
        @endif
    </div>

    @if ($canManage)
    <div class="cb-card mt-3">
        <div class="cb-card-header"><h5><i class="ri-cpu-line"></i>Hardware hubs <span class="cb-count">{{ $hubs->where('is_active', true)->count() }}</span></h5></div>
        <p class="small text-muted mb-2">A building-management system, IoT gateway or smart-room controller can report on/off states itself. Give it a key, then it sends
            <code>POST {{ url('/api/v1/facility/report') }}</code> with <code>{"devices":[{"code":"R101-AC","state":"on","reading":"22°C"}]}</code> at least every 15 minutes.
            States can be on / off / fault (or 1 / 0, running / stopped, alarm). <code>GET {{ url('/api/v1/facility/devices') }}</code> lists every code. A device the hub stops reporting shows “No signal”.</p>
        <form method="POST" action="{{ route('it.facilities.hubs.store') }}" class="cb-toolbar">@csrf
            <input name="name" class="form-control" style="max-width:320px" maxlength="78" required placeholder="e.g. Building management system">
            <button class="action-btn btn-go"><i class="ri-key-2-line"></i> Create a key</button>
        </form>
        @foreach ($hubs as $h)
            <div class="d-flex justify-content-between align-items-center border-top py-2 small" style="{{ $h->is_active ? '' : 'opacity:.55' }}">
                <div><strong>{{ $h->name }}</strong> <span class="rs-ref">…{{ $h->token_hint }}</span>
                    <div class="text-muted">{{ $h->is_active ? ($h->last_seen_at ? 'Last reported ' . $h->last_seen_at->diffForHumans() . ($h->last_ip ? ' from ' . $h->last_ip : '') : 'Has not reported yet') : 'Revoked' }}</div></div>
                @if ($h->is_active)<form method="POST" action="{{ route('it.facilities.hubs.revoke', $h) }}" onsubmit="return confirm('Stop {{ $h->name }} reporting?');">@csrf<button class="action-btn btn-danger-soft"><i class="ri-forbid-line"></i> Revoke</button></form>@endif
            </div>
        @endforeach
    </div>
    @endif

</div>
</div>
</div>

@if ($canManage)
<datalist id="fd-areas">@foreach (FacilityDevice::AREAS as $a)<option value="{{ $a }}">@endforeach</datalist>
<div class="modal fade" id="deviceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content rm-form" method="POST" action="{{ route('it.facilities.store') }}" id="deviceForm">
            @csrf
            <input type="hidden" name="_method" value="POST" id="fdMethod">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-plug-line me-1"></i> <span id="fdTitle">Add equipment</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                @if ($errors->any())<div class="col-12"><div class="cb-banner danger mb-0"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div></div>@endif
                <div class="col-sm-6"><label for="f_room_id">Room</label><select name="room_id" id="f_room_id" class="form-select"><option value="">Not a room (another area)</option>@foreach ($rooms as $r)<option value="{{ $r->id }}">Room {{ $r->number }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label for="f_area">Area (when not a room)</label><input name="area" id="f_area" list="fd-areas" class="form-control" maxlength="60" placeholder="Lobby, Kitchen, Pool…"></div>
                <div class="col-sm-6"><label for="f_name">Name <span class="text-danger">*</span></label><input name="name" id="f_name" class="form-control" maxlength="80" required placeholder="e.g. Generator 1, Lobby lights"></div>
                <div class="col-sm-6"><label for="f_kind">Kind <span class="text-danger">*</span></label><select name="kind" id="f_kind" class="form-select">@foreach (FacilityDevice::KINDS as $k => [$label])<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label for="f_code">Code for hardware</label><input name="code" id="f_code" class="form-control" maxlength="60" placeholder="Made for you if left empty (R101-AC)"></div>
                <div class="col-sm-6 d-flex flex-column justify-content-end gap-1">
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_critical" value="1" id="f_is_critical"> <span class="form-check-label">Critical — off or faulty raises an alarm</span></label>
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="should_be_on" value="1" id="f_should_be_on"> <span class="form-check-label">Should always be on</span></label>
                    <label class="form-check mb-0" id="f_active_wrap"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="f_is_active" checked> <span class="form-check-label">Shown on the board</span></label>
                </div>
                <div class="col-12"><label for="f_notes">Notes</label><input name="notes" id="f_notes" class="form-control" maxlength="255"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('deviceForm'), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('deviceModal'));
    const storeUrl = @json(route('it.facilities.store')), updateUrl = @json(route('it.facilities.update', '__ID__'));
    document.querySelectorAll('[data-device]').forEach(btn => btn.addEventListener('click', () => {
        const d = btn.dataset.device === 'new' ? null : JSON.parse(btn.dataset.device);
        form.reset();
        form.action = d ? updateUrl.replace('__ID__', d.id) : storeUrl;
        document.getElementById('fdMethod').value = d ? 'PUT' : 'POST';
        document.getElementById('fdTitle').textContent = d ? 'Edit ' + d.name : 'Add equipment';
        document.getElementById('f_active_wrap').style.display = d ? '' : 'none';
        if (d) {
            ['room_id', 'area', 'name', 'kind', 'code', 'notes'].forEach(k => document.getElementById('f_' + k).value = d[k] ?? '');
            ['is_critical', 'should_be_on', 'is_active'].forEach(k => document.getElementById('f_' + k).checked = !!d[k]);
        }
        modal.show();
    }));
    @if ($errors->any()) modal.show(); @endif
});
</script>
@endif
@endsection
