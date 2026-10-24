@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-shield-user-line" subtitle="Everyone who comes in without a room: sign them in, give them a pass, sign them out when they leave.">
        <x-slot:actions>
            @can('Manage visitors')
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#visitorModal"><i class="ri-user-add-line"></i> Sign in a visitor</button>
            @endcan
            <a class="cb-hero-btn" href="{{ route('visitors.check') }}"><i class="ri-qr-scan-2-line"></i> Check a pass</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($stats['overstayed'])
        <div class="cb-banner warning"><i class="ri-time-line"></i><div>{{ $stats['overstayed'] }} {{ Str::plural('visitor', $stats['overstayed']) }} still on site after their pass ran out. Sign them out or extend the pass.</div></div>
    @endif

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="On site now" :value="$stats['on_site']" icon="ri-user-follow-line" accent="teal" :hint="$stats['people'] . ' ' . Str::plural('person', $stats['people']) . ' in total'" /></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('visitors.index') }}"><x-cb.stat label="Pass expired" :value="$stats['overstayed']" icon="ri-alarm-warning-line" accent="rose" /></a></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Contractors & deliveries in" :value="$stats['contractors']" icon="ri-tools-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('visitors.index', ['show' => 'today']) }}"><x-cb.stat label="Signed in today" :value="$stats['today']" icon="ri-door-open-line" accent="green" /></a></div>
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="show" class="cb-select" aria-label="Show" onchange="this.form.submit()">
                @foreach (['on_site' => 'On site now', 'today' => 'Today', 'all' => 'All visits'] as $k => $label)<option value="{{ $k }}" @selected($show === $k)>{{ $label }}</option>@endforeach
            </select>
            <input type="search" name="q" class="form-control" style="max-width: 280px" value="{{ $search }}" placeholder="Name, phone, company, plate…" aria-label="Search">
        </form>
        @if ($visitors->isEmpty())
            <div class="empty-state py-5"><i class="ri-shield-user-line"></i><h6>{{ $show === 'on_site' ? 'No visitors on site' : 'No visits found' }}</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Visitor</th><th>Here for</th><th>Signed in</th><th>Pass until</th><th>Left</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($visitors as $v)
                            <tr>
                                <td data-label="Visitor"><span class="fw-semibold">{{ $v->full_name }}</span>@if ($v->party_size > 1) <span class="text-muted small">+{{ $v->party_size - 1 }}</span>@endif
                                    <div class="small text-muted">{{ collect([$v->company, $v->phone, $v->vehicle_plate ? 'car ' . $v->vehicle_plate : null])->filter()->implode(' · ') ?: ($v->anonymised_at ? 'details cleared' : '—') }}</div></td>
                                <td data-label="Here for">{{ $v->visiting }}<div class="small text-muted">{{ $v->purpose_label }}</div></td>
                                <td data-label="Signed in">{{ $v->signed_in_at->format('D j M, g:i A') }}<div class="small text-muted">{{ $v->signedInBy?->name }}</div></td>
                                <td data-label="Pass until" class="{{ $v->isOverstayed() ? 'text-danger fw-semibold' : '' }}">{{ $v->expected_out_at?->format('g:i A') ?? '—' }}@if ($v->isOverstayed())<div class="small">expired</div>@endif</td>
                                <td data-label="Left">@if ($v->signed_out_at){{ $v->signed_out_at->format('g:i A') }}<div class="small text-muted">{{ $v->signed_in_at->diffForHumans($v->signed_out_at, true) }} on site</div>@else<span class="status-pill st-success">On site</span>@endif</td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('visitors.pass', $v) }}" class="action-btn btn-more" title="Pass"><i class="ri-printer-line"></i></a>
                                    @if ($v->isOnSite())
                                        @can('Manage visitors')
                                            <button type="button" class="action-btn btn-more" data-bs-toggle="collapse" data-bs-target="#ext{{ $v->id }}" title="Extend pass"><i class="ri-time-line"></i></button>
                                            <form method="POST" action="{{ route('visitors.sign-out', $v) }}" class="d-inline" data-once>@csrf<button class="action-btn btn-open"><i class="ri-logout-box-r-line"></i> Sign out</button></form>
                                            <form method="POST" action="{{ route('visitors.extend', $v) }}" class="collapse mt-2 rm-form" id="ext{{ $v->id }}" data-once>@csrf
                                                <div class="input-group input-group-sm"><input type="datetime-local" name="expected_out_at" class="form-control" required value="{{ now()->addHours(2)->format('Y-m-d\TH:i') }}" aria-label="New time"><button class="action-btn btn-more">Extend</button></div>
                                            </form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $visitors->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>

