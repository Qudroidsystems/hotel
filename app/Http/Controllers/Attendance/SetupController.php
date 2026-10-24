<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendancePoint;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\Department;
use App\Models\Attendance\Shift;
use App\Models\Attendance\StaffProfile;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Shifts, departments, kiosks, and who is tracked (with their badges). */
class SetupController extends Controller
{
    public function __construct(private AttendanceService $att)
    {
        $this->middleware('permission:Manage staff & shifts');
    }

    public function index(Request $request)
    {
        $tab = in_array($request->input('tab'), ['staff', 'shifts', 'departments', 'points'], true) ? $request->input('tab') : 'staff';
        $q = trim((string) $request->input('q'));
        $profiles = StaffProfile::with(['user.roles', 'department', 'shift'])
            ->when($request->integer('department'), fn ($w, $v) => $w->where('department_id', $v))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('staff_no', 'like', "%{$q}%")->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"))))
            ->get()->sortBy(fn ($p) => $p->user->name)->values();
        $untracked = User::whereDoesntHave('staffProfile')->where('is_disabled', false)->orderBy('name')->get(['id', 'name', 'email']);
        $shifts = Shift::orderBy('start_time')->get()->each(fn ($s) => $s->setAttribute('staff_count', StaffProfile::where('shift_id', $s->id)->count()));
        $departments = Department::withCount('staff')->with('manager')->orderBy('name')->get();
        $points = AttendancePoint::orderBy('name')->get();
        $users = User::orderBy('name')->get(['id', 'name']);
        $pagetitle = 'Attendance Set-up';

        return view('attendance.setup', compact('pagetitle', 'tab', 'profiles', 'untracked', 'shifts', 'departments', 'points', 'users', 'q'));
    }

    // ── Staff ─────────────────────────────────────────────────────────

    public function addStaff(Request $request)
    {
        $data = $request->validate(['user_ids' => 'required|array|min:1', 'user_ids.*' => 'integer|exists:users,id']);
        $n = 0;
        foreach (User::whereIn('id', $data['user_ids'])->get() as $u) {
            if (! StaffProfile::where('user_id', $u->id)->exists()) {
                $this->att->ensureProfile($u);
                $n++;
            }
        }

        return back()->with('success', $n . ' staff added to attendance. Set their department and shift, then print their badges.');
    }

    public function updateStaff(Request $request, StaffProfile $profile)
    {
        $data = $request->validate([
            'staff_no'         => ['required', 'string', 'max:20', Rule::unique('staff_profiles', 'staff_no')->ignore($profile->id)],
            'department_id'    => 'nullable|exists:departments,id',
            'shift_id'         => 'nullable|exists:shifts,id',
            'job_title'        => 'nullable|string|max:80',
            'employment_start' => 'nullable|date',
            'work_days'        => 'nullable|array',
            'work_days.*'      => 'integer|between:1,7',
        ]);
        $data['work_days'] = array_values(array_map('intval', $data['work_days'] ?? []));
        $data['tracks_attendance'] = $request->boolean('tracks_attendance');
        $profile->update($data);

        return back()->with('success', $profile->user->name . ' updated.');
    }

    public function reissueBadge(StaffProfile $profile)
    {
        $this->att->reissueBadge($profile);

        return back()->with('success', 'New badge code for ' . $profile->user->name . '. The old card no longer works — print the new one.')
            ->with('print_badge', $profile->id);
    }

    /** Printable ID cards with QR codes. ?ids[]=… or ?department=… */
    public function badges(Request $request)
    {
        $profiles = StaffProfile::with(['user', 'department'])
            ->when($request->input('ids'), fn ($q, $ids) => $q->whereIn('id', (array) $ids))
            ->when($request->integer('department'), fn ($q, $v) => $q->where('department_id', $v))
            ->get()->sortBy(fn ($p) => $p->user->name)->values();

        return view('attendance.badges', compact('profiles'));
    }

