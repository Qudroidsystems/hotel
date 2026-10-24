@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Attendance\StaffLeave; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Staff Leave" icon="ri-suitcase-line" subtitle="Leave days are never counted as absent">
        <x-slot:actions>
            @can('Manage attendance')<button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#leaveModal"><i class="ri-add-line"></i> Record leave</button>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('attendance.partials.nav', ['active' => 'leave'])
    @include('rooms.partials.flash')

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="when" class="cb-select" onchange="this.form.submit()" aria-label="When">
                <option value="">Current &amp; upcoming</option><option value="past" @selected(request('when') === 'past')>All, including past</option>
            </select>
            <select name="status" class="cb-select" onchange="this.form.submit()" aria-label="Status">
                <option value="">Approved</option><option value="cancelled" @selected($status === 'cancelled')>Cancelled</option>
            </select>
        </form>
        @if ($leaves->isEmpty())
            <div class="empty-state py-5"><i class="ri-suitcase-line"></i><h6>No leave to show</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Staff</th><th>Leave</th><th>Dates</th><th>Recorded</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($leaves as $l)
                            <tr>
                                <td data-label="Staff"><a href="{{ route('attendance.staff', $l->user) }}" class="fw-bold text-reset">{{ $l->user->name }}</a>
                                    <div class="small text-muted">{{ $l->user->staffProfile?->department?->name }}</div></td>
                                <td data-label="Leave">{{ $l->type_label }}@if ($l->reason)<div class="small text-muted">{{ $l->reason }}</div>@endif</td>
                                <td data-label="Dates">{{ $l->from_date->format('D j M') }} – {{ $l->to_date->format('D j M Y') }}
                                    <div class="small text-muted">{{ $l->days }} day{{ $l->days === 1 ? '' : 's' }}@if ($l->from_date->lte(today()) && $l->to_date->gte(today())) · <span class="text-primary fw-semibold">away now</span>@endif</div></td>
                                <td data-label="Recorded" class="small">{{ $l->createdBy?->name }} · {{ $l->created_at->format('j M') }}</td>
                                <td class="text-end">
                                    @if ($l->status === 'approved')
                                        @can('Manage attendance')
                                            <form method="POST" action="{{ route('attendance.leave.cancel', $l) }}" onsubmit="return confirm('Cancel this leave?');">@csrf<button class="action-btn btn-danger-soft" type="submit">Cancel</button></form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $leaves->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>

@can('Manage attendance')
<div class="modal fade" id="leaveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('attendance.leave.store') }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-suitcase-line me-1"></i> Record leave</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label for="lUser">Staff</label><select name="user_id" id="lUser" class="form-select" required><option value="">Choose…</option>@foreach ($staff as $u)<option value="{{ $u->id }}" @selected(old('user_id') == $u->id)>{{ $u->name }}</option>@endforeach</select></div>
                <div class="col-12"><label for="lType">Type</label><select name="type" id="lType" class="form-select">@foreach (StaffLeave::TYPES as $k => $lbl)<option value="{{ $k }}" @selected(old('type') === $k)>{{ $lbl }}</option>@endforeach</select></div>
                <div class="col-6"><label for="lFrom">From</label><input type="date" name="from_date" id="lFrom" class="form-control" required value="{{ old('from_date') }}"></div>
                <div class="col-6"><label for="lTo">To</label><input type="date" name="to_date" id="lTo" class="form-control" required value="{{ old('to_date') }}"></div>
                <div class="col-12"><label for="lReason">Reason</label><input name="reason" id="lReason" class="form-control" maxlength="255" value="{{ old('reason') }}"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save leave</button></div>
        </form>
    </div>
</div>
@endcan
@endsection