@can('Manage visitors')
<div class="modal fade" id="visitorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content rm-form" method="POST" action="{{ route('visitors.store') }}" data-once>
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-user-add-line me-1"></i> Sign in a visitor</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-6"><label for="vsName">Full name</label><input type="text" name="full_name" id="vsName" class="form-control" maxlength="150" required value="{{ old('full_name') }}"></div>
                <div class="col-md-6"><label for="vsPhone">Phone</label><input type="tel" name="phone" id="vsPhone" class="form-control" maxlength="30" value="{{ old('phone') }}"></div>
                <div class="col-md-4"><label for="vsIdType">ID shown</label>
                    <select name="id_type" id="vsIdType" class="form-select"><option value="">None</option>@foreach (\App\Models\Visitor::ID_TYPES as $t)<option @selected(old('id_type') === $t)>{{ $t }}</option>@endforeach</select></div>
                <div class="col-md-4"><label for="vsIdNo">ID number</label><input type="text" name="id_number" id="vsIdNo" class="form-control" maxlength="50" value="{{ old('id_number') }}"></div>
                <div class="col-md-4"><label for="vsCompany">Company</label><input type="text" name="company" id="vsCompany" class="form-control" maxlength="120" value="{{ old('company') }}"></div>
                <div class="col-md-4"><label for="vsPurpose">Reason for visit</label>
                    <select name="purpose" id="vsPurpose" class="form-select" required>@foreach (\App\Models\Visitor::PURPOSES as $k => $label)<option value="{{ $k }}" @selected(old('purpose') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-md-8" id="vsGuestWrap"><label for="vsGuest">Which guest</label>
                    <select name="reservation_id" id="vsGuest" class="form-select"><option value="">Choose an in-house guest</option>@foreach ($inHouse as $g)<option value="{{ $g['id'] }}" @selected((int) old('reservation_id') === $g['id'])>{{ $g['label'] }}</option>@endforeach</select></div>
                <div class="col-md-4 d-none" id="vsStaffWrap"><label for="vsStaff">Seeing (staff)</label>
                    <select name="host_user_id" id="vsStaff" class="form-select"><option value="">—</option>@foreach ($staff as $u)<option value="{{ $u->id }}" @selected((int) old('host_user_id') === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
                <div class="col-md-4 d-none" id="vsHostWrap"><label for="vsHost">Or department / person</label><input type="text" name="host" id="vsHost" class="form-control" maxlength="150" value="{{ old('host') }}" placeholder="e.g. Kitchen, Accounts"></div>
                <div class="col-md-3"><label for="vsPlate">Vehicle plate</label><input type="text" name="vehicle_plate" id="vsPlate" class="form-control text-uppercase" maxlength="20" value="{{ old('vehicle_plate') }}"></div>
                <div class="col-md-3"><label for="vsParty">People</label><input type="number" name="party_size" id="vsParty" class="form-control" min="1" max="50" value="{{ old('party_size', 1) }}"></div>
                <div class="col-md-6"><label for="vsUntil">Pass valid until</label><input type="datetime-local" name="expected_out_at" id="vsUntil" class="form-control" value="{{ old('expected_out_at', now()->addHours(config('hotel.security.pass_hours', 4))->format('Y-m-d\TH:i')) }}"></div>
                <div class="col-12"><label for="vsNotes">Notes <span class="text-muted fw-normal">(items carried in, equipment…)</span></label><input type="text" name="notes" id="vsNotes" class="form-control" maxlength="500" value="{{ old('notes') }}"></div>
            </div>
            <div class="modal-footer"><button type="button" class="action-btn btn-more" data-bs-dismiss="modal">Cancel</button><button type="submit" class="action-btn btn-open"><i class="ri-qr-code-line"></i> Sign in &amp; print pass</button></div>
        </form>
    </div>
</div>
<script>
(function () {
    var p = document.getElementById('vsPurpose');
    function sync() {
        var guest = p.value === 'guest_visit';
        document.getElementById('vsGuestWrap').classList.toggle('d-none', !guest);
        document.getElementById('vsStaffWrap').classList.toggle('d-none', guest);
        document.getElementById('vsHostWrap').classList.toggle('d-none', guest);
        if (!guest) document.getElementById('vsGuest').value = '';
    }
    p.addEventListener('change', sync); sync();
    @if ($errors->any() && old('full_name')) document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('visitorModal')).show(); }); @endif
})();
</script>
@endcan
@endsection
