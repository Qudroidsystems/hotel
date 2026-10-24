<?php

namespace App\Http\Controllers\Inventory;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Inventory\StockReceipt;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierInvoice;
use App\Services\Inventory\ProcurementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Supplier invoices, what is owed and payments made (spec §8.3). */
class SupplierInvoiceController extends Controller
{
    public function __construct(private ProcurementService $procurement)
    {
        $this->middleware('permission:Manage supplier invoices|Pay suppliers');
        $this->middleware('permission:Manage supplier invoices')->only(['create', 'store', 'void']);
        $this->middleware('permission:Pay suppliers')->only(['pay']);
    }

    public function index(Request $request)
    {
        $show = in_array($request->query('show'), ['open', 'overdue', 'paid', 'void', 'all'], true) ? $request->query('show') : 'open';
        $supplierId = $request->integer('supplier') ?: null;

        $invoices = SupplierInvoice::with(['supplier:id,name', 'receipt:id,number'])
            ->when($show === 'open', fn ($q) => $q->open())
            ->when($show === 'overdue', fn ($q) => $q->open()->where('due_date', '<', today()))
            ->when(in_array($show, ['paid', 'void'], true), fn ($q) => $q->where('status', $show))
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->when($request->input('q'), fn ($q, $v) => $q->where(fn ($w) => $w->where('invoice_number', 'like', "%{$v}%")->orWhere('number', 'like', "%{$v}%")))
            ->orderBy('due_date')->paginate(30)->withQueryString();

        $open = SupplierInvoice::open()->with('supplier:id,name')->get(['id', 'supplier_id', 'amount', 'paid', 'due_date']);
        $owed = $open->groupBy('supplier_id')->map(fn ($g) => [
            'supplier' => $g->first()->supplier,
            'balance'  => round($g->sum(fn ($i) => (float) $i->amount - (float) $i->paid), 2),
            'overdue'  => round($g->filter(fn ($i) => $i->due_date->lt(today()))->sum(fn ($i) => (float) $i->amount - (float) $i->paid), 2),
            'count'    => $g->count(),
        ])->sortByDesc('balance')->values();
        $stats = [
            'owed'      => $owed->sum('balance'),
            'overdue'   => $owed->sum('overdue'),
            'due_week'  => round($open->filter(fn ($i) => $i->due_date->between(today(), today()->addDays(7)))->sum(fn ($i) => (float) $i->amount - (float) $i->paid), 2),
            'paid_month' => (float) \App\Models\Inventory\SupplierPayment::where('paid_on', '>=', today()->startOfMonth())->sum('amount'),
        ];
        $uninvoiced = StockReceipt::whereNotNull('supplier_id')->where('total', '>', 0)->whereDoesntHave('invoices', fn ($q) => $q->where('status', '!=', 'void'))
            ->with('supplier:id,name')->latest('received_on')->limit(8)->get();
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);
        $pagetitle = 'Supplier Invoices';

        return view('inventory.invoices.index', compact('pagetitle', 'invoices', 'show', 'supplierId', 'owed', 'stats', 'uninvoiced', 'suppliers'));
    }

    public function create(Request $request)
    {
        $receipt = $request->integer('receipt') ? StockReceipt::with(['supplier', 'purchaseOrder'])->find($request->integer('receipt')) : null;
        $suppliers = Supplier::active()->orderBy('name')->get(['id', 'name', 'payment_terms_days']);
        $receipts = StockReceipt::whereNotNull('supplier_id')->whereDoesntHave('invoices', fn ($q) => $q->where('status', '!=', 'void'))
            ->with('supplier:id,name')->latest('received_on')->limit(100)->get(['id', 'number', 'supplier_id', 'total', 'received_on', 'supplier_invoice']);
        $pagetitle = 'Record Supplier Invoice';

        return view('inventory.invoices.create', compact('pagetitle', 'receipt', 'suppliers', 'receipts'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'stock_receipt_id' => 'nullable|exists:stock_receipts,id',
            'supplier_id'      => 'nullable|required_without:stock_receipt_id|exists:suppliers,id',
            'invoice_number'   => 'required|string|max:60',
            'invoice_date'     => 'required|date|before_or_equal:today',
            'due_date'         => 'nullable|date|after_or_equal:invoice_date',
            'amount'           => 'required|numeric|min:0.01|max:9999999999',
            'notes'            => 'nullable|string|max:500',
        ], ['supplier_id.required_without' => 'Choose the supplier, or the delivery this invoice is for.']);
        if (! empty($data['stock_receipt_id'])) {
            unset($data['supplier_id']);
        }
        try {
            $inv = $this->procurement->recordInvoice($data, $request->user());
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.invoices.show', $inv)->with('success', "Invoice {$inv->invoice_number} recorded — due " . $inv->due_date->format('j M Y') . '.');
    }

    public function show(SupplierInvoice $invoice)
    {
        $invoice->load(['supplier', 'receipt.lines.item', 'purchaseOrder', 'payments.paidBy', 'recordedBy']);
        $match = $this->procurement->match($invoice);
        $pagetitle = $invoice->number;

        return view('inventory.invoices.show', compact('pagetitle', 'invoice', 'match'));
    }

    public function pay(Request $request, SupplierInvoice $invoice)
    {
        $data = $request->validate([
            'amount'    => 'required|numeric|min:0.01',
            'method'    => ['required', Rule::in(array_keys(SupplierInvoice::METHODS))],
            'reference' => 'nullable|string|max:80',
            'paid_on'   => 'required|date|before_or_equal:today',
            'notes'     => 'nullable|string|max:255',
        ]);

        return $this->attempt(fn () => $this->procurement->pay($invoice, $data, $request->user()), 'Payment recorded.');
    }

    public function void(Request $request, SupplierInvoice $invoice)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->procurement->voidInvoice($invoice, $data['reason'], $request->user()), 'Invoice voided.');
    }

    private function attempt(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }
}
