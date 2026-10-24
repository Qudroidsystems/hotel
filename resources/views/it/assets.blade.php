@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php use App\Models\It\ItAsset; $canManage = auth()->user()->can('Manage IT monitoring'); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="IT Equipment" icon="ri-router-line" subtitle="Routers, switches, access points, servers and other devices — where they are, how to reach them, and their warranty">
        <x-slot:actions>
            @if ($canManage)<button type="button" class="cb-hero-btn" data-asset="new"><i class="ri-add-line"></i> Add equipment</button>@endif
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @include('it.partials.nav')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Devices in use" :value="$stats['total']" icon="ri-router-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Access points" :value="$stats['types']['access_point'] ?? 0" icon="ri-wifi-line" accent="green" :hint="($stats['types']['switch'] ?? 0) . ' switches · ' . ($stats['types']['router'] ?? 0) . ' routers'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Warranty ending ≤ 60 days" :value="$stats['expiring']" icon="ri-shield-check-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Out of warranty" :value="$stats['expired']" icon="ri-shield-cross-line" accent="rose" /></div>
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="type" class="cb-select" aria-label="Type" onchange="this.form.submit()"><option value="">All kinds</option>@foreach (ItAsset::TYPES as $k => [$label])<option value="{{ $k }}" @selected($type === $k)>{{ $label }}</option>@endforeach</select>
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $q }}" placeholder="Name, IP, serial, location…" aria-label="Search"></div>
            <button class="action-btn btn-open"><i class="ri-search-line"></i> Search</button>
        </form>
        @if ($assets->isEmpty())
            <div class="empty-state py-5"><i class="ri-router-line"></i><h6>No equipment recorded</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Equipment</th><th>Network</th><th>Where</th><th>Details</th><th>Warranty</th><th>Monitored</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($assets as $a)
                            @php $mon = $a->monitors->where('is_active', true); $worst = $mon->contains('status', 'down') ? 'down' : ($mon->contains('status', 'degraded') ? 'degraded' : ($mon->isNotEmpty() ? 'up' : null)); @endphp
                            <tr style="{{ $a->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Equipment"><span class="fw-semibold"><i class="{{ $a->type_icon }} text-muted"></i> {{ $a->name }}</span><div class="small text-muted">{{ $a->type_label }}@unless ($a->is_active) · <span class="status-pill st-muted">Retired</span>@endunless</div></td>
                                <td data-label="Network" class="small"><span class="rs-ref">{{ $a->ip_address ?: '—' }}</span>@if ($a->mac_address)<div class="text-muted">{{ $a->mac_address }}</div>@endif</td>
                                <td data-label="Where" class="small">{{ $a->location ?: '—' }}</td>
                                <td data-label="Details" class="small">{{ $a->make_model }}@if ($a->serial_number)<div class="text-muted">S/N {{ $a->serial_number }}</div>@endif @if ($a->firmware)<div class="text-muted">Firmware {{ $a->firmware }}</div>@endif</td>
                                <td data-label="Warranty" class="small">@if ($a->warranty_until)<span class="{{ $a->warrantyEnded() ? 'text-danger' : ($a->warranty_until->lte(today()->addDays(60)) ? 'text-warning fw-semibold' : '') }}">{{ $a->warrantyEnded() ? 'Ended' : 'Until' }} {{ $a->warranty_until->format('j M Y') }}</span>@else — @endif</td>
                                <td data-label="Monitored" class="small">@if ($worst)<span class="status-pill {{ \App\Models\It\ItMonitor::STATUSES[$worst][1] }}">{{ \App\Models\It\ItMonitor::STATUSES[$worst][0] }}</span>@else<span class="text-muted">No</span>@endif
                                    @if ($a->tickets_count)<div class="text-muted">{{ $a->tickets_count }} ticket{{ $a->tickets_count === 1 ? '' : 's' }}</div>@endif</td>
                                <td class="text-end text-nowrap">@if ($canManage)
                                    <button type="button" class="action-btn btn-more" data-asset="{{ json_encode(['id' => $a->id, 'name' => $a->name, 'type' => $a->type, 'ip_address' => $a->ip_address, 'mac_address' => $a->mac_address, 'location' => $a->location, 'make_model' => $a->make_model, 'serial_number' => $a->serial_number, 'firmware' => $a->firmware, 'purchased_on' => $a->purchased_on?->toDateString(), 'warranty_until' => $a->warranty_until?->toDateString(), 'notes' => $a->notes, 'is_active' => $a->is_active]) }}"><i class="ri-pencil-line"></i></button>
                                    <form method="POST" action="{{ route('it.assets.destroy', $a) }}" class="d-inline" onsubmit="return confirm('Remove {{ $a->name }}?');">@csrf @method('DELETE')<button class="action-btn btn-danger-soft" title="Remove"><i class="ri-delete-bin-line"></i></button></form>
                                @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>
