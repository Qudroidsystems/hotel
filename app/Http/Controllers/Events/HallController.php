<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\Events\EventPackage;
use App\Models\Events\Hall;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Halls (capacities and hire rates) and event packages (per-person catering). */
class HallController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage halls');
    }

    public function index()
    {
        return view('events.halls', [
            'pagetitle' => 'Halls & Packages',
            'halls'     => Hall::ordered()->withCount('events')->get(),
            'packages'  => EventPackage::orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $hall = Hall::create($this->hallData($request));
        Audit::record('events.hall_added', "Added hall {$hall->name}", $hall);

        return back()->with('success', "{$hall->name} added.");
    }

    public function update(Request $request, Hall $hall)
    {
        $hall->fill($this->hallData($request, $hall) + ['is_active' => $request->boolean('is_active')]);
        [$old, $new] = Audit::diff($hall);
        $hall->save();
        if ($new) {
            Audit::record('events.hall_changed', "Changed hall {$hall->name}", $hall, $old, $new);
        }

        return back()->with('success', "{$hall->name} saved. Events already booked keep their prices.");
    }

    public function storePackage(Request $request)
    {
        $p = EventPackage::create($this->packageData($request));
        Audit::record('events.package_added', "Added event package {$p->name}", $p);

        return back()->with('success', "{$p->name} added.");
    }

    public function updatePackage(Request $request, EventPackage $package)
    {
        $package->fill($this->packageData($request) + ['is_active' => $request->boolean('is_active')]);
        [$old, $new] = Audit::diff($package);
        $package->save();
        if ($new) {
            Audit::record('events.package_changed', "Changed event package {$package->name}", $package, $old, $new);
        }

        return back()->with('success', "{$package->name} saved.");
    }

    private function hallData(Request $request, ?Hall $hall = null): array
    {
        $data = $request->validate([
            'name'               => ['required', 'string', 'max:100', Rule::unique('halls', 'name')->ignore($hall?->id)],
            'capacity_theatre'   => 'nullable|integer|min:1|max:20000',
            'capacity_banquet'   => 'nullable|integer|min:1|max:20000',
            'capacity_classroom' => 'nullable|integer|min:1|max:20000',
            'capacity_boardroom' => 'nullable|integer|min:1|max:20000',
            'hourly_rate'        => 'nullable|numeric|min:0|max:100000000',
            'half_day_rate'      => 'nullable|numeric|min:0|max:100000000',
            'full_day_rate'      => 'nullable|numeric|min:0|max:100000000',
            'description'        => 'nullable|string|max:500',
            'color'              => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order'         => 'nullable|integer|min:0|max:999',
        ]);
        foreach (['hourly_rate', 'half_day_rate', 'full_day_rate', 'sort_order'] as $k) {
            $data[$k] = (float) ($data[$k] ?? 0);
        }
        $data['color'] ??= '#0f766e';

        return $data;
    }

    private function packageData(Request $request): array
    {
        return $request->validate([
            'name'             => 'required|string|max:100',
            'price_per_person' => 'required|numeric|min:0|max:100000000',
            'description'      => 'nullable|string|max:500',
        ]);
    }
}
