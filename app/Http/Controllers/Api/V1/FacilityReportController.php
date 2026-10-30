<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Facility\FacilityDevice;
use App\Services\Facility\FacilityService;
use Illuminate\Http\Request;

/**
 * For hardware: a building-management system or IoT hub reports equipment states with its key
 * (IT Control Room → Equipment → Hardware hubs).
 *
 *   POST /api/v1/facility/report   Authorization: Bearer fh_…
 *   {"devices": [{"code": "R101-AC", "state": "on", "reading": "22°C"}, {"code": "GEN-1", "state": "fault"}]}
 *   → {"success": true, "data": {"received": 2, "updated": 2, "changed": 1, "unknown": []}}
 *
 *   GET /api/v1/facility/devices   → every device code the hub can report, with its place and current state.
 *
 * States: on / off / fault (also 1/0, true/false, running/stopped, alarm/error). Report at least every
 * 15 minutes; a device not heard from for longer shows "No signal" on the board.
 */
class FacilityReportController extends Controller
{
    public function report(Request $request, FacilityService $facility)
    {
        $hub = FacilityService::hubFor($request->bearerToken());
        if (! $hub) {
            return response()->json(['success' => false, 'message' => 'Unknown or revoked hub key.'], 401);
        }
        $d = $request->validate([
            'devices' => 'required|array|max:' . FacilityService::MAX_REPORT,
            'devices.*.code' => 'required|string|max:60',
            'devices.*.state' => 'required',
            'devices.*.reading' => 'nullable|max:28',
        ]);

        return response()->json(['success' => true, 'data' => $facility->report($hub, $d['devices'], $request->ip())]);
    }

    public function devices(Request $request)
    {
        $hub = FacilityService::hubFor($request->bearerToken());
        if (! $hub) {
            return response()->json(['success' => false, 'message' => 'Unknown or revoked hub key.'], 401);
        }
        \App\Support\Tenancy\CurrentHotel::get()->adopt($hub);

        return response()->json(['success' => true, 'data' => FacilityDevice::active()->with('room:id,number')->orderBy('code')->get()
            ->map(fn ($d) => ['code' => $d->code, 'name' => $d->name, 'kind' => $d->kind, 'place' => $d->place, 'state' => $d->state, 'reading' => $d->reading])]);
    }
}
