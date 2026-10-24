<?php

namespace App\Http\Controllers\Inventory;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockReceipt;
use App\Models\Inventory\Supplier;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Goods received notes: booking deliveries into a store. */
class ReceiptController extends Controller
{
    public function __construct(private StockService $stock)
    {
        $this->middleware('permission:View stock')->only(['index', 'show']);
        $this->middleware('permission:Receive stock')->only(['create', 'store']);
    }

    public function index(Request $request)
    {
        $receipts = StockReceipt::with(['supplier', 'location', 'receivedBy', 'purchaseOrder'])
            ->when($request->integer('supplier'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('number', 'like', "%{$v}%")->orWhere('supplier_invoice', 'like', "%{$v}%")))
            ->withCount('lines')->latest('id')->paginate(25)->withQueryString();
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $pagetitle = 'Goods Received';

        return view('inventory.receipts.index', compact('pagetitle', 'receipts', 'suppliers'));
    }

    public function create(Request $request)
    {
        $po = $request->integer('po') ? PurchaseOrder::with(['lines.item', 'supplier'])->find($request->integer('po')) : null;
        if ($po && ! $po->canReceive()) {
            return redirect()->route('inventory.po.show', $po)->with('error', "This order is " . strtolower($po->status_label) . '.');
        }
        $items = StockItem::active()->with('category')->orderBy('name')->get();
        $locations = StockLocation::active()->ordered()->get();
        $suppliers = Supplier::active()->orderBy('name')->get();
        $key = (string) Str::uuid();
        $pagetitle = $po ? "Receive {$po->number}" : 'Receive Stock';

        return view('inventory.receipts.create', compact('pagetitle', 'po', 'items', 'locations', 'suppliers', 'key'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_order_id'              => 'nullable|exists:purchase_orders,id',
            'supplier_id'                    => 'nullable|required_without:purchase_order_id|exists:suppliers,id',
            'location_id'                    => 'nullable|required_without:purchase_order_id|exists:stock_locations,id',
            'supplier_invoice'               => 'nullable|string|max:60',
            'received_on'                    => 'required|date|before_or_equal:today',
            'notes'                          => 'nullable|string|max:500',
            'idempotency_key'                => 'nullable|string|max:64',
            'lines'                          => 'required|array|min:1',
            'lines.*.item_id'                => 'required|exists:stock_items,id',
            'lines.*.quantity'               => 'nullable|numeric|min:0|max:99999999',
            'lines.*.unit_cost'              => 'required|numeric|min:0|max:99999999',
            'lines.*.purchase_order_line_id' => 'nullable|integer',
            'lines.*.rejected_quantity'      => 'nullable|numeric|min:0|max:99999999',
            'lines.*.reject_reason'          => 'nullable|string|max:120',
        ], ['supplier_id.required_without' => 'Choose the supplier.', 'location_id.required_without' => 'Choose the store the goods went into.']);

        $po = ! empty($data['purchase_order_id']) ? PurchaseOrder::find($data['purchase_order_id']) : null;
        try {
            $receipt = $this->stock->receive($data, $data['lines'], $po);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.receipts.show', $receipt)->with('success', "Received — {$receipt->number}.");
    }

    public function show(Request $request, StockReceipt $receipt)
    {
        $receipt->load(['supplier', 'location', 'receivedBy', 'purchaseOrder', 'lines.item', 'invoices']);
        $pagetitle = $receipt->number;
        $print = $request->boolean('print');

        return view($print ? 'inventory.receipts.print' : 'inventory.receipts.show', compact('pagetitle', 'receipt'));
    }
}
