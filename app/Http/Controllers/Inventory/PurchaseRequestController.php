<?php

namespace App\Http\Controllers\Inventory;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Inventory\PurchaseRequest;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\Supplier;
use App\Services\Inventory\ProcurementService;
use Illuminate\Http\Request;

/** Purchase requests: a department asks, a manager approves, the buyer orders (spec §8.3). */
class PurchaseRequestController extends Controller
{
    public function __construct(private ProcurementService $procurement)
    {
        $this->middleware('permission:Request purchases|Approve purchases|Manage purchase orders')->only(['index', 'show']);
        $this->middleware('permission:Request purchases')->only(['create', 'store']);
        $this->middleware('permission:Approve purchases')->only(['decide']);
        $this->middleware('permission:Manage purchase orders')->only(['order']);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $seeAll = $user->canAny(['Approve purchases', 'Manage purchase orders']);
        $status = array_key_exists((string) $request->query('status'), PurchaseRequest::STATUSES) ? $request->query('status') : null;

        $requests = PurchaseRequest::with(['requester:id,name', 'decidedBy:id,name', 'purchaseOrder:id,number'])->withCount('lines')
            ->when($status, fn ($q) => $q->where('status', $status), fn ($q) => $q->whereIn('status', ['submitted', 'approved']))
            ->when(! $seeAll, fn ($q) => $q->where('requested_by', $user->id))
            ->orderByRaw("case status when 'submitted' then 0 when 'approved' then 1 else 2 end")->latest('id')
            ->paginate(25)->withQueryString();
        $counts = PurchaseRequest::when(! $seeAll, fn ($q) => $q->where('requested_by', $user->id))
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $pagetitle = 'Purchase Requests';

        return view('inventory.requests.index', compact('pagetitle', 'requests', 'status', 'counts', 'seeAll'));
    }

    public function create()
    {
        $items = StockItem::active()->with('category')->orderBy('name')->get();
        $pagetitle = 'New Purchase Request';

        return view('inventory.requests.create', compact('pagetitle', 'items'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'department'        => 'required|string|max:80',
            'needed_by'         => 'nullable|date|after_or_equal:today',
            'reason'            => 'nullable|string|max:500',
            'lines'             => 'required|array|min:1',
            'lines.*.item_id'   => 'nullable|exists:stock_items,id',
            'lines.*.quantity'  => 'nullable|numeric|min:0|max:99999999',
            'lines.*.unit_cost' => 'nullable|numeric|min:0|max:99999999',
        ]);
        try {
            $pr = $this->procurement->submitRequest($data, $data['lines'], $request->user());
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.requests.show', $pr)->with('success', "{$pr->number} sent for approval.");
    }

    public function show(Request $request, PurchaseRequest $pr)
    {
        $user = $request->user();
        abort_unless($user->canAny(['Approve purchases', 'Manage purchase orders']) || $pr->requested_by === $user->id, 403);
        $pr->load(['lines.item.supplier', 'requester', 'decidedBy', 'purchaseOrder']);
        $suppliers = Supplier::active()->orderBy('name')->get(['id', 'name']);
        $locations = StockLocation::active()->ordered()->get();
        $suggested = $pr->lines->pluck('item.preferred_supplier_id')->filter()->countBy()->sortDesc()->keys()->first();
        $pagetitle = $pr->number;

        return view('inventory.requests.show', compact('pagetitle', 'pr', 'suppliers', 'locations', 'suggested'));
    }

    public function decide(Request $request, PurchaseRequest $pr)
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:255']);
        $approve = $data['decision'] === 'approve';

        return $this->attempt(fn () => $this->procurement->decideRequest($pr, $approve, $data['note'] ?? null, $request->user()), "{$pr->number} " . ($approve ? 'approved.' : 'rejected.'));
    }

    public function cancel(Request $request, PurchaseRequest $pr)
    {
        abort_unless($pr->requested_by === $request->user()->id || $request->user()->can('Approve purchases'), 403);

        return $this->attempt(fn () => $this->procurement->cancelRequest($pr, $request->user()), "{$pr->number} cancelled.");
    }

    public function order(Request $request, PurchaseRequest $pr)
    {
        $data = $request->validate(['supplier_id' => 'required|exists:suppliers,id', 'location_id' => 'required|exists:stock_locations,id']);
        try {
            $po = $this->procurement->orderRequest($pr, (int) $data['supplier_id'], (int) $data['location_id'], $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.po.show', $po)->with('success', "Draft {$po->number} created from {$pr->number}. Check the prices, then send it.");
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
