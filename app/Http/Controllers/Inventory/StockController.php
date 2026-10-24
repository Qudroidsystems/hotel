<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Inventory\StockCategory;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\Supplier;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Stock on hand, stock items, and hand adjustments (used / waste / correction). */
class StockController extends Controller
{
    public function __construct(private StockService $stock)
    {
        $this->middleware('permission:View stock')->only(['index', 'show']);
        $this->middleware('permission:Manage stock items')->only(['store', 'update']);
        $this->middleware('permission:Adjust stock')->only(['adjust']);
    }

    public function index(Request $request)
    {
        $locations = StockLocation::active()->ordered()->get();
        $categories = StockCategory::ordered()->get();
        $tab = in_array($request->input('tab'), ['all', 'low', 'negative'], true) ? $request->input('tab') : 'all';
        $f = [
            'q'        => trim((string) $request->input('q')),
            'category' => $request->integer('category') ?: null,
            'location' => $request->integer('location') ?: null,
            'inactive' => $request->boolean('inactive'),
        ];

        $items = StockItem::with(['category', 'levels', 'supplier'])
            ->when(! $f['inactive'], fn ($q) => $q->where('is_active', true))
            ->when($f['category'], fn ($q, $v) => $q->where('category_id', $v))
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$f['q']}%")->orWhere('sku', 'like', "%{$f['q']}%")))
            ->orderBy('name')->get()
            ->each(function (StockItem $i) {
                $i->setAttribute('on_hand', (float) $i->levels->sum('quantity'));
                $i->setAttribute('by_location', $i->levels->pluck('quantity', 'location_id')->map(fn ($q) => (float) $q));
            });

        $counts = [
            'all'      => $items->count(),
            'low'      => $items->filter(fn ($i) => $i->isLow($i->on_hand))->count(),
            'negative' => $items->filter(fn ($i) => $i->by_location->contains(fn ($q) => $q < 0))->count(),
        ];
        $list = match ($tab) {
            'low'      => $items->filter(fn ($i) => $i->isLow($i->on_hand)),
            'negative' => $items->filter(fn ($i) => $i->by_location->contains(fn ($q) => $q < 0)),
            default    => $items,
        };
        if ($f['location']) {
            $list = $list->filter(fn ($i) => $i->by_location->has($f['location']));
        }

        $stats = [
            'value'    => $this->stock->stockValue($f['location']),
            'items'    => $counts['all'],
            'low'      => $counts['low'],
            'negative' => $counts['negative'],
        ];
        $suppliers = Supplier::active()->orderBy('name')->get(['id', 'name']);
        $pagetitle = 'Stock';

        return view('inventory.index', compact('pagetitle', 'list', 'locations', 'categories', 'tab', 'f', 'counts', 'stats', 'suppliers'));
    }

    public function show(Request $request, StockItem $item)
    {
        $item->load(['category', 'supplier', 'levels.location', 'usedIn.posItem']);
        $locations = StockLocation::active()->ordered()->get();
        $type = StockMovementType::tryFrom((string) $request->input('type'));
        $movements = $item->movements()->with(['location', 'user', 'source'])
            ->when($request->integer('location'), fn ($q, $v) => $q->where('location_id', $v))
            ->when($type, fn ($q) => $q->where('type', $type->value))
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(30)->withQueryString();

        // Last 30 days, per kind of movement.
        $month = $item->movements()->where('occurred_at', '>=', now()->subDays(30))
            ->select('type', DB::raw('sum(quantity) as qty'), DB::raw('sum(value) as val'))->groupBy('type')->get()
            ->mapWithKeys(fn ($r) => [$r->type->value => ['qty' => (float) $r->qty, 'val' => (float) $r->val]]);

        $categories = StockCategory::ordered()->get();
        $suppliers = Supplier::active()->orderBy('name')->get(['id', 'name']);
        $prices = app(\App\Services\Inventory\ProcurementService::class)->priceHistory($item->id);
        $pagetitle = $item->name;

        return view('inventory.item', compact('pagetitle', 'item', 'locations', 'movements', 'month', 'categories', 'suppliers', 'type', 'prices'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $opening = $request->validate([
            'opening_location' => 'nullable|exists:stock_locations,id',
            'opening_qty'      => 'nullable|numeric|min:0|max:99999999',
            'opening_cost'     => 'nullable|numeric|min:0|max:99999999',
        ]);

        $item = DB::transaction(function () use ($data, $opening) {
            $item = StockItem::create($data);
            if (! empty($opening['opening_location']) && (float) ($opening['opening_qty'] ?? 0) > 0) {
                $this->stock->opening($item, (int) $opening['opening_location'], (float) $opening['opening_qty'], (float) ($opening['opening_cost'] ?? 0));
            } elseif (isset($opening['opening_cost'])) {
                $item->forceFill(['avg_cost' => (float) $opening['opening_cost'], 'last_cost' => (float) $opening['opening_cost']])->save();
            }

            return $item;
        });

        return redirect()->route('inventory.items.show', $item)->with('success', "{$item->name} added.");
    }

    public function update(Request $request, StockItem $item)
    {
        $item->update($this->validated($request, $item));

        return back()->with('success', 'Item updated.');
    }

    public function adjust(Request $request, StockItem $item)
    {
        $data = $request->validate([
            'location_id' => 'required|exists:stock_locations,id',
            'type'        => ['required', Rule::in(array_map(fn ($t) => $t->value, StockMovementType::manual()))],
            'direction'   => 'nullable|in:in,out',
            'quantity'    => 'required|numeric|min:0.001|max:99999999',
            'reason'      => 'required|string|max:255',
        ]);
        $type = StockMovementType::from($data['type']);
        $qty = (float) $data['quantity'] * ($type === StockMovementType::Adjustment && ($data['direction'] ?? 'out') === 'in' ? 1 : -1);

        try {
            $this->stock->adjust($item, (int) $data['location_id'], $type, $qty, $data['reason']);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $type->label() . ' recorded.');
    }

    private function validated(Request $request, ?StockItem $item = null): array
    {
        $data = $request->validate([
            'name'                  => 'required|string|max:120',
            'sku'                   => ['nullable', 'string', 'max:40', Rule::unique('stock_items', 'sku')->ignore($item?->id)],
            'category_id'           => 'nullable|exists:stock_categories,id',
            'unit'                  => 'required|string|max:20',
            'reorder_level'         => 'nullable|numeric|min:0|max:99999999',
            'reorder_qty'           => 'nullable|numeric|min:0|max:99999999',
            'preferred_supplier_id' => 'nullable|exists:suppliers,id',
            'notes'                 => 'nullable|string|max:255',
        ]);
        $data['reorder_level'] = (float) ($data['reorder_level'] ?? 0);
        $data['is_active'] = $item ? $request->boolean('is_active') : true;

        return $data;
    }
}
