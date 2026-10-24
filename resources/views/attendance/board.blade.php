@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Attendance\AttendanceDay; $isToday = $date->isToday(); @endphp
<style>
    .at-av { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; flex: none; }
    .at-shift { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; }
    .at-shift i { width: 9px; height: 9px; border-radius: 50%; display: inline-block; }
    .at-feed li { display: flex; gap: 10px; align-items: center; padding: 8px 0; border-bottom: 1px dashed var(--cb-border, #e2e8f0); }
    .at-feed img.ph { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; }
    .at-dir { font-size: 11px; font-weight: 800; border-radius: 6px; padding: 2px 7px; }
    .at-dir.in { background: #dcfce7; color: #166534; } .at-dir.out { background: #e0e7ff; color: #3730a3; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Staff Attendance" icon="ri-user-follow-line" :subtitle="($isToday ? 'Today, ' : '') . $date->format('l j F Y')">
        <x-slot:actions>
            @can('Run attendance kiosk')<a class="cb-hero-btn" href="{{ route('attendance.kiosk') }}" style="background:#0d9488;border-color:#0d9488"><i class="ri-qr-scan-2-line"></i> Open kiosk</a>@endcan
            <a class="cb-hero-btn" href="{{ route('attendance.timesheet') }}"><i class="ri-calendar-2-line"></i> Timesheet</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('attendance.partials.nav', ['active' => 'board'])
    @include('rooms.partials.flash')

    @if ($stats['total'] === 0)
        <div class="cb-banner info"><i class="ri-information-line"></i><div>No staff are set up for attendance yet. @can('Manage staff & shifts')<a href="{{ route('attendance.setup') }}" class="fw-bold">Add staff and shifts</a>@endcan</div></div>
    @endif

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-2"><x-cb.stat label="In now" :value="$stats['in']" icon="ri-login-circle-line" accent="green" :hint="'of ' . $stats['total'] . ' staff'" /></div>
        <div class="col-6 col-lg-2"><x-cb.stat label="Late" :value="$stats['late']" icon="ri-timer-flash-line" accent="amber" /></div>
        <div class="col-6 col-lg-2"><x-cb.stat :label="$isToday ? 'Not in yet' : 'Due'" :value="$stats['due']" icon="ri-time-line" accent="sky" /></div>
        <div class="col-6 col-lg-2"><x-cb.stat label="Absent" :value="$stats['absent']" icon="ri-user-unfollow-line" accent="rose" /></div>
        <div class="col-6 col-lg-2"><x-cb.stat label="On leave" :value="$stats['leave']" icon="ri-suitcase-line" accent="violet" /></div>
        <div class="col-6 col-lg-2"><x-cb.stat label="No clock-out" :value="$stats['missing']" icon="ri-logout-circle-line" accent="teal" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="cb-card">
                <form method="GET" class="cb-toolbar">
                    <input type="date" name="date" class="cb-select" value="{{ $date->toDateString() }}" max="{{ today()->toDateString() }}" aria-label="Date" onchange="this.form.submit()">
                    <select name="department" class="cb-select" aria-label="Department" onchange="this.form.submit()">
                        <option value="">All departments</option>
                        @foreach ($departments as $d)<option value="{{ $d->id }}" @selected($dept === $d->id)>{{ $d->name }}</option>@endforeach
                    </select>
                    <a href="{{ route('attendance.board', ['date' => $date->copy()->subDay()->toDateString(), 'department' => $dept]) }}" class="action-btn btn-more"><i class="ri-arrow-left-s-line"></i> Previous day</a>
                    @unless ($isToday)<a href="{{ route('attendance.board', ['department' => $dept]) }}" class="action-btn btn-more">Today</a>@endunless
                    <input type="search" class="cb-select ms-auto" placeholder="Find a name…" aria-label="Find a name" oninput="const q=this.value.toLowerCase();document.querySelectorAll('[data-name]').forEach(r=>r.hidden=q&&!r.dataset.name.includes(q))">
                </form>
                @if ($rows->isEmpty())
                    <div class="empty-state py-5"><i class="ri-user-follow-line"></i><h6>No staff to show</h6></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Staff</th><th>Shift</th><th>In</th><th>Out</th><th class="num">Worked</th><th>Status</th></tr></thead>
                            <tbody>
                                @foreach ($rows as $r)
                                    @php $p = $r['profile']; $row = $r['row']; @endphp
                                    <tr data-name="{{ strtolower($p->user->name . ' ' . $p->staff_no) }}">
                                        <td data-label="Staff">
                                            <a href="{{ route('attendance.staff', $p->user) }}" class="d-flex align-items-center gap-2 text-reset">
                                                <img src="{{ $p->user->avatar_url }}" alt="" class="at-av">
                                                <span><span class="fw-bold" style="color: var(--cb-heading)">{{ $p->user->name }}</span>
                                                    <span class="d-block small text-muted">{{ $p->job_title ?: '—' }}{{ $p->department ? ' · ' . $p->department->name : '' }}</span></span>
                                            </a>
                                        </td>
                                        <td data-label="Shift">
                                            @if ($r['shift'])
                                                <span class="at-shift"><i style="background: {{ $r['shift']->color }}"></i>{{ $r['shift']->name }}</span>
                                                <div class="small text-muted">{{ $r['start']->format('g:i A') }} – {{ $r['end']->format('g:i A') }}{{ !empty($r['from_yesterday']) ? ' (from last night)' : '' }}</div>
                                                @if ($r['rota'])<div class="small text-violet" title="{{ $r['rota']->note }}"><i class="ri-repeat-line"></i> rota change</div>@endif
                                            @elseif ($r['leave'])
                                                <span class="small">{{ $r['leave']->type_label }} until {{ $r['leave']->to_date->format('j M') }}</span>
                                            @else
                                                <span class="small text-muted">Day off</span>
                                            @endif
                                        </td>
                                        <td data-label="In" class="small">{{ $row?->first_in?->format('g:i A') ?? '—' }}</td>
                                        <td data-label="Out" class="small">{{ $row?->last_out?->format('g:i A') ?? ($row?->is_in ? 'still in' : '—') }}</td>
                                        <td data-label="Worked" class="num small">{{ AttendanceDay::hours($r['worked']) }}</td>
                                        <td data-label="Status">
                                            @include('attendance.partials.status', ['v' => $r])
                                            @if ($r['status'] === 'due')<div class="small text-muted">{{ $r['overdue'] }} min past start</div>@endif
                                            @if ($r['early'])<div class="small text-muted">left {{ $r['early'] }}m early</div>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Latest scans" icon="ri-qr-scan-2-line" :count="$recent->count()">
                @if ($recent->isEmpty())
                    <div class="small text-muted">No clock-ins yet{{ $isToday ? ' today' : '' }}.</div>
                @else
                    <ul class="at-feed list-unstyled mb-0">
                        @foreach ($recent as $pu)
                            <li>
                                @if ($pu->photo_path)<a href="{{ route('attendance.photo', $pu) }}" target="_blank" rel="noopener"><img class="ph" src="{{ route('attendance.photo', $pu) }}" alt="Photo at scan" loading="lazy"></a>
                                @else<img class="ph" src="{{ $pu->user->avatar_url }}" alt="">@endif
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small" style="color: var(--cb-heading)">{{ $pu->user->name }}</div>
                                    <div class="small text-muted">{{ $pu->punched_at->format('g:i A') }} · {{ $pu->point?->name ?? ($pu->source === 'manual' ? 'Added by HR' : '—') }}@if ($pu->was_offline) · <i class="ri-wifi-off-line" title="Scanned while the kiosk was offline"></i>@endif</div>
                                </div>
                                @if ($pu->direction)<span class="at-dir {{ $pu->direction }}">{{ strtoupper($pu->direction) }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
