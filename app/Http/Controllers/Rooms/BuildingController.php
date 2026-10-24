<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Buildings / blocks and their floors. */
class BuildingController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View rooms|Manage buildings', ['only' => ['index']]);
        $this->middleware('permission:Manage buildings', ['except' => ['index']]);
    }

    public function index()
    {
        $pagetitle = 'Buildings & Floors';
        $buildings = Building::ordered()
            ->withCount('rooms')
            ->with(['floors' => fn ($q) => $q->withCount('rooms')])
            ->get();

        return view('rooms.buildings', compact('pagetitle', 'buildings'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'code'        => 'required|string|max:20|alpha_dash|unique:buildings,code',
            'description' => 'nullable|string|max:500',
            'sort_order'  => 'nullable|integer|min:0|max:999',
            'floors_from' => 'nullable|integer|min:-5|max:100',
            'floors_to'   => 'nullable|integer|min:-5|max:100|gte:floors_from',
        ]);

        $building = Building::create([
            'name'        => $data['name'],
            'code'        => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'sort_order'  => $data['sort_order'] ?? 0,
        ]);

        // Optionally create its floors in one go (e.g. 0 → 4 = Ground + 4 floors).
        if (isset($data['floors_from'], $data['floors_to'])) {
            foreach (range($data['floors_from'], $data['floors_to']) as $level) {
                $building->floors()->create(['level' => $level, 'name' => Floor::defaultName($level)]);
            }
        }

        return back()->with('success', "Building “{$building->name}” added.");
    }

    public function update(Request $request, Building $building)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'code'        => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('buildings', 'code')->ignore($building->id)],
            'description' => 'nullable|string|max:500',
            'sort_order'  => 'nullable|integer|min:0|max:999',
            'is_active'   => 'nullable|boolean',
        ]);

        $building->update([
            'name'        => $data['name'],
            'code'        => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'sort_order'  => $data['sort_order'] ?? 0,
            'is_active'   => $request->boolean('is_active'),
        ]);

        return back()->with('success', "Building “{$building->name}” updated.");
    }

    public function destroy(Building $building)
    {
        if ($building->rooms()->exists()) {
            return back()->with('error', "“{$building->name}” still has rooms. Move or delete them first.");
        }

        $building->delete();

        return back()->with('success', 'Building deleted.');
    }

    public function storeFloor(Request $request, Building $building)
    {
        $data = $request->validate([
            'level' => ['required', 'integer', 'min:-5', 'max:100', Rule::unique('floors')->where('building_id', $building->id)],
            'name'  => 'nullable|string|max:100',
        ], ['level.unique' => 'That floor level already exists in this building.']);

        $building->floors()->create([
            'level' => $data['level'],
            'name'  => $data['name'] ?: Floor::defaultName((int) $data['level']),
        ]);

        return back()->with('success', 'Floor added.');
    }

    public function updateFloor(Request $request, Floor $floor)
    {
        $data = $request->validate([
            'level' => ['required', 'integer', 'min:-5', 'max:100', Rule::unique('floors')->where('building_id', $floor->building_id)->ignore($floor->id)],
            'name'  => 'required|string|max:100',
        ], ['level.unique' => 'That floor level already exists in this building.']);

        $floor->update($data);

        return back()->with('success', 'Floor updated.');
    }

    public function destroyFloor(Floor $floor)
    {
        if ($floor->rooms()->exists()) {
            return back()->with('error', "{$floor->name} still has rooms. Move or delete them first.");
        }

        $floor->delete();

        return back()->with('success', 'Floor deleted.');
    }
}
