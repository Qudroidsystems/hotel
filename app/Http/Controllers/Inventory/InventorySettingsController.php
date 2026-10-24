<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\StockCategory;
use App\Models\Inventory\StockLocation;
use App\Models\Pos\PosOutlet;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Stores (locations), stock categories, and which store each POS outlet sells from. */
class InventorySettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage stock items');
    }

    public function index()
    {
        $locations = StockLocation::ordered()->withCount(['levels as items_count' => fn ($q) => $q->where('quantity', '!=', 0)])->get();
        $categories = StockCategory::ordered()->withCount('items')->get();
        $outlets = PosOutlet::ordered()->get();
        $pagetitle = 'Stores & Categories';

        return view('inventory.settings', compact('pagetitle', 'locations', 'categories', 'outlets'));
    }

    public function storeLocation(Request $request)
    {
        StockLocation::create($this->location($request));

        return back()->with('success', 'Store added.');
    }

    public function updateLocation(Request $request, StockLocation $location)
    {
        $location->update($this->location($request, $location));

        return back()->with('success', 'Store updated.');
    }

    private function location(Request $request, ?StockLocation $l = null): array
    {
        $request->merge(['code' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('code')))]);
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'code'       => ['required', 'string', 'min:2', 'max:12', Rule::unique('stock_locations', 'code')->ignore($l?->id)],
            'type'       => ['required', Rule::in(array_keys(StockLocation::TYPES))],
            'sort_order' => 'nullable|integer|min:0|max:999',
        ]);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $l ? $request->boolean('is_active') : true;

        return $data;
    }

    public function storeCategory(Request $request)
    {
        StockCategory::create($request->validate(['name' => 'required|string|max:80|unique:stock_categories,name', 'sort_order' => 'nullable|integer|min:0']));

        return back()->with('success', 'Category added.');
    }

    public function updateCategory(Request $request, StockCategory $category)
    {
        $category->update($request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('stock_categories', 'name')->ignore($category->id)], 'sort_order' => 'nullable|integer|min:0']));

        return back()->with('success', 'Category updated.');
    }

    public function outlets(Request $request)
    {
        $data = $request->validate(['outlet' => 'array', 'outlet.*' => 'nullable|exists:stock_locations,id']);
        foreach ($data['outlet'] ?? [] as $outletId => $locationId) {
            PosOutlet::whereKey($outletId)->update(['stock_location_id' => $locationId ?: null]);
        }

        return back()->with('success', 'Outlet stores saved. New sales take stock from these stores.');
    }
}
