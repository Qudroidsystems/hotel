<?php

namespace App\Http\Controllers\Attendance;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\StaffLeave;
use App\Models\Attendance\StaffProfile;
use App\Models\Attendance\StaffRota;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** HR fixes: add a missed clock-in, remove a wrong one, change the rota, record leave. Every change has a reason. */
class CorrectionController extends Controller
{
    public function __construct(private AttendanceService $att)
    {
        $this->middleware('permission:Manage attendance');
    }

    public function addPunch(Request $request, User $user)
    {
        $data = $request->validate([
            'date'   => 'required|date|before_or_equal:today',
            'time'   => 'required|date_format:H:i',
            'reason' => 'required|string|max:255',
        ]);

        return $this->attempt(fn () => $this->att->manualPunch($user, Carbon::parse($data['date'] . ' ' . $data['time']), $data['reason'], $request->user()),
            'Clock-in added.');
    }

    public function voidPunch(Request $request, AttendancePunch $punch)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->att->voidPunch($punch, $data['reason'], $request->user()), 'Clock-in removed.');
    }

    public function setRota(Request $request, User $user)
    {
        $data = $request->validate([
            'date'     => 'required|date',
            'shift_id' => ['nullable', Rule::exists('shifts', 'id')],
            'mode'     => 'required|in:shift,off,usual',
            'note'     => 'nullable|string|max:255',
        ]);
        $profile = StaffProfile::where('user_id', $user->id)->firstOrFail();
        $date = Carbon::parse($data['date'])->toDateString();

        if ($data['mode'] === 'usual') {
            StaffRota::where('user_id', $user->id)->whereDate('work_date', $date)->delete();
        } else {
            abort_if($data['mode'] === 'shift' && empty($data['shift_id']), 422, 'Choose a shift.');
            StaffRota::updateOrCreate(['user_id' => $user->id, 'work_date' => $date],
                ['shift_id' => $data['mode'] === 'off' ? null : $data['shift_id'], 'note' => $data['note'] ?? null, 'created_by' => $request->user()->id]);
        }
        $this->att->recompute($profile, $date);

        return back()->with('success', 'Rota updated for ' . Carbon::parse($date)->format('D j M') . '.');
    }

    public function storeLeave(Request $request)
    {
        $data = $request->validate([
            'user_id'   => ['required', Rule::exists('staff_profiles', 'user_id')],
            'type'      => ['required', Rule::in(array_keys(StaffLeave::TYPES))],
            'from_date' => 'required|date',
            'to_date'   => 'required|date|after_or_equal:from_date',
            'reason'    => 'nullable|string|max:255',
        ]);
        $overlap = StaffLeave::approved()->where('user_id', $data['user_id'])
            ->where('from_date', '<=', $data['to_date'])->where('to_date', '>=', $data['from_date'])->exists();
        if ($overlap) {
            return back()->withInput()->with('error', 'This person already has leave on some of those days.');
        }
        StaffLeave::create($data + ['status' => 'approved', 'created_by' => $request->user()->id]);

        return back()->with('success', 'Leave recorded.');
    }

    public function cancelLeave(StaffLeave $leave)
    {
        $leave->update(['status' => 'cancelled']);

        return back()->with('success', 'Leave cancelled.');
    }

    private function attempt(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }
}
