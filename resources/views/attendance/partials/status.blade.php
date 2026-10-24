{{-- One day's status pill. Needs $v (dayView array). --}}
@php use App\Models\Attendance\AttendanceDay; @endphp
<span class="status-pill {{ AttendanceDay::pill($v['status']) }}">{{ AttendanceDay::label($v['status']) }}@if ($v['status'] === 'late' || ($v['late'] > 0 && $v['status'] !== 'late')) · {{ $v['late'] }}m @endif</span>
