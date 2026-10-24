@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Attendance\StaffProfile;
    $tabs = ['staff' => ['Staff & badges', 'ri-team-line', $profiles->count()], 'shifts' => ['Shifts', 'ri-time-line', $shifts->count()], 'departments' => ['Departments', 'ri-building-2-line', $departments->count()], 'points' => ['Kiosks', 'ri-qr-scan-2-line', $points->count()]];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Attendance Set-up" icon="ri-settings-3-line" subtitle="Who is tracked, their shifts and badges, and where they clock in">
        <x-slot:actions>
            @if ($tab === 'staff')
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#addStaffModal" @disabled($untracked->isEmpty())><i class="ri-user-add-line"></i> Add staff</button>
                <button type="button" class="cb-hero-btn" id="printSelected"><i class="ri-printer-line"></i> Print badges</button>
            @elseif ($tab === 'shifts')
                <button type="button" class="cb-hero-btn" data-edit-shift="new"><i class="ri-add-line"></i> Add shift</button>
            @elseif ($tab === 'departments')
                <button type="button" class="cb-hero-btn" data-edit-dept="new"><i class="ri-add-line"></i> Add department</button>
            @else
                <button type="button" class="cb-hero-btn" data-edit-point="new"><i class="ri-add-line"></i> Add kiosk</button>
            @endif
        </x-slot:actions>
    </x-cb.hero>

    @include('attendance.partials.nav', ['active' => 'setup'])
    @include('rooms.partials.flash')
    @if (session('print_badge'))
        <div class="cb-banner info"><i class="ri-printer-line"></i><div class="d-flex w-100 align-items-center gap-2">The new card is ready. <a class="action-btn btn-open ms-auto" target="_blank" rel="noopener" href="{{ route('attendance.badges', ['ids' => [session('print_badge')]]) }}">Print it</a></div></div>
    @endif

    <div class="rs-tabs" role="tablist">
        @foreach ($tabs as $key => [$label, $icon, $n])
            <a href="{{ route('attendance.setup', ['tab' => $key]) }}" class="rs-tab {{ $tab === $key ? 'active' : '' }}"><i class="{{ $icon }}"></i> {{ $label }} <span class="n">{{ $n }}</span></a>
        @endforeach
    </div>

    @if ($tab === 'staff')
        <div class="cb-card">
            <form method="GET" class="cb-toolbar">
                <input type="hidden" name="tab" value="staff">
                <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $q }}" placeholder="Name or staff no…" aria-label="Search"></div>
                <select name="department" class="cb-select" onchange="this.form.submit()" aria-label="Department"><option value="">All departments</option>@foreach ($departments as $d)<option value="{{ $d->id }}" @selected(request('department') == $d->id)>{{ $d->name }}</option>@endforeach</select>
                <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Filter</button>
                @if ($untracked->isNotEmpty())<span class="small text-muted ms-auto">{{ $untracked->count() }} user{{ $untracked->count() === 1 ? '' : 's' }} not set up yet</span>@endif
            </form>
            @if ($profiles->isEmpty())
                <div class="empty-state py-5"><i class="ri-team-line"></i><h6>No staff set up yet</h6><p class="small text-muted">Add the people whose attendance you want to track.</p></div>
            @else
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th style="width:30px"><input type="checkbox" class="form-check-input" id="selAll" aria-label="Select all"></th><th>Staff</th><th>Department</th><th>Shift</th><th>Works</th><th>Badge</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($profiles as $p)
                                <tr style="{{ $p->tracks_attendance ? '' : 'opacity:.55' }}">
                                    <td><input type="checkbox" class="form-check-input" data-sel value="{{ $p->id }}" aria-label="Select {{ $p->user->name }}"></td>
                                    <td data-label="Staff"><a href="{{ route('attendance.staff', $p->user) }}" class="fw-bold text-reset">{{ $p->user->name }}</a>
                                        <div class="small text-muted"><span class="rs-ref">{{ $p->staff_no }}</span> {{ $p->job_title }}</div>
                                        @unless ($p->tracks_attendance)<span class="status-pill st-muted">Not tracked</span>@endunless</td>
                                    <td data-label="Department">@if ($p->department)<span class="d-inline-flex align-items-center gap-1"><span style="width:9px;height:9px;border-radius:50%;background:{{ $p->department->color }}"></span>{{ $p->department->name }}</span>@else<span class="text-danger small">None</span>@endif</td>
                                    <td data-label="Shift" class="small">{{ $p->shift ? $p->shift->name . ' · ' . $p->shift->hours_label : '—' }}</td>
                                    <td data-label="Works" class="small">{{ $p->work_days_label }}</td>
                                    <td data-label="Badge" class="small">Issued {{ $p->badge_issued_at?->format('j M Y') ?? '—' }}</td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="action-btn btn-more" data-edit-staff="{{ json_encode(['id' => $p->id, 'name' => $p->user->name, 'staff_no' => $p->staff_no, 'department_id' => $p->department_id, 'shift_id' => $p->shift_id, 'job_title' => $p->job_title, 'employment_start' => $p->employment_start?->toDateString(), 'work_days' => $p->work_days ?? [1, 2, 3, 4, 5, 6], 'tracks_attendance' => $p->tracks_attendance]) }}"><i class="ri-pencil-line"></i> Edit</button>
                                        <a class="action-btn btn-open" href="{{ route('attendance.badges', ['ids' => [$p->id]]) }}" target="_blank" rel="noopener" title="Print badge"><i class="ri-printer-line"></i></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @elseif ($tab === 'shifts')
        <div class="cb-card">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Shift</th><th>Hours</th><th class="num">Late after</th><th class="num">Early-leave allowance</th><th class="num">Unpaid break</th><th class="num">Staff</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($shifts as $s)
                            <tr style="{{ $s->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Shift"><span class="d-inline-flex align-items-center gap-2 fw-bold"><span style="width:12px;height:12px;border-radius:4px;background:{{ $s->color }}"></span>{{ $s->name }}</span> <span class="rs-ref">{{ $s->code }}</span>
                                    @if ($s->crossesMidnight())<div class="small text-muted"><i class="ri-moon-line"></i> overnight — ends next morning</div>@endif</td>
                                <td data-label="Hours">{{ $s->hours_label }} <div class="small text-muted">{{ intdiv($s->minutes(), 60) }}h{{ $s->minutes() % 60 ? ' ' . ($s->minutes() % 60) . 'm' : '' }} paid</div></td>
                                <td data-label="Late after" class="num">{{ $s->grace_minutes }} min</td>
                                <td data-label="Early leave" class="num">{{ $s->early_grace_minutes }} min</td>
                                <td data-label="Break" class="num">{{ $s->break_minutes ? $s->break_minutes . ' min' : '—' }}</td>
                                <td data-label="Staff" class="num">{{ $s->staff_count }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="action-btn btn-more" data-edit-shift="{{ json_encode(['id' => $s->id, 'name' => $s->name, 'code' => $s->code, 'start_time' => substr($s->start_time, 0, 5), 'end_time' => substr($s->end_time, 0, 5), 'grace_minutes' => $s->grace_minutes, 'early_grace_minutes' => $s->early_grace_minutes, 'break_minutes' => $s->break_minutes, 'color' => $s->color, 'is_active' => $s->is_active]) }}"><i class="ri-pencil-line"></i> Edit</button>
                                    <form method="POST" action="{{ route('attendance.shifts.destroy', $s) }}" class="d-inline" onsubmit="return confirm('Delete this shift?');">@csrf @method('DELETE')<button class="action-btn btn-danger-soft" type="submit" title="Delete"><i class="ri-delete-bin-line"></i></button></form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted">No shifts yet. Add your shifts (e.g. Morning 7am–3pm, Night 11pm–7am).</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'departments')
        <div class="cb-card">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Department</th><th>Head</th><th class="num">Staff</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($departments as $d)
                            <tr>
                                <td data-label="Department"><span class="d-inline-flex align-items-center gap-2 fw-bold"><span style="width:12px;height:12px;border-radius:4px;background:{{ $d->color }}"></span>{{ $d->name }}</span> @if ($d->code)<span class="rs-ref">{{ $d->code }}</span>@endif</td>
                                <td data-label="Head">{{ $d->manager?->name ?? '—' }}</td>
                                <td data-label="Staff" class="num">{{ $d->staff_count }}</td>
                                <td class="text-end text-nowrap">
                                    <a class="action-btn btn-open" href="{{ route('attendance.badges', ['department' => $d->id]) }}" target="_blank" rel="noopener"><i class="ri-printer-line"></i> Badges</a>
                                    <button type="button" class="action-btn btn-more" data-edit-dept="{{ json_encode($d->only(['id', 'name', 'code', 'color', 'manager_id'])) }}"><i class="ri-pencil-line"></i> Edit</button>
                                    <form method="POST" action="{{ route('attendance.departments.destroy', $d) }}" class="d-inline" onsubmit="return confirm('Delete this department? Its staff keep their records.');">@csrf @method('DELETE')<button class="action-btn btn-danger-soft" type="submit" title="Delete"><i class="ri-delete-bin-line"></i></button></form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">No departments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="cb-banner info"><i class="ri-tablet-line"></i><div>A kiosk is any tablet or phone with a camera at the staff entrance. Sign in on it as someone with <strong>Run attendance kiosk</strong> (e.g. the security post) and open <strong>Open kiosk</strong>. It keeps working if the network drops.</div></div>
        <div class="cb-card">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Kiosk</th><th>Location</th><th>Photo with each scan</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($points as $pt)
                            <tr style="{{ $pt->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Kiosk"><span class="fw-bold">{{ $pt->name }}</span> <span class="rs-ref">{{ $pt->code }}</span>@unless ($pt->is_active)<span class="status-pill st-muted">Off</span>@endunless</td>
                                <td data-label="Location" class="small">{{ $pt->location ?: '—' }}</td>
                                <td data-label="Photo">{!! $pt->take_photo ? '<span class="status-pill st-success">Yes</span>' : '<span class="status-pill st-muted">No</span>' !!}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="action-btn btn-more" data-edit-point="{{ json_encode($pt->only(['id', 'name', 'code', 'location', 'take_photo', 'is_active'])) }}"><i class="ri-pencil-line"></i> Edit</button>
                                    <form method="POST" action="{{ route('attendance.points.destroy', $pt) }}" class="d-inline" onsubmit="return confirm('Remove this kiosk?');">@csrf @method('DELETE')<button class="action-btn btn-danger-soft" type="submit" title="Remove"><i class="ri-delete-bin-line"></i></button></form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">No kiosks yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
</div>
</div>

{{-- Add staff --}}
<div class="modal fade" id="addStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content rm-form" method="POST" action="{{ route('attendance.staff.add') }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-user-add-line me-1"></i> Add staff to attendance</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p class="small text-muted">Each gets a staff number and a badge. Staff accounts are created under User Management.</p>
                <label class="form-check mb-2 fw-semibold"><input type="checkbox" class="form-check-input" onchange="document.querySelectorAll('[data-add-user]').forEach(c => c.checked = this.checked)"> <span class="form-check-label">Everyone below</span></label>
                @foreach ($untracked as $u)
                    <label class="form-check mb-1"><input type="checkbox" class="form-check-input" name="user_ids[]" value="{{ $u->id }}" data-add-user> <span class="form-check-label">{{ $u->name }} <span class="small text-muted">{{ $u->email }}</span></span></label>
                @endforeach
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Add</button></div>
        </form>
    </div>
</div>

{{-- Edit staff --}}
<div class="modal fade" id="staffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="#" id="staffForm">
            @csrf @method('PUT')
            <div class="modal-header"><h5 class="modal-title" data-title>Staff</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-5"><label for="s_staff_no">Staff no.</label><input name="staff_no" id="s_staff_no" class="form-control" maxlength="20" required></div>
                <div class="col-sm-7"><label for="s_job_title">Job title</label><input name="job_title" id="s_job_title" class="form-control" maxlength="80"></div>
                <div class="col-sm-6"><label for="s_department_id">Department</label><select name="department_id" id="s_department_id" class="form-select"><option value="">—</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label for="s_shift_id">Usual shift</label><select name="shift_id" id="s_shift_id" class="form-select"><option value="">No set shift</option>@foreach ($shifts as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->hours_label }})</option>@endforeach</select></div>
                <div class="col-12"><label>Works on</label>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach (StaffProfile::DAYS as $n => $dn)
                            <input type="checkbox" class="btn-check" name="work_days[]" value="{{ $n }}" id="wd{{ $n }}" data-wd><label class="btn btn-sm btn-outline-secondary" for="wd{{ $n }}">{{ $dn }}</label>
                        @endforeach
                    </div></div>
                <div class="col-sm-6"><label for="s_employment_start">Started on</label><input type="date" name="employment_start" id="s_employment_start" class="form-control"></div>
                <div class="col-sm-6 d-flex align-items-end"><label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="tracks_attendance" value="1" id="s_tracks_attendance"> <span class="form-check-label">Track attendance</span></label></div>
                <div class="col-12 small text-muted">Days before “Started on” are never counted as absent. Change single days (swaps, extra days off) from the person’s calendar.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>

