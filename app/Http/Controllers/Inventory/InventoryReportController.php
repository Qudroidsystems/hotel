<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\StockMovementType as T;
use App\Http\Controllers\Controller;
use App\Models\Inventory\StockCategory;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Pos\PosItem;
use App\Models\Pos\PosOrder;
use App\Models\Pos\PosOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View stock reports');
    }

    public function index(Request $request)
    {
        $tab = in_array($request->input('tab'), ['valuation', 'margins', 'losses', 'movements'], true) ? $request->input('tab') : 'valuation';
        $from = $this->date($request->input('from'), today()->startOfMonth());
        $to = $this->date($request->input('to'), today());
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $locations = StockLocation::ordered()->get();
        $categories = StockCategory::ordered()->get();
        $locationId = $request->integer('location') ?: null;
        $data = [];

        if ($tab === 'valuation') {
            // Stock value now, per store and category, at average cost.
            $rows = DB::table('stock_levels')->join('stock_items', 'stock_items.id', '=', 'stock_levels.item_id')
                ->where('stock_levels.quantity', '>', 0)
                ->selectRaw('stock_levels.location_id, stock_items.category_id, sum(stock_levels.quantity * stock_items.avg_cost) as value, count(*) as lines')
                ->groupBy('stock_levels.location_id', 'stock_items.category_id')->get();
            $data['grid'] = $rows->groupBy('category_id')->map(fn ($g) => $g->pluck('value', 'location_id')->map(fn ($v) => (float) $v));
            $data['byLocation'] = $rows->groupBy('location_id')->map(fn ($g) => (float) $g->sum('value'));
            $data['total'] = (float) $rows->sum('value');
        }

        if ($tab === 'margins') {
            // Bar & restaurant: what was sold, for how much, and what the stock behind it cost.
            $orderIds = PosOrder::where('status', '!=', PosOrder::VOIDED)->whereBetween('ordered_at', $range)
                ->when($request->integer('outlet'), fn ($q, $v) => $q->where('outlet_id', $v))->pluck('id');
            $sold = PosOrderItem::whereIn('order_id', $orderIds)
                ->selectRaw('item_id, name, sum(quantity) as qty, sum(total) as revenue')->groupBy('item_id', 'name')->get();
            $recipes = PosItem::with('components.stockItem')->whereIn('id', $sold->pluck('item_id')->filter())->get()->keyBy('id');
            $data['rows'] = $sold->map(function ($r) use ($recipes) {
                $recipe = $recipes[$r->item_id] ?? null;
                $unitCost = $recipe ? $recipe->components->sum(fn ($c) => (float) $c->quantity * (float) $c->stockItem->avg_cost) : null;
                $cost = $unitCost !== null && $recipe->components->isNotEmpty() ? $unitCost * (float) $r->qty : null;

                return (object) [
                    'name' => $r->name, 'qty' => (float) $r->qty, 'revenue' => (float) $r->revenue, 'cost' => $cost,
                    'margin' => $cost !== null ? (float) $r->revenue - $cost : null,
                    'pct' => $cost !== null && (float) $r->revenue > 0 ? ((float) $r->revenue - $cost) / (float) $r->revenue * 100 : null,
                ];
            })->sortByDesc('revenue')->values();
            // Actual cost booked in the ledger for these sales (cost at the time of sale).
            $data['ledgerCost'] = -(float) StockMovement::whereIn('type', [T::Sale->value, T::SaleVoid->value])
                ->where('source_type', (new PosOrder)->getMorphClass())->whereIn('source_id', $orderIds)->sum('value');
            $data['revenue'] = (float) $data['rows']->sum('revenue');
            $data['noRecipe'] = $data['rows']->whereNull('cost')->count();
            $data['outlets'] = \App\Models\Pos\PosOutlet::ordered()->get();
        }

        if ($tab === 'losses') {
            // Waste, items used in-house, and stocktake differences.
            $types = [T::Waste->value, T::Issue->value, T::Count->value, T::Adjustment->value];
            $rows = StockMovement::with('item')->whereIn('type', $types)->whereBetween('occurred_at', $range)
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->selectRaw('item_id, type, sum(quantity) as qty, sum(value) as value')->groupBy('item_id', 'type')->get();
            $data['byType'] = collect($types)->mapWithKeys(fn ($t) => [$t => (float) $rows->where('type', T::from($t))->sum('value')]);
            $data['rows'] = $rows->groupBy('item_id')->map(fn ($g) => (object) [
                'item'  => $g->first()->item,
                'types' => $g->mapWithKeys(fn ($r) => [$r->type->value => ['qty' => (float) $r->qty, 'value' => (float) $r->value]]),
                'value' => (float) $g->sum('value'),
            ])->sortBy('value')->values();
        }

        if ($tab === 'movements') {
            $type = T::tryFrom((string) $request->input('type'));
            $q = trim((string) $request->input('q'));
            $base = StockMovement::whereBetween('occurred_at', $range)
                ->when($locationId, fn ($w) => $w->where('location_id', $locationId))
                ->when($type, fn ($w) => $w->where('type', $type->value))
                ->when($q !== '', fn ($w) => $w->whereHas('item', fn ($i) => $i->where('name', 'like', "%{$q}%")));
            $data['summary'] = (clone $base)->selectRaw('type, count(*) as n, sum(value) as value')->groupBy('type')->get();
            $data['movements'] = (clone $base)->with(['item', 'location', 'user', 'source'])->orderByDesc('occurred_at')->orderByDesc('id')->paginate(40)->withQueryString();
            $data['type'] = $type;
            $data['q'] = $q;
        }

        $pagetitle = 'Stock Reports';

        return view('inventory.reports', compact('pagetitle', 'tab', 'from', 'to', 'locations', 'categories', 'locationId', 'data'));
    }

    private function date($value, Carbon $fallback): Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfDay() : $fallback->copy();
        } catch (\Throwable) {
            return $fallback->copy();
        }
    }
}
