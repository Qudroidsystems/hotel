<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Pos\PosCategory;
use App\Models\Pos\PosItem;
use App\Models\Pos\PosOrderItem;
use App\Models\Pos\PosOutlet;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Menu set-up: outlets (bar, restaurant…), categories and items with prices.
 * Tills pick up changes the next time they are online.
 */
class PosMenuController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage POS menu');
    }

    public function index(Request $request)
    {
        $tab = in_array($request->input('tab'), ['items', 'categories', 'outlets'], true) ? $request->input('tab') : 'items';
        $outlets = PosOutlet::ordered()->withCount('items')->get();
        $categories = PosCategory::ordered()->withCount('items')->get();
        $q = trim((string) $request->input('q'));
        $items = PosItem::with(['category', 'outlets:id,code,color'])
            ->when($request->integer('category'), fn ($w, $v) => $w->where('category_id', $v))
            ->when($request->integer('outlet'), fn ($w, $v) => $w->whereHas('outlets', fn ($o) => $o->whereKey($v)))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")))
            ->orderBy('category_id')->orderBy('sort_order')->orderBy('name')
            ->get();
        $pagetitle = 'POS Menu';

        return view('pos.menu', compact('pagetitle', 'tab', 'outlets', 'categories', 'items', 'q'));
    }

    // ── Items ─────────────────────────────────────────────────────────

    public function storeItem(Request $request)
    {
        $item = PosItem::create($this->itemData($request));
        $item->outlets()->sync($request->input('outlets', []));

        return back()->with('success', "{$item->name} added to the menu.");
    }

    public function updateItem(Request $request, PosItem $item)
    {
        $item->update($this->itemData($request, $item));
        $item->outlets()->sync($request->input('outlets', []));

        return back()->with('success', "{$item->name} updated.");
    }

    public function destroyItem(PosItem $item)
    {
        // Items that have been sold stay for the sales history.
        if (PosOrderItem::where('item_id', $item->id)->exists()) {
            $item->update(['is_active' => false]);

            return back()->with('success', "{$item->name} has sales on record, so it was taken off the menu instead of deleted.");
        }
        $item->delete();

        return back()->with('success', 'Item deleted.');
    }

    /** Quick price / on-off change from the item list. */
    public function quickItem(Request $request, PosItem $item)
    {
        $data = $request->validate(['price' => 'nullable|numeric|min:0|max:99999999', 'is_active' => 'nullable|boolean']);
        $item->update(array_filter($data, fn ($v) => $v !== null));

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', "{$item->name} updated.");
    }

    private function itemData(Request $request, ?PosItem $item = null): array
    {
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'category_id' => 'required|exists:pos_categories,id',
            'price'       => 'required|numeric|min:0|max:99999999',
            'charge_type' => ['required', Rule::in(array_map(fn ($t) => $t->value, PosItem::CHARGE_TYPES))],
            'sku'         => ['nullable', 'string', 'max:40', Rule::unique('pos_items', 'sku')->ignore($item?->id)],
            'description' => 'nullable|string|max:255',
            'sort_order'  => 'nullable|integer|min:0|max:9999',
            'outlets'     => 'nullable|array',
            'outlets.*'   => 'integer|exists:pos_outlets,id',
        ]);
        unset($data['outlets']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    // ── Categories ────────────────────────────────────────────────────

    public function storeCategory(Request $request)
    {
        PosCategory::create($this->categoryData($request));

        return redirect()->route('pos.menu', ['tab' => 'categories'])->with('success', 'Category added.');
    }

    public function updateCategory(Request $request, PosCategory $category)
    {
        $category->update($this->categoryData($request, $category));

        return redirect()->route('pos.menu', ['tab' => 'categories'])->with('success', 'Category updated.');
    }

    public function destroyCategory(PosCategory $category)
    {
        if ($category->items()->exists()) {
            return back()->with('error', 'Move or delete the items in this category first.');
        }
        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    private function categoryData(Request $request, ?PosCategory $category = null): array
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:80', Rule::unique('pos_categories', 'name')->ignore($category?->id)],
            'color'      => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    // ── Outlets ───────────────────────────────────────────────────────

    public function storeOutlet(Request $request)
    {
        PosOutlet::create($this->outletData($request));

        return redirect()->route('pos.menu', ['tab' => 'outlets'])->with('success', 'Outlet added.');
    }

    public function updateOutlet(Request $request, PosOutlet $outlet)
    {
        $outlet->update($this->outletData($request, $outlet));

        return redirect()->route('pos.menu', ['tab' => 'outlets'])->with('success', 'Outlet updated.');
    }

    public function destroyOutlet(PosOutlet $outlet)
    {
        if ($outlet->orders()->exists()) {
            $outlet->update(['is_active' => false]);

            return back()->with('success', "{$outlet->name} has sales on record, so it was closed instead of deleted.");
        }
        $outlet->delete();

        return back()->with('success', 'Outlet deleted.');
    }

    private function outletData(Request $request, ?PosOutlet $outlet = null): array
    {
        $request->merge(['code' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('code')))]);
        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'code'           => ['required', 'string', 'min:2', 'max:6', Rule::unique('pos_outlets', 'code')->ignore($outlet?->id)],
            'type'           => ['required', Rule::in(array_keys(PosOutlet::TYPES))],
            'color'          => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'receipt_footer' => 'nullable|string|max:255',
            'sort_order'     => 'nullable|integer|min:0|max:999',
        ], ['code.unique' => 'Another outlet already uses that code.']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['allow_room_charge'] = $request->boolean('allow_room_charge');
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
