<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\Department;
use App\Models\Attendance\Shift;
use App\Models\Attendance\StaffLeave;
use App\Models\Attendance\StaffProfile;
use App\Models\Attendance\StaffRota;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/** Who's in today, a person's month, timesheets, and each user's own record. */
class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $att)
    {
        $this->middleware('permission:View attendance')->only(['board', 'timesheet', 'photo', 'leave']);
    }

    public function board(Request $request)
    {
        $date = $this->date($request->input('date'), today());
        $dept = $request->integer('department') ?: null;
        $rows = $this->att->board($date->toDateString(), $dept);
        $counts = $rows->countBy('status');
        $stats = [
            'in'      => $rows->where('is_in', true)->count(),
            'late'    => $rows->filter(fn ($r) => $r['late'] > 0)->count(),
            'due'     => $counts['due'] ?? 0,
            'absent'  => $counts['absent'] ?? 0,
            'leave'   => $counts['leave'] ?? 0,
            'missing' => $counts['missing_out'] ?? 0,
            'total'   => $rows->count(),
        ];
        $departments = Department::orderBy('name')->get();
        $recent = AttendancePunch::with(['user', 'point'])->where('ignored', false)->whereNull('voided_at')
            ->whereBetween('punched_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()->addHours(6)])
            ->when($dept, fn ($q, $v) => $q->whereHas('user.staffProfile', fn ($w) => $w->where('department_id', $v)))
            ->latest('punched_at')->limit(12)->get();
        $pagetitle = 'Staff Attendance';

        return view('attendance.board', compact('pagetitle', 'rows', 'stats', 'date', 'dept', 'departments', 'recent'));
    }

    public function staff(Request $request, User $user)
    {
        abort_unless($request->user()->can('View attendance') || $request->user()->id === $user->id, 403);
        $profile = StaffProfile::with(['department', 'shift', 'user'])->where('user_id', $user->id)->first();
        abort_unless($profile, 404, 'This person is not set up for attendance yet.');

        $month = $this->month($request->input('month'));
        [$from, $to] = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        $sheet = $this->att->timesheet($from, $to, null, $user->id)->first();
        $punches = AttendancePunch::with(['point', 'recordedBy', 'voidedBy'])->where('user_id', $user->id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->orderByDesc('punched_at')->get()->groupBy(fn ($p) => $p->work_date->toDateString());
        $leaves = StaffLeave::with('createdBy')->where('user_id', $user->id)->orderByDesc('from_date')->limit(10)->get();
        $rota = StaffRota::with('shift')->where('user_id', $user->id)->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get()->keyBy(fn ($r) => $r->work_date->toDateString());
        $shifts = Shift::where('is_active', true)->orderBy('start_time')->get();
        $pagetitle = $user->name . ' — Attendance';
        $own = $request->user()->id === $user->id && ! $request->user()->can('View attendance');

        return view('attendance.staff', compact('pagetitle', 'user', 'profile', 'month', 'sheet', 'punches', 'leaves', 'rota', 'shifts', 'own'));
    }

    /** Everyone's totals for a period; ?export=csv downloads it. */
    public function timesheet(Request $request)
    {
        $from = $this->date($request->input('from'), today()->startOfMonth());
        $to = $this->date($request->input('to'), today());
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $to = $to->min($from->copy()->addDays(92));
        $dept = $request->integer('department') ?: null;
        $rows = $this->att->timesheet($from, $to, $dept);

        if ($request->input('export') === 'csv') {
            $name = 'timesheet-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.csv';

            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Staff no', 'Name', 'Department', 'Job title', 'Usual shift', 'Scheduled days', 'Days present', 'Days late', 'Minutes late', 'Absent', 'On leave', 'No clock-out', 'Left early (days)', 'Hours worked', 'Overtime hours', 'Punctuality %']);
                foreach ($rows as $r) {
                    $p = $r['profile'];
                    fputcsv($out, [$p->staff_no, $p->user->name, $p->department?->name, $p->job_title, $p->shift?->name, $r['scheduled'], $r['present'], $r['late'], $r['late_minutes'],
                        $r['absent'], $r['leave'], $r['missing_out'], $r['early'], round($r['worked'] / 60, 2), round($r['overtime'] / 60, 2), $r['punctuality']]);
                }
                fclose($out);
            }, $name, ['Content-Type' => 'text/csv']);
        }

        $departments = Department::orderBy('name')->get();
        $pagetitle = 'Timesheet';
        $days = collect();
        for ($d = $from->copy(); $d->lte($to->copy()->min(today())); $d->addDay()) {
            $days->push($d->copy());
        }
        $grid = $request->boolean('grid') && $days->count() <= 31;

        return view('attendance.timesheet', compact('pagetitle', 'rows', 'from', 'to', 'dept', 'departments', 'days', 'grid'));
    }

    public function leave(Request $request)
    {
        $status = $request->input('status') === 'cancelled' ? 'cancelled' : 'approved';
        $leaves = StaffLeave::with(['user.staffProfile.department', 'createdBy'])->where('status', $status)
            ->when($request->input('when') !== 'past', fn ($q) => $q->where('to_date', '>=', today()->toDateString()))
            ->orderBy('from_date')->paginate(30)->withQueryString();
        $staff = User::whereHas('staffProfile')->orderBy('name')->get(['id', 'name']);
        $pagetitle = 'Staff Leave';

        return view('attendance.leave', compact('pagetitle', 'leaves', 'staff', 'status'));
    }

    public function my(Request $request)
    {
        $user = $request->user();
        $profile = StaffProfile::with(['department', 'shift'])->where('user_id', $user->id)->first();
        if (! $profile) {
            $pagetitle = 'My Attendance';

            return view('attendance.my', ['pagetitle' => $pagetitle, 'profile' => null]);
        }

        return $this->staff($request, $user);
    }

    public function photo(AttendancePunch $punch)
    {
        abort_unless($punch->photo_path && Storage::disk('local')->exists($punch->photo_path), 404);

        return response()->file(Storage::disk('local')->path($punch->photo_path), ['Cache-Control' => 'private, max-age=86400']);
    }

    private function date($value, Carbon $fallback): Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfDay() : $fallback->copy()->startOfDay();
        } catch (\Throwable) {
            return $fallback->copy()->startOfDay();
        }
    }

    private function month($value): Carbon
    {
        try {
            return $value ? Carbon::createFromFormat('Y-m', $value)->startOfMonth() : today()->startOfMonth();
        } catch (\Throwable) {
            return today()->startOfMonth();
        }
    }
}
