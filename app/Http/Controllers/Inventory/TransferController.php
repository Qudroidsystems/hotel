<?php

namespace App\Http\Controllers\Inventory;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLevel;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockTransfer;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Moving stock between stores (main store → bar, kitchen, housekeeping). */
class TransferController extends Controller
{
    public function __construct(private StockService $stock)
    {
        $this->middleware('permission:View stock')->only(['index', 'show']);
        $this->middleware('permission:Transfer stock')->only(['create', 'store']);
    }

    public function index(Request $request)
    {
        $transfers = StockTransfer::with(['from', 'to', 'createdBy'])->withCount('lines')
            ->when($request->integer('location'), fn ($q, $v) => $q->where(fn ($w) => $w->where('from_location_id', $v)->orWhere('to_location_id', $v)))
            ->latest('id')->paginate(25)->withQueryString();
        $locations = StockLocation::ordered()->get();
        $pagetitle = 'Stock Transfers';

        return view('inventory.transfers.index', compact('pagetitle', 'transfers', 'locations'));
    }

    public function create(Request $request)
    {
        $items = StockItem::active()->with('category')->orderBy('name')->get();
        $locations = StockLocation::active()->ordered()->get();
        // What each store holds, so the form can show "available".
        $levels = StockLevel::all()->groupBy('location_id')->map(fn ($g) => $g->pluck('quantity', 'item_id')->map(fn ($q) => (float) $q));
        $key = (string) Str::uuid();
        $pagetitle = 'Transfer Stock';

        return view('inventory.transfers.create', compact('pagetitle', 'items', 'locations', 'levels', 'key'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'from_location_id' => 'required|exists:stock_locations,id',
            'to_location_id'   => 'required|exists:stock_locations,id|different:from_location_id',
            'notes'            => 'nullable|string|max:500',
            'idempotency_key'  => 'nullable|string|max:64',
            'lines'            => 'required|array|min:1',
            'lines.*.item_id'  => 'required|exists:stock_items,id',
            'lines.*.quantity' => 'nullable|numeric|min:0|max:99999999',
        ], ['to_location_id.different' => 'Choose two different stores.']);
        try {
            $t = $this->stock->transfer((int) $data['from_location_id'], (int) $data['to_location_id'], $data['lines'], $data['notes'] ?? null, $data['idempotency_key'] ?? null);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.transfers.show', $t)->with('success', "Transferred — {$t->number}.");
    }

    public function show(Request $request, StockTransfer $transfer)
    {
        $transfer->load(['from', 'to', 'createdBy', 'lines.item']);
        $pagetitle = $transfer->number;

        return view($request->boolean('print') ? 'inventory.transfers.print' : 'inventory.transfers.show', compact('pagetitle', 'transfer'));
    }
}
