<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use App\Models\It\ItAsset;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The IT equipment register (spec §11.1 device inventory and configuration records). */
class AssetController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View IT control room|Manage IT monitoring');
        $this->middleware('permission:Manage IT monitoring')->except('index');
    }

    public function index(Request $request)
    {
        $type = array_key_exists((string) $request->query('type'), ItAsset::TYPES) ? $request->query('type') : null;
        $q = trim((string) $request->query('q'));
        $assets = ItAsset::withCount(['tickets', 'monitors'])->with('monitors:id,asset_id,status,is_active')
            ->when($type, fn ($w) => $w->where('type', $type))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('ip_address', 'like', "%{$q}%")
                ->orWhere('serial_number', 'like', "%{$q}%")->orWhere('location', 'like', "%{$q}%")))
            ->orderByDesc('is_active')->orderBy('type')->orderBy('name')->get();
        $counts = ItAsset::where('is_active', true)->get(['type', 'warranty_until']);
        $stats = [
            'total'    => $counts->count(),
            'expiring' => $counts->filter(fn ($a) => $a->warranty_until && $a->warranty_until->between(today(), today()->addDays(60)))->count(),
            'expired'  => $counts->filter(fn ($a) => $a->warranty_until && $a->warranty_until->isPast())->count(),
            'types'    => $counts->countBy('type'),
        ];
        $pagetitle = 'IT Equipment';

        return view('it.assets', compact('pagetitle', 'assets', 'type', 'q', 'stats'));
    }

    public function store(Request $request)
    {
        $asset = ItAsset::create($this->validated($request));
        Audit::record('it.asset_added', "Added IT equipment {$asset->name} ({$asset->type_label})", $asset);

        return back()->with('success', "{$asset->name} added.");
    }

    public function update(Request $request, ItAsset $asset)
    {
        $asset->fill($this->validated($request, $asset));
        [$old, $new] = Audit::diff($asset);
        $asset->save();
        if ($new) {
            Audit::record('it.asset_changed', "Changed IT equipment {$asset->name}", $asset, $old, $new);
        }

        return back()->with('success', "{$asset->name} saved.");
    }

    public function destroy(ItAsset $asset)
    {
        if ($asset->tickets()->exists() || $asset->monitors()->exists()) {
            $asset->update(['is_active' => false]);

            return back()->with('success', "{$asset->name} has tickets or monitors linked, so it was marked retired instead of deleted.");
        }
        Audit::record('it.asset_removed', "Removed IT equipment {$asset->name}", $asset);
        $asset->delete();

        return back()->with('success', 'Equipment removed.');
    }

    private function validated(Request $request, ?ItAsset $asset = null): array
    {
        $data = $request->validate([
            'name'           => 'required|string|max:120',
            'type'           => ['required', Rule::in(array_keys(ItAsset::TYPES))],
            'ip_address'     => 'nullable|string|max:64',
            'mac_address'    => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Fa-f]{2}([:-]?[0-9A-Fa-f]{2}){5}$/'],
            'location'       => 'nullable|string|max:120',
            'make_model'     => 'nullable|string|max:120',
            'serial_number'  => 'nullable|string|max:80',
            'firmware'       => 'nullable|string|max:60',
            'purchased_on'   => 'nullable|date|before_or_equal:today',
            'warranty_until' => 'nullable|date',
            'notes'          => 'nullable|string|max:1000',
        ], ['mac_address.regex' => 'Enter the MAC address like AA:BB:CC:DD:EE:FF.']);
        $data['mac_address'] = isset($data['mac_address']) ? strtoupper($data['mac_address']) : null;
        $data['is_active'] = $asset ? $request->boolean('is_active') : true;

        return $data;
    }
}
