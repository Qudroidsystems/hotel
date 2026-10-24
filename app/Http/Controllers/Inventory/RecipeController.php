<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\StockItem;
use App\Models\Pos\PosItem;
use Illuminate\Http\Request;

/** What each bar/restaurant item takes out of stock when it is sold. */
class RecipeController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()->canAny(['Manage stock items', 'Manage POS menu']), 403);

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $filter = in_array($request->input('show'), ['missing', 'all'], true) ? $request->input('show') : 'all';
        $posItems = PosItem::with(['category', 'components.stockItem', 'outlets:id,code,color'])
            ->where('is_active', true)
            ->when($filter === 'missing', fn ($q) => $q->doesntHave('components'))
            ->orderBy('category_id')->orderBy('name')->get();
        $stockItems = StockItem::active()->orderBy('name')->get(['id', 'name', 'unit', 'avg_cost']);
        $missing = PosItem::where('is_active', true)->doesntHave('components')->count();
        $pagetitle = 'Recipes';

        return view('inventory.recipes', compact('pagetitle', 'posItems', 'stockItems', 'filter', 'missing'));
    }

    public function update(Request $request, PosItem $item)
    {
        $data = $request->validate([
            'components'                 => 'nullable|array',
            'components.*.stock_item_id' => 'nullable|exists:stock_items,id',
            'components.*.quantity'      => 'nullable|numeric|min:0.001|max:99999',
        ]);
        $rows = collect($data['components'] ?? [])->filter(fn ($c) => ! empty($c['stock_item_id']) && (float) ($c['quantity'] ?? 0) > 0)
            ->keyBy('stock_item_id');
        $item->components()->delete();
        foreach ($rows as $c) {
            $item->components()->create(['stock_item_id' => $c['stock_item_id'], 'quantity' => $c['quantity']]);
        }

        return back()->with('success', "Recipe for {$item->name} saved." . ($rows->isEmpty() ? ' It no longer takes anything from stock.' : ''));
    }
}
