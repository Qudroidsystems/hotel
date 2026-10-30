<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use App\Models\Facility\FacilityDevice;
use App\Models\Facility\FacilityHub;
use App\Models\Room;
use App\Services\Facility\FacilityService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Setting up the room / area equipment shown on the call console's board, and the hardware hubs that feed it. */
class FacilityController extends Controller
{
    public function __construct(private FacilityService $facility)
    {
        $this->middleware('permission:Use call console|Manage room equipment')->only('index');
        $this->middleware('permission:Manage room equipment')->except('index');
    }

    public function index(Request $request)
    {
        $place = (string) $request->query('place', '');
        $devices = FacilityDevice::with('room:id,number')
            ->when($place === 'rooms', fn ($q) => $q->whereNotNull('room_id'))
            ->when($place === 'areas', fn ($q) => $q->whereNull('room_id'))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")->orWhere('area', 'like', "%{$s}%")
                ->orWhereHas('room', fn ($r) => $r->where('number', 'like', "%{$s}%"))))
            ->orderByRaw('room_id is null')->orderBy('area')->orderBy('room_id')->orderBy('sort_order')->paginate(60)->withQueryString();
        $rooms = Room::where('is_active', true)->orderBy('number')->get(['id', 'number']);
        $hubs = FacilityHub::latest()->get();
        $pagetitle = 'Room & Area Equipment';

        return view('it.facilities', compact('pagetitle', 'devices', 'rooms', 'hubs', 'place'));
    }

    public function store(Request $request)
    {
        $d = $this->validated($request);
        $d['code'] = $d['code'] ?: $this->facility->code($d['room_id'] ? 'R' . Room::find($d['room_id'])?->number : ($d['area'] ?: 'HOTEL'), $d['kind']);
        $request->validate(['code' => [Rule::unique('facility_devices', 'code')->where('hotel_id', \App\Support\Tenancy\CurrentHotel::get()->id())]]);
        $device = FacilityDevice::create($d + ['state' => 'off']);
        Audit::record('facility.device_added', "Added {$device->name} ({$device->place})", $device);

        return back()->with('success', "{$device->name} added to {$device->place}.");
    }

    public function update(Request $request, FacilityDevice $device)
    {
        $d = $this->validated($request, $device);
        $device->update(array_filter($d, fn ($v, $k) => $k !== 'code' || $v, ARRAY_FILTER_USE_BOTH));

        return back()->with('success', "{$device->name} saved.");
    }

    public function destroy(FacilityDevice $device)
    {
        Audit::record('facility.device_removed', "Removed {$device->name} ({$device->place})", $device);
        $device->delete();

        return back()->with('success', 'Removed.');
    }

    /** POST facilities/{device}/state {state: on|off|fault} — switched by hand on the board (JSON for the console). */
    public function state(Request $request, FacilityDevice $device)
    {
        $d = $request->validate(['state' => 'required|in:on,off,fault', 'reading' => 'nullable|string|max:28']);
        $this->facility->set($device, $d['state'], $d['reading'] ?? null, 'manual', $request->user());

        return $request->expectsJson()
            ? response()->json(['success' => true, 'data' => ['id' => $device->id, 'state' => $device->live_state]])
            : back()->with('success', "{$device->name} is {$d['state']}.");
    }

    public function standard(Request $request)
    {
        $n = $this->facility->addStandard($request->user());

        return back()->with('success', $n ? "{$n} devices added to rooms that had none." : 'Every room already has equipment listed.');
    }

    public function hubStore(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:78']);
        [$hub, $key] = $this->facility->newHub($d['name'], $request->user());

        return back()->with('success', "Hub \"{$hub->name}\" created.")->with('hub_key', $key);
    }

    public function hubRevoke(FacilityHub $hub)
    {
        $hub->update(['is_active' => false]);
        Audit::record('facility.hub_revoked', "Revoked hardware hub key \"{$hub->name}\"", $hub);

        return back()->with('success', "Hub \"{$hub->name}\" can no longer report.");
    }

    private function validated(Request $request, ?FacilityDevice $device = null): array
    {
        $d = $request->validate([
            'room_id' => 'nullable|integer|exists:rooms,id',
            'area' => 'nullable|string|max:60|required_without:room_id',
            'name' => 'required|string|max:80',
            'kind' => ['required', Rule::in(array_keys(FacilityDevice::KINDS))],
            'code' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._:-]+$/',
                Rule::unique('facility_devices', 'code')->where('hotel_id', \App\Support\Tenancy\CurrentHotel::get()->id())->ignore($device?->id)],
            'is_critical' => 'nullable|boolean', 'should_be_on' => 'nullable|boolean', 'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:255',
        ]);
        $d['room_id'] = $d['room_id'] ?? null;
        $d['area'] = $d['room_id'] ? null : ($d['area'] ?? null);
        $d['code'] = isset($d['code']) ? strtoupper($d['code']) : null;
        foreach (['is_critical', 'should_be_on'] as $b) {
            $d[$b] = $request->boolean($b);
        }
        $d['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        return $d;
    }
}
