@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Attendance\AttendanceDay;
    $q = array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'department' => $dept]);
    $codes = ['present' => ['✓', '#16a34a'], 'in' => ['✓', '#16a34a'], 'late' => ['L', '#d97706'], 'missing_out' => ['?', '#7c3aed'], 'absent' => ['A', '#dc2626'], 'leave' => ['Lv', '#0284c7'], 'off' => ['·', '#94a3b8'], 'due' => ['…', '#94a3b8'], 'upcoming' => ['', '#94a3b8']];
@endphp
<style>
    .ts-grid th, .ts-grid td { padding: 4px 3px !important; text-align: center; font-size: 11.5px; white-space: nowrap; }
    .ts-grid td.nm, .ts-grid th.nm { text-align: left; position: sticky; left: 0; background: var(--cb-surface, #fff); z-index: 1; min-width: 160px; }
    .ts-grid .wk { color: var(--cb-muted); }
    .ts-grid .c { font-weight: 800; border-radius: 5px; display: inline-block; min-width: 20px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Timesheet" icon="ri-calendar-2-line" :subtitle="$from->format('j M') . ' – ' . $to->format('j M Y')">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('attendance.timesheet', $q + ['export' => 'csv']) }}"><i class="ri-file-excel-2-line"></i> Download CSV</a>
            <button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line"></i> Print</button>
        </x-slot:actions>
    </x-cb.hero>

    @include('attendance.partials.nav', ['active' => 'timesheet'])

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <input type="date" name="from" class="cb-select" value="{{ $from->toDateString() }}" aria-label="From">
            <input type="date" name="to" class="cb-select" value="{{ $to->toDateString() }}" aria-label="To">
            <select name="department" class="cb-select" aria-label="Department" onchange="this.form.submit()">
                <option value="">All departments</option>
                @foreach ($departments as $d)<option value="{{ $d->id }}" @selected($dept === $d->id)>{{ $d->name }}</option>@endforeach
            </select>
            <label class="form-check mb-0 small"><input type="checkbox" class="form-check-input" name="grid" value="1" @checked($grid) onchange="this.form.submit()"> <span class="form-check-label">Day by day</span></label>
            <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Show</button>
            <a href="{{ route('attendance.timesheet', ['from' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => today()->subMonthNoOverflow()->endOfMonth()->toDateString(), 'department' => $dept]) }}" class="action-btn btn-more">Last month</a>
            <a href="{{ route('attendance.timesheet', ['department' => $dept]) }}" class="action-btn btn-more">This month</a>
        </form>

        @if ($rows->isEmpty())
            <div class="empty-state py-5"><i class="ri-calendar-2-line"></i><h6>No staff</h6></div>
        @elseif ($grid)
            <div class="table-responsive">
                <table class="cb-table ts-grid">
                    <thead><tr><th class="nm">Staff</th>
                        @foreach ($days as $d)<th class="{{ $d->isWeekend() ? 'wk' : '' }}">{{ substr($d->format('D'), 0, 1) }}<br>{{ $d->day }}</th>@endforeach
                        <th>Late</th><th>Abs</th><th>Hours</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td class="nm"><a href="{{ route('attendance.staff', $r['profile']->user) }}" class="fw-semibold text-reset">{{ $r['profile']->user->name }}</a></td>
                                @foreach ($days as $d)
                                    @php $v = $r['days'][$d->toDateString()] ?? null; [$c, $col] = $codes[$v['status'] ?? 'off'] ?? ['', '#94a3b8']; @endphp
                                    <td title="{{ $d->format('D j M') }}: {{ AttendanceDay::label($v['status'] ?? 'off') }}{{ ($v['late'] ?? 0) ? ' (' . $v['late'] . 'm late)' : '' }}"><span class="c" style="color: {{ $col }}">{{ $c }}</span></td>
                                @endforeach
                                <td>{{ $r['late'] }}</td><td>{{ $r['absent'] }}</td><td>{{ round($r['worked'] / 60, 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="small text-muted p-3">✓ on time · L late · A absent · Lv leave · ? no clock-out · · day off</div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Staff</th><th class="num">Present</th><th class="num">Late</th><th class="num">Absent</th><th class="num">Leave</th><th class="num">No out</th><th class="num">Hours</th><th class="num">Overtime</th><th class="num">On time</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            @php $p = $r['profile']; @endphp
                            <tr>
                                <td data-label="Staff"><a href="{{ route('attendance.staff', $p->user) }}" class="fw-bold text-reset">{{ $p->user->name }}</a>
                                    <div class="small text-muted">{{ $p->staff_no }}{{ $p->department ? ' · ' . $p->department->name : '' }}{{ $p->shift ? ' · ' . $p->shift->name : '' }}</div></td>
                                <td data-label="Present" class="num">{{ $r['present'] }} <span class="small text-muted">/ {{ $r['scheduled'] }}</span></td>
                                <td data-label="Late" class="num">{!! $r['late'] ? '<span class="text-warning-emphasis fw-semibold">' . $r['late'] . '</span> <span class="small text-muted">(' . $r['late_minutes'] . 'm)</span>' : '0' !!}</td>
                                <td data-label="Absent" class="num">{!! $r['absent'] ? '<span class="text-danger fw-bold">' . $r['absent'] . '</span>' : '0' !!}</td>
                                <td data-label="Leave" class="num">{{ $r['leave'] }}</td>
                                <td data-label="No out" class="num">{{ $r['missing_out'] }}</td>
                                <td data-label="Hours" class="num fw-semibold">{{ AttendanceDay::hours($r['worked']) }}</td>
                                <td data-label="Overtime" class="num">{{ AttendanceDay::hours($r['overtime']) }}</td>
                                <td data-label="On time" class="num">{{ $r['punctuality'] !== null ? $r['punctuality'] . '%' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold"><td>Total</td><td class="num">{{ $rows->sum('present') }}</td><td class="num">{{ $rows->sum('late') }}</td><td class="num">{{ $rows->sum('absent') }}</td><td class="num">{{ $rows->sum('leave') }}</td><td class="num">{{ $rows->sum('missing_out') }}</td>
                            <td class="num">{{ AttendanceDay::hours($rows->sum('worked')) }}</td><td class="num">{{ AttendanceDay::hours($rows->sum('overtime')) }}</td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
