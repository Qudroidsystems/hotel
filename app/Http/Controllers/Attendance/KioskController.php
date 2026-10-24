<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\Request;

/**
 * The clock-in kiosk: a tablet or phone at the staff entrance. Like the bar
 * till, it keeps working offline — it knows every badge (as a hash), saves
 * each scan on the device, and sends them to /attendance/kiosk/sync.
 */
class KioskController extends Controller
{
    public function __construct(private AttendanceService $att)
    {
        $this->middleware('permission:Run attendance kiosk');
    }

    public function kiosk()
    {
        return response()->view('attendance.kiosk', ['boot' => $this->att->kioskData()])->header('Cache-Control', 'no-cache, private');
    }

    public function data()
    {
        return response()->json($this->att->kioskData() + ['csrf' => csrf_token()]);
    }

    public function ping(Request $request)
    {
        return response()->json(['ok' => true, 'time' => now()->toIso8601String(), 'user' => $request->user()->id]);
    }

    public function sync(Request $request)
    {
        $data = $request->validate(['punches' => 'required|array|max:50']);

        return response()->json(['results' => $this->att->sync($data['punches'], $request->user())]);
    }
}