</div>
</div>

@if ($canManage)
<div class="modal fade" id="assetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content rm-form" method="POST" action="{{ route('it.assets.store') }}" id="assetForm">
            @csrf
            <input type="hidden" name="_method" value="POST" id="asMethod">
            <input type="hidden" name="asset_id" value="{{ old('asset_id') }}" id="asId">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-router-line me-1"></i> <span id="asTitle">Add equipment</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                @if ($errors->any())<div class="col-12"><div class="cb-banner danger mb-0"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div></div>@endif
                <div class="col-sm-6"><label for="a_name">Name <span class="text-danger">*</span></label><input name="name" id="a_name" class="form-control" maxlength="120" required value="{{ old('name') }}" placeholder="e.g. AP-Floor2-East"></div>
                <div class="col-sm-6"><label for="a_type">Kind <span class="text-danger">*</span></label><select name="type" id="a_type" class="form-select">@foreach (ItAsset::TYPES as $k => [$label])<option value="{{ $k }}" @selected(old('type') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-4"><label for="a_ip_address">IP address</label><input name="ip_address" id="a_ip_address" class="form-control" maxlength="64" value="{{ old('ip_address') }}" placeholder="192.168.1.10"></div>
                <div class="col-sm-4"><label for="a_mac_address">MAC address</label><input name="mac_address" id="a_mac_address" class="form-control" maxlength="32" value="{{ old('mac_address') }}" placeholder="AA:BB:CC:DD:EE:FF"></div>
                <div class="col-sm-4"><label for="a_location">Location</label><input name="location" id="a_location" class="form-control" maxlength="120" value="{{ old('location') }}" placeholder="Server room, 2nd floor corridor…"></div>
                <div class="col-sm-4"><label for="a_make_model">Make / model</label><input name="make_model" id="a_make_model" class="form-control" maxlength="120" value="{{ old('make_model') }}"></div>
                <div class="col-sm-4"><label for="a_serial_number">Serial number</label><input name="serial_number" id="a_serial_number" class="form-control" maxlength="80" value="{{ old('serial_number') }}"></div>
                <div class="col-sm-4"><label for="a_firmware">Firmware</label><input name="firmware" id="a_firmware" class="form-control" maxlength="60" value="{{ old('firmware') }}"></div>
                <div class="col-sm-4"><label for="a_purchased_on">Bought</label><input type="date" name="purchased_on" id="a_purchased_on" class="form-control" value="{{ old('purchased_on') }}"></div>
                <div class="col-sm-4"><label for="a_warranty_until">Warranty until</label><input type="date" name="warranty_until" id="a_warranty_until" class="form-control" value="{{ old('warranty_until') }}"></div>
                <div class="col-sm-4 d-flex align-items-end" id="a_active_wrap"><label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="a_is_active" checked> <span class="form-check-label">In use</span></label></div>
                <div class="col-12"><label for="a_notes">Notes</label><textarea name="notes" id="a_notes" class="form-control" rows="2" maxlength="1000" placeholder="Configuration notes, admin URL, support contact… (never store passwords here)">{{ old('notes') }}</textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('assetForm'), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('assetModal'));
    const storeUrl = @json(route('it.assets.store')), updateUrl = @json(route('it.assets.update', '__ID__'));
    const keys = ['name', 'type', 'ip_address', 'mac_address', 'location', 'make_model', 'serial_number', 'firmware', 'purchased_on', 'warranty_until', 'notes'];
    document.querySelectorAll('[data-asset]').forEach(btn => btn.addEventListener('click', () => {
        const a = btn.dataset.asset === 'new' ? null : JSON.parse(btn.dataset.asset);
        form.reset();
        form.action = a ? updateUrl.replace('__ID__', a.id) : storeUrl;
        document.getElementById('asMethod').value = a ? 'PUT' : 'POST';
        document.getElementById('asId').value = a ? a.id : '';
        document.getElementById('asTitle').textContent = a ? 'Edit ' + a.name : 'Add equipment';
        document.getElementById('a_active_wrap').style.display = a ? '' : 'none';
        if (a) { keys.forEach(k => document.getElementById('a_' + k).value = a[k] ?? ''); document.getElementById('a_is_active').checked = !!a.is_active; }
        modal.show();
    }));
    @if ($errors->any())
        const oldId = document.getElementById('asId').value;
        if (oldId) { form.action = updateUrl.replace('__ID__', oldId); document.getElementById('asMethod').value = 'PUT'; }
        modal.show();
    @endif
});
</script>
@endif
@endsection