    // ── Shifts ────────────────────────────────────────────────────────

    public function storeShift(Request $request)
    {
        Shift::create($this->shiftData($request));

        return redirect()->route('attendance.setup', ['tab' => 'shifts'])->with('success', 'Shift added.');
    }

    public function updateShift(Request $request, Shift $shift)
    {
        $shift->update($this->shiftData($request, $shift));

        return redirect()->route('attendance.setup', ['tab' => 'shifts'])->with('success', 'Shift updated. Past days keep the times they were worked out with until a clock-in changes them.');
    }

    public function destroyShift(Shift $shift)
    {
        if (StaffProfile::where('shift_id', $shift->id)->exists()) {
            return back()->with('error', 'Staff still use this shift. Move them to another shift first, or switch it off.');
        }
        $shift->delete();

        return back()->with('success', 'Shift deleted.');
    }

    private function shiftData(Request $request, ?Shift $shift = null): array
    {
        $request->merge(['code' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('code')))]);
        $data = $request->validate([
            'name'                => 'required|string|max:60',
            'code'                => ['required', 'string', 'min:1', 'max:10', Rule::unique('shifts', 'code')->ignore($shift?->id)],
            'start_time'          => 'required|date_format:H:i',
            'end_time'            => 'required|date_format:H:i|different:start_time',
            'grace_minutes'       => 'required|integer|min:0|max:120',
            'early_grace_minutes' => 'required|integer|min:0|max:120',
            'break_minutes'       => 'required|integer|min:0|max:240',
            'color'               => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    // ── Departments ───────────────────────────────────────────────────

    public function storeDepartment(Request $request)
    {
        Department::create($this->deptData($request));

        return redirect()->route('attendance.setup', ['tab' => 'departments'])->with('success', 'Department added.');
    }

    public function updateDepartment(Request $request, Department $department)
    {
        $department->update($this->deptData($request, $department));

        return redirect()->route('attendance.setup', ['tab' => 'departments'])->with('success', 'Department updated.');
    }

    public function destroyDepartment(Department $department)
    {
        $department->delete(); // staff keep their profile with no department

        return back()->with('success', 'Department deleted.');
    }

    private function deptData(Request $request, ?Department $d = null): array
    {
        return $request->validate([
            'name'       => ['required', 'string', 'max:80', Rule::unique('departments', 'name')->ignore($d?->id)],
            'code'       => ['nullable', 'string', 'max:10', Rule::unique('departments', 'code')->ignore($d?->id)],
            'color'      => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'manager_id' => 'nullable|exists:users,id',
        ]);
    }

    // ── Kiosks ────────────────────────────────────────────────────────

    public function storePoint(Request $request)
    {
        AttendancePoint::create($this->pointData($request));

        return redirect()->route('attendance.setup', ['tab' => 'points'])->with('success', 'Clock-in point added.');
    }

    public function updatePoint(Request $request, AttendancePoint $point)
    {
        $point->update($this->pointData($request, $point));

        return redirect()->route('attendance.setup', ['tab' => 'points'])->with('success', 'Clock-in point updated.');
    }

    public function destroyPoint(AttendancePoint $point)
    {
        if (AttendancePunch::where('point_id', $point->id)->exists()) {
            $point->update(['is_active' => false]);

            return back()->with('success', 'This point has clock-ins on record, so it was switched off instead.');
        }
        $point->delete();

        return back()->with('success', 'Clock-in point deleted.');
    }

    private function pointData(Request $request, ?AttendancePoint $p = null): array
    {
        $request->merge(['code' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('code')))]);
        $data = $request->validate([
            'name'     => 'required|string|max:80',
            'code'     => ['required', 'string', 'max:10', Rule::unique('attendance_points', 'code')->ignore($p?->id)],
            'location' => 'nullable|string|max:120',
        ]);
        $data['take_photo'] = $request->boolean('take_photo');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
