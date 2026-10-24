@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Attendance\AttendanceDay;
    use App\Models\Attendance\StaffLeave;
    use App\Models\Attendance\StaffProfile;
    $u = $user; $p = $profile; $s = $sheet;
    $canManage = auth()->user()->can('Manage attendance');
    $prevMonth = $month->copy()->subMonth()->format('Y-m'); $nextMonth = $month->copy()->addMonth();
    $routeName = $own ? 'attendance.my' : 'attendance.staff';
    $routeArgs = $own ? [] : [$u];
    $first = $month->copy()->startOfMonth(); $lead = $first->isoWeekday() - 1;
@endphp
<style>
    .at-cal { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
    .at-cal .hd { font-size: 11.5px; font-weight: 700; color: var(--cb-muted); text-transform: uppercase; text-align: center; padding: 2px 0; }
    .at-cal .d { border: 1px solid var(--cb-border, #e2e8f0); border-radius: 10px; padding: 6px 7px; min-height: 76px; background: var(--cb-surface, #fff); font-size: 11.5px; position: relative; text-align: left; }
    .at-cal button.d { cursor: pointer; } .at-cal button.d:hover { box-shadow: 0 2px 10px rgba(0,0,0,.08); }
    .at-cal .d .n { font-weight: 800; font-size: 13px; color: var(--cb-heading); }
    .at-cal .d.today { outline: 2px solid #0d9488; }
    .at-cal .d .bar { position: absolute; left: 0; top: 8px; bottom: 8px; width: 4px; border-radius: 0 4px 4px 0; }
    .at-cal .d.st-present .bar, .at-cal .d.st-in .bar { background: #16a34a; } .at-cal .d.st-late .bar { background: #d97706; }
    .at-cal .d.st-absent { background: #fef2f2; } .at-cal .d.st-absent .bar { background: #dc2626; }
    .at-cal .d.st-leave .bar { background: #0284c7; } .at-cal .d.st-missing_out .bar { background: #7c3aed; }
    .at-cal .d.st-off, .at-cal .d.future { opacity: .6; }
    [data-bs-theme=dark] .at-cal .d.st-absent { background: rgba(220,38,38,.1); }
    .at-photo { width: 54px; height: 54px; border-radius: 10px; object-fit: cover; }
    .at-void td { text-decoration: line-through; color: var(--cb-muted) !important; }
    .at-void td.keep { text-decoration: none; }
    @media (max-width: 640px) { .at-cal .d { min-height: 56px; padding: 4px; } .at-cal .d .t { display: none; } }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$own ? 'My Attendance' : $u->name" icon="ri-user-follow-line"
               :subtitle="$p->staff_no . ($p->job_title ? ' · ' . $p->job_title : '') . ($p->department ? ' · ' . $p->department->name : '') . ($p->shift ? ' · ' . $p->shift->name . ' (' . $p->shift->hours_label . ')' : '') . ' · ' . $p->work_days_label"
               :back="$own ? null : route('attendance.board')" :back-label="$own ? null : 'Attendance'">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route($routeName, $routeArgs + ['month' => $prevMonth]) }}"><i class="ri-arrow-left-s-line"></i> {{ $month->copy()->subMonth()->format('M') }}</a>
            <span class="cb-hero-btn" style="pointer-events:none">{{ $month->format('F Y') }}</span>
            @if ($nextMonth->lte(today()))<a class="cb-hero-btn" href="{{ route($routeName, $routeArgs + ['month' => $nextMonth->format('Y-m')]) }}">{{ $nextMonth->format('M') }} <i class="ri-arrow-right-s-line"></i></a>@endif
            @if ($canManage)
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#punchModal"><i class="ri-add-line"></i> Add clock-in</button>
            @endif
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @unless ($p->tracks_attendance)
        <div class="cb-banner warning"><i class="ri-eye-off-line"></i><div>Attendance isn't tracked for this person (switched off in set-up), so days aren't counted as absent.</div></div>
    @endunless

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-2"><x-cb.stat label="Days present" :value="$s['present'] . ' / ' . $s['scheduled']" icon="ri-checkbox-circle-line" accent="green" /></div>
        <div class="col-6 col-lg-2"><x-cb.stat label="Late" :value="$s['late']" icon="ri-timer-flash-line" accent="amber" :hint="$s['late_minutes'] . ' min in total'" /></div>
        <div class="col-6 col-lg-2"><x-cb.stat label="Absent" :value="$s['absent']" icon="ri-user-unfollow-line" accent="rose" /></div>
        <div class="col-6 col-lg-2"><x-cb.stat label="On leave" :value="$s['leave']" icon="ri-suitcase-line" accent="sky" /></div>
        <div class="col-6 col-lg-2"><x-cb.stat label="Hours worked" :value="AttendanceDay::hours($s['worked'])" icon="ri-time-line" accent="teal" /></div>
        <div class="col-6 col-lg-2"><x-cb.stat label="Overtime" :value="AttendanceDay::hours($s['overtime'])" icon="ri-add-circle-line" accent="violet" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card :title="$month->format('F Y')" icon="ri-calendar-2-line">
                <x-slot:tools>@if ($canManage)<span class="small text-muted">Click a day to change the rota</span>@endif</x-slot:tools>
                <div class="at-cal">
                    @foreach (StaffProfile::DAYS as $dn)<div class="hd">{{ $dn }}</div>@endforeach
                    @for ($i = 0; $i < $lead; $i++)<div></div>@endfor
                    @for ($d = $first->copy(); $d->lte($month->copy()->endOfMonth()); $d->addDay())
                        @php $ds = $d->toDateString(); $v = $s['days'][$ds] ?? null; $future = $d->gt(today()); @endphp
                        <{{ $canManage ? 'button type=button' : 'div' }} class="d {{ $v ? 'st-' . $v['status'] : '' }} {{ $d->isToday() ? 'today' : '' }} {{ $future ? 'future' : '' }}"
                            @if ($canManage) data-rota-date="{{ $ds }}" data-rota-label="{{ $d->format('l j M') }}" data-rota-current="{{ isset($rota[$ds]) ? ($rota[$ds]->shift_id ?? 'off') : 'usual' }}" @endif>
                            <span class="bar"></span>
                            <div class="n">{{ $d->day }}@if (isset($rota[$ds])) <i class="ri-repeat-line text-primary" title="Rota change: {{ $rota[$ds]->note }}"></i>@endif</div>
                            @if ($v)
                                <div class="t">
                                    @if ($v['row']){{ $v['row']->first_in?->format('H:i') }}–{{ $v['row']->last_out?->format('H:i') ?? '…' }}
                                    @elseif ($v['status'] === 'leave')<span class="text-info">Leave</span>
                                    @elseif ($v['status'] === 'absent')<span class="text-danger fw-semibold">Absent</span>
                                    @elseif ($v['shift'])<span class="text-muted">{{ $v['shift']->code }}</span>
                                    @else<span class="text-muted">Off</span>@endif
                                </div>
                                @if ($v['late'])<div class="t text-warning-emphasis">late {{ $v['late'] }}m</div>@endif
                            @elseif ($future)
                                @php $fv = app(\App\Services\Attendance\AttendanceService::class)->scheduleFor($p, $ds); @endphp
                                <div class="t text-muted">{{ $fv['leave'] ? 'Leave' : ($fv['shift']?->code ?? 'Off') }}</div>
                            @endif
                        </{{ $canManage ? 'button' : 'div' }}>
                    @endfor
                </div>
                <div class="d-flex flex-wrap gap-3 small text-muted mt-3">
                    <span><span class="d-inline-block rounded" style="width:10px;height:10px;background:#16a34a"></span> On time</span>
                    <span><span class="d-inline-block rounded" style="width:10px;height:10px;background:#d97706"></span> Late</span>
                    <span><span class="d-inline-block rounded" style="width:10px;height:10px;background:#dc2626"></span> Absent</span>
                    <span><span class="d-inline-block rounded" style="width:10px;height:10px;background:#0284c7"></span> Leave</span>
                    <span><span class="d-inline-block rounded" style="width:10px;height:10px;background:#7c3aed"></span> No clock-out</span>
                </div>
            </x-cb.card>

            <x-cb.card title="Clock-ins" icon="ri-qr-scan-2-line" :flush="true">
                @if ($punches->isEmpty())
                    <div class="empty-state py-4"><i class="ri-qr-scan-2-line"></i><h6>No clock-ins this month</h6></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Day</th><th>Time</th><th>Where</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($punches as $day => $list)
                                    @foreach ($list->sortBy('punched_at') as $pu)
                                        <tr class="{{ $pu->voided_at || $pu->ignored ? 'at-void' : '' }}">
                                            <td data-label="Day" class="small">@if ($loop->first)<strong>{{ \Carbon\Carbon::parse($day)->format('D j M') }}</strong>@endif</td>
                                            <td data-label="Time">
                                                <div class="d-flex align-items-center gap-2">
                                                    @if ($pu->photo_path && ! $own)<a class="keep" href="{{ route('attendance.photo', $pu) }}" target="_blank" rel="noopener"><img class="at-photo" src="{{ route('attendance.photo', $pu) }}" alt="Photo at scan" loading="lazy"></a>@endif
                                                    <div>
                                                        <span class="fw-bold">{{ $pu->punched_at->format('g:i A') }}</span>
                                                        @if ($pu->direction)<span class="status-pill {{ $pu->direction === 'in' ? 'st-success' : 'st-info' }} keep">{{ strtoupper($pu->direction) }}</span>@endif
                                                        @if (! $pu->punched_at->isSameDay($pu->work_date))<span class="small text-muted keep">({{ $pu->punched_at->format('D') }} — night shift)</span>@endif
                                                        @if ($pu->ignored)<div class="small text-muted keep">Repeat scan — not counted</div>@endif
                                                        @if ($pu->voided_at)<div class="small text-danger keep">Removed by {{ $pu->voidedBy?->name }}: {{ $pu->void_reason }}</div>@endif
                                                    </div>
                                                </div>
                                            </td>
                                            <td data-label="Where" class="small">
                                                {{ $pu->source === 'manual' ? 'Added by ' . ($pu->recordedBy?->name ?? 'HR') : ($pu->point?->name ?? 'Kiosk') }}
                                                @if ($pu->was_offline)<span class="text-muted keep" title="Scanned while the kiosk was offline, sent later"><i class="ri-wifi-off-line"></i></span>@endif
                                                @if ($pu->note)<div class="text-muted">{{ $pu->note }}</div>@endif
                                            </td>
                                            <td class="keep text-end">
                                                @if ($canManage && ! $pu->voided_at && ! $pu->ignored)
                                                    <button type="button" class="action-btn btn-danger-soft" data-void-punch="{{ route('attendance.punches.void', $pu) }}" data-void-label="{{ $pu->punched_at->format('D j M, g:i A') }}" title="Remove"><i class="ri-forbid-line"></i></button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            @if ($own || auth()->user()->can('Manage staff & shifts'))
                <x-cb.card title="{{ $own ? 'My badge' : 'Badge' }}" icon="ri-qr-code-line">
                    <div class="d-flex gap-3 align-items-center">
                        <div id="badgeQr" class="p-2 bg-white rounded-3 border" style="width:136px;height:136px"></div>
                        <div class="small text-muted">
                            Scan this at the staff entrance kiosk{{ $own ? ' if you don’t have your card with you' : '' }}.
                            <div class="mt-1">Issued {{ $p->badge_issued_at?->format('j M Y') ?? '—' }}</div>
                            @can('Manage staff & shifts')
                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    <a href="{{ route('attendance.badges', ['ids' => [$p->id]]) }}" target="_blank" rel="noopener" class="action-btn btn-open"><i class="ri-printer-line"></i> Print card</a>
                                    <form method="POST" action="{{ route('attendance.staff.badge', $p) }}" onsubmit="return confirm('Replace {{ addslashes($u->name) }}’s badge? The old card will stop working.');">@csrf
                                        <button class="action-btn btn-more" type="submit"><i class="ri-refresh-line"></i> Lost card</button></form>
                                </div>
                            @endcan
                        </div>
                    </div>
                </x-cb.card>
            @endif

            <x-cb.card title="Leave" icon="ri-suitcase-line" :count="$leaves->where('status', 'approved')->count()">
                @if ($canManage)
                    <x-slot:tools><button type="button" class="action-btn btn-go" data-bs-toggle="modal" data-bs-target="#leaveModal"><i class="ri-add-line"></i> Add</button></x-slot:tools>
                @endif
                @forelse ($leaves as $l)
                    <div class="d-flex justify-content-between align-items-start py-2 border-bottom" style="{{ $l->status === 'cancelled' ? 'opacity:.5;text-decoration:line-through' : '' }}">
                        <div><div class="fw-semibold small">{{ $l->type_label }} · {{ $l->days }} day{{ $l->days === 1 ? '' : 's' }}</div>
                            <div class="small text-muted">{{ $l->from_date->format('j M') }} – {{ $l->to_date->format('j M Y') }}{{ $l->reason ? ' · ' . $l->reason : '' }}</div></div>
                        @if ($canManage && $l->status === 'approved' && $l->to_date->gte(today()))
                            <form method="POST" action="{{ route('attendance.leave.cancel', $l) }}" onsubmit="return confirm('Cancel this leave?');">@csrf<button class="btn btn-link btn-sm p-0 text-danger" type="submit">Cancel</button></form>
                        @endif
                    </div>
                @empty
                    <div class="small text-muted">No leave recorded.</div>
                @endforelse
            </x-cb.card>

            <x-cb.card title="Details" icon="ri-information-line">
                <dl class="rm-meta mb-0" style="grid-template-columns: 120px 1fr">
                    <dt>Staff no.</dt><dd><span class="rs-ref">{{ $p->staff_no }}</span></dd>
                    <dt>Usual shift</dt><dd>{{ $p->shift ? $p->shift->name . ' · ' . $p->shift->hours_label : '—' }}</dd>
                    <dt>Works</dt><dd>{{ $p->work_days_label }}</dd>
                    @if ($p->employment_start)<dt>Started</dt><dd>{{ $p->employment_start->format('j M Y') }}</dd>@endif
                    @if ($s['punctuality'] !== null)<dt>Punctuality</dt><dd>{{ $s['punctuality'] }}% on time</dd>@endif
                    @if ($s['missing_out'])<dt>No clock-out</dt><dd>{{ $s['missing_out'] }} day{{ $s['missing_out'] === 1 ? '' : 's' }}</dd>@endif
                </dl>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>

@if ($canManage)
<div class="modal fade" id="punchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('attendance.punches.store', $u) }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-add-line me-1"></i> Add a clock-in for {{ $u->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-6"><label for="pDate">Date</label><input type="date" name="date" id="pDate" class="form-control" required max="{{ today()->toDateString() }}" value="{{ old('date', today()->toDateString()) }}"></div>
                <div class="col-6"><label for="pTime">Time</label><input type="time" name="time" id="pTime" class="form-control" required value="{{ old('time') }}"></div>
                <div class="col-12"><label for="pReason">Reason <span class="text-danger">*</span></label><input name="reason" id="pReason" class="form-control" maxlength="255" required placeholder="e.g. Forgot badge — confirmed by supervisor" value="{{ old('reason') }}"></div>
                <div class="col-12 small text-muted">In or out is worked out from the order of the day’s clock-ins. Use the time on the night of a night shift — after midnight belongs to the shift that started the evening before.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Add</button></div>
        </form>
    </div>
</div>
<div class="modal fade" id="voidPunchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="#" id="voidPunchForm">
            @csrf
            <div class="modal-header"><h5 class="modal-title text-danger"><i class="ri-forbid-line me-1"></i> Remove clock-in <span data-label></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><label for="vpReason">Reason <span class="text-danger">*</span></label><input name="reason" id="vpReason" class="form-control" maxlength="255" required placeholder="e.g. Scanned by mistake">
                <div class="small text-muted mt-2">It stays on record, crossed out, with your name.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep</button><button class="btn btn-danger" type="submit">Remove</button></div>
        </form>
    </div>
</div>
<div class="modal fade" id="rotaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('attendance.rota', $u) }}" id="rotaForm">
            @csrf <input type="hidden" name="date" id="rotaDate">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-repeat-line me-1"></i> <span id="rotaTitle">Rota</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="d-flex flex-column gap-2 mb-3">
                    <label class="form-check mb-0"><input type="radio" class="form-check-input" name="mode" value="usual" checked> <span class="form-check-label">Usual pattern ({{ $p->shift?->name ?? 'no shift' }}, {{ $p->work_days_label }})</span></label>
                    <label class="form-check mb-0"><input type="radio" class="form-check-input" name="mode" value="off"> <span class="form-check-label">Day off</span></label>
                    <label class="form-check mb-0 d-flex align-items-center gap-2"><input type="radio" class="form-check-input" name="mode" value="shift"> <span class="form-check-label">Work</span>
                        <select name="shift_id" id="rotaShift" class="form-select form-select-sm" style="max-width:240px">@foreach ($shifts as $sh)<option value="{{ $sh->id }}">{{ $sh->name }} ({{ $sh->hours_label }})</option>@endforeach</select></label>
                </div>
                <input name="note" class="form-control" maxlength="255" placeholder="Note, e.g. Swapped with Kunle">
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
<div class="modal fade" id="leaveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('attendance.leave.store') }}">
            @csrf <input type="hidden" name="user_id" value="{{ $u->id }}">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-suitcase-line me-1"></i> Leave for {{ $u->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label for="lType">Type</label><select name="type" id="lType" class="form-select">@foreach (StaffLeave::TYPES as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach</select></div>
                <div class="col-6"><label for="lFrom">From</label><input type="date" name="from_date" id="lFrom" class="form-control" required></div>
                <div class="col-6"><label for="lTo">To</label><input type="date" name="to_date" id="lTo" class="form-control" required></div>
                <div class="col-12"><label for="lReason">Reason</label><input name="reason" id="lReason" class="form-control" maxlength="255"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save leave</button></div>
        </form>
    </div>
</div>
@endif

<script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    @if ($own || auth()->user()->can('Manage staff & shifts'))
        if (window.QRCode) new QRCode(document.getElementById('badgeQr'), { text: @json($p->badge_qr), width: 120, height: 120, correctLevel: QRCode.CorrectLevel.M });
    @endif
    document.querySelectorAll('[data-void-punch]').forEach(b => b.addEventListener('click', () => {
        const m = document.getElementById('voidPunchModal');
        document.getElementById('voidPunchForm').action = b.dataset.voidPunch;
        m.querySelector('[data-label]').textContent = b.dataset.voidLabel;
        bootstrap.Modal.getOrCreateInstance(m).show();
    }));
    document.querySelectorAll('[data-rota-date]').forEach(b => b.addEventListener('click', () => {
        document.getElementById('rotaDate').value = b.dataset.rotaDate;
        document.getElementById('rotaTitle').textContent = 'Rota — ' + b.dataset.rotaLabel;
        const cur = b.dataset.rotaCurrent, f = document.getElementById('rotaForm');
        f.querySelector('[name=mode][value=' + (cur === 'usual' ? 'usual' : (cur === 'off' ? 'off' : 'shift')) + ']').checked = true;
        if (cur !== 'usual' && cur !== 'off') document.getElementById('rotaShift').value = cur;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('rotaModal')).show();
    }));
    document.getElementById('rotaShift')?.addEventListener('change', () => { document.querySelector('#rotaForm [name=mode][value=shift]').checked = true; });
});
</script>
@endsection
