<?php

namespace App\Http\Controllers\Inventory;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\Supplier;
use App\Services\Inventory\ProcurementService;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function __construct(private StockService $stock, private ProcurementService $procurement)
    {
        $this->middleware('permission:View stock')->only(['index', 'show']);
        $this->middleware('permission:Approve purchases')->only(['approve', 'reject']);
        $this->middleware('permission:Manage purchase orders')->except(['index', 'show', 'approve', 'reject']);
    }

    public function index(Request $request)
    {
        $status = array_key_exists((string) $request->input('status'), PurchaseOrder::STATUSES) ? $request->input('status') : null;
        $orders = PurchaseOrder::with(['supplier', 'location', 'createdBy'])->withCount('lines')
            ->when($status, fn ($q) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->when($request->integer('supplier'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->orderByRaw("case status when 'pending' then 0 when 'draft' then 1 when 'approved' then 2 when 'ordered' then 3 when 'partial' then 4 else 5 end")->latest('id')
            ->paginate(25)->withQueryString();
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $locations = StockLocation::active()->ordered()->get();
        $counts = PurchaseOrder::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $pagetitle = 'Purchase Orders';

        return view('inventory.po.index', compact('pagetitle', 'orders', 'suppliers', 'locations', 'status', 'counts'));
    }

    public function create(Request $request)
    {
        return $this->form(null, $request->integer('supplier') ?: null);
    }

    public function edit(PurchaseOrder $po)
    {
        if (! $po->isEditable()) {
            return redirect()->route('inventory.po.show', $po)->with('error', 'Only a draft order can be changed.');
        }

        return $this->form($po->load('lines.item'));
    }

    private function form(?PurchaseOrder $po, ?int $supplierId = null)
    {
        $items = StockItem::active()->with('category')->orderBy('name')->get();
        $locations = StockLocation::active()->ordered()->get();
        $suppliers = Supplier::active()->orderBy('name')->get();
        $pagetitle = $po ? "Edit {$po->number}" : 'New Purchase Order';

        return view('inventory.po.form', compact('pagetitle', 'po', 'items', 'locations', 'suppliers', 'supplierId'));
    }

    public function store(Request $request)
    {
        return $this->save($request, null);
    }

    public function update(Request $request, PurchaseOrder $po)
    {
        return $this->save($request, $po);
    }

    private function save(Request $request, ?PurchaseOrder $po)
    {
        $data = $request->validate([
            'supplier_id'       => 'required|exists:suppliers,id',
            'location_id'       => 'required|exists:stock_locations,id',
            'expected_on'       => 'nullable|date',
            'notes'             => 'nullable|string|max:500',
            'lines'             => 'required|array|min:1',
            'lines.*.item_id'   => 'nullable|exists:stock_items,id',
            'lines.*.quantity'  => 'nullable|numeric|min:0|max:99999999',
            'lines.*.unit_cost' => 'nullable|numeric|min:0|max:99999999',
            'send'              => 'nullable|boolean',
        ]);
        try {
            $po = $this->stock->savePurchaseOrder($po, $data, $data['lines']);
            $message = "{$po->number} saved as a draft.";
            if ($request->boolean('send')) {
                if ($po->needsApproval()) {
                    $this->procurement->submitOrder($po, $request->user());
                    $message = "{$po->number} sent for approval.";
                } else {
                    $this->stock->markOrdered($po);
                    $message = "{$po->number} marked as ordered.";
                }
            }
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.po.show', $po)->with('success', $message);
    }

    public function show(Request $request, PurchaseOrder $po)
    {
        $po->load(['supplier', 'location', 'lines.item', 'receipts.receivedBy', 'createdBy', 'orderedBy', 'submittedBy', 'approvedBy', 'invoices']);
        $prices = $this->procurement->priceCheck($po);
        $selfRaised = in_array($request->user()->id, array_filter([$po->created_by, $po->submitted_by]), true);
        $pagetitle = $po->number;

        return view($request->boolean('print') ? 'inventory.po.print' : 'inventory.po.show', compact('pagetitle', 'po', 'prices', 'selfRaised'));
    }

    public function order(PurchaseOrder $po)
    {
        return $this->attempt(fn () => $this->stock->markOrdered($po), "{$po->number} marked as ordered.");
    }

    public function submit(Request $request, PurchaseOrder $po)
    {
        return $this->attempt(fn () => $this->procurement->submitOrder($po, $request->user()), "{$po->number} sent for approval.");
    }

    public function approve(Request $request, PurchaseOrder $po)
    {
        $send = $request->boolean('send');

        return $this->attempt(fn () => $this->procurement->approveOrder($po, $request->user(), $send), "{$po->number} approved" . ($send ? ' and marked as ordered.' : '.'));
    }

    public function reject(Request $request, PurchaseOrder $po)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->procurement->rejectOrder($po, $data['reason'], $request->user()), "{$po->number} sent back to draft.");
    }

    public function cancel(Request $request, PurchaseOrder $po)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->stock->cancelPurchaseOrder($po, $data['reason']), "{$po->number} cancelled.");
    }

    public function reorder(Request $request)
    {
        $data = $request->validate(['location_id' => 'required|exists:stock_locations,id']);
        $made = $this->stock->draftReorders((int) $data['location_id']);

        return redirect()->route('inventory.po.index', ['status' => 'draft'])->with($made ? 'success' : 'error', $made
            ? count($made) . ' draft order(s) created — check quantities and prices, then mark them ordered.'
            : 'Nothing to reorder: no low items have a preferred supplier set.');
    }

    private function attempt(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }
}