{{-- Shift --}}
<div class="modal fade" id="shiftModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('attendance.shifts.store') }}" id="shiftForm">
            @csrf <input type="hidden" name="_method" value="POST">
            <div class="modal-header"><h5 class="modal-title" data-title>Add shift</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-8"><label for="h_name">Name</label><input name="name" id="h_name" class="form-control" maxlength="60" required placeholder="e.g. Morning"></div>
                <div class="col-sm-4"><label for="h_code">Code</label><input name="code" id="h_code" class="form-control text-uppercase" maxlength="10" required placeholder="MOR"></div>
                <div class="col-6"><label for="h_start_time">Starts</label><input type="time" name="start_time" id="h_start_time" class="form-control" required value="07:00"></div>
                <div class="col-6"><label for="h_end_time">Ends</label><input type="time" name="end_time" id="h_end_time" class="form-control" required value="15:00"></div>
                <div class="col-4"><label for="h_grace_minutes">Late after (min)</label><input type="number" name="grace_minutes" id="h_grace_minutes" class="form-control" min="0" max="120" value="10" required></div>
                <div class="col-4"><label for="h_early_grace_minutes">Early leave ok (min)</label><input type="number" name="early_grace_minutes" id="h_early_grace_minutes" class="form-control" min="0" max="120" value="10" required></div>
                <div class="col-4"><label for="h_break_minutes">Unpaid break (min)</label><input type="number" name="break_minutes" id="h_break_minutes" class="form-control" min="0" max="240" value="0" required></div>
                <div class="col-6"><label for="h_color">Colour</label><input type="color" name="color" id="h_color" class="form-control form-control-color w-100" value="#0d9488"></div>
                <div class="col-6 d-flex align-items-end"><label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="h_is_active" checked> <span class="form-check-label">In use</span></label></div>
                <div class="col-12 small text-muted">An end time earlier than the start is an overnight shift (e.g. 11pm–7am). Clock-outs the next morning count for the night before.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>

{{-- Department --}}
<div class="modal fade" id="deptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('attendance.departments.store') }}" id="deptForm">
            @csrf <input type="hidden" name="_method" value="POST">
            <div class="modal-header"><h5 class="modal-title" data-title>Add department</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-8"><label for="d_name">Name</label><input name="name" id="d_name" class="form-control" maxlength="80" required></div>
                <div class="col-sm-4"><label for="d_code">Code</label><input name="code" id="d_code" class="form-control text-uppercase" maxlength="10"></div>
                <div class="col-sm-8"><label for="d_manager_id">Head of department</label><select name="manager_id" id="d_manager_id" class="form-select"><option value="">—</option>@foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
                <div class="col-sm-4"><label for="d_color">Colour</label><input type="color" name="color" id="d_color" class="form-control form-control-color w-100" value="#1e4a7e"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>

{{-- Kiosk --}}
<div class="modal fade" id="pointModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('attendance.points.store') }}" id="pointForm">
            @csrf <input type="hidden" name="_method" value="POST">
            <div class="modal-header"><h5 class="modal-title" data-title>Add kiosk</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-8"><label for="k_name">Name</label><input name="name" id="k_name" class="form-control" maxlength="80" required placeholder="e.g. Staff Entrance"></div>
                <div class="col-sm-4"><label for="k_code">Code</label><input name="code" id="k_code" class="form-control text-uppercase" maxlength="10" required></div>
                <div class="col-12"><label for="k_location">Location</label><input name="location" id="k_location" class="form-control" maxlength="120"></div>
                <div class="col-12 d-flex flex-column gap-1">
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="take_photo" value="1" id="k_take_photo" checked> <span class="form-check-label">Take a photo with each scan (stops people clocking in for each other)</span></label>
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="k_is_active" checked> <span class="form-check-label">In use</span></label>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function wire(attr, modalId, formId, prefix, storeUrl, updateUrl, label) {
        const form = document.getElementById(formId), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById(modalId));
        document.querySelectorAll('[' + attr + ']').forEach(btn => btn.addEventListener('click', () => {
            const raw = btn.getAttribute(attr), d = raw === 'new' ? null : JSON.parse(raw);
            form.reset();
            form.action = d ? updateUrl.replace('__ID__', d.id) : storeUrl;
            form.querySelector('[name=_method]').value = d ? 'PUT' : 'POST';
            form.querySelector('[data-title]').textContent = (d ? 'Edit ' : 'Add ') + label;
            if (d) Object.entries(d).forEach(([k, v]) => { const el = document.getElementById(prefix + k); if (!el) return; if (el.type === 'checkbox') el.checked = !!v; else el.value = v ?? ''; });
            modal.show();
        }));
    }
    wire('data-edit-shift', 'shiftModal', 'shiftForm', 'h_', @json(route('attendance.shifts.store')), @json(route('attendance.shifts.update', '__ID__')), 'shift');
    wire('data-edit-dept', 'deptModal', 'deptForm', 'd_', @json(route('attendance.departments.store')), @json(route('attendance.departments.update', '__ID__')), 'department');
    wire('data-edit-point', 'pointModal', 'pointForm', 'k_', @json(route('attendance.points.store')), @json(route('attendance.points.update', '__ID__')), 'kiosk');

    const sf = document.getElementById('staffForm'), sm = bootstrap.Modal.getOrCreateInstance(document.getElementById('staffModal'));
    const staffUrl = @json(route('attendance.staff.update', '__ID__'));
    document.querySelectorAll('[data-edit-staff]').forEach(btn => btn.addEventListener('click', () => {
        const d = JSON.parse(btn.dataset.editStaff);
        sf.action = staffUrl.replace('__ID__', d.id);
        sf.querySelector('[data-title]').textContent = d.name;
        ['staff_no', 'job_title', 'department_id', 'shift_id', 'employment_start'].forEach(k => document.getElementById('s_' + k).value = d[k] ?? '');
        document.getElementById('s_tracks_attendance').checked = !!d.tracks_attendance;
        sf.querySelectorAll('[data-wd]').forEach(c => c.checked = d.work_days.includes(parseInt(c.value)));
        sm.show();
    }));

    const all = document.getElementById('selAll');
    all?.addEventListener('change', () => document.querySelectorAll('[data-sel]').forEach(c => { if (!c.closest('tr').hidden) c.checked = all.checked; }));
    document.getElementById('printSelected')?.addEventListener('click', () => {
        const ids = [...document.querySelectorAll('[data-sel]:checked')].map(c => 'ids[]=' + c.value);
        if (!ids.length) { alert('Tick the staff whose badges you want to print.'); return; }
        window.open(@json(route('attendance.badges')) + '?' + ids.join('&'), '_blank');
    });
});
</script>
@endsection
