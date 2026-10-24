<?php

namespace App\Services\Inventory;

use App\Exceptions\BookingException;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\PurchaseRequest;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockReceipt;
use App\Models\Inventory\StockReceiptLine;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierInvoice;
use App\Models\Inventory\SupplierPayment;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Procurement controls (spec §8.3): requests → approval → purchase order →
 * delivery → supplier invoice → payment. Nobody approves their own request or
 * order, and every decision lands on the audit trail.
 */
class ProcurementService
{
    public function __construct(private StockService $stock)
    {
    }

    // ── Purchase requests ─────────────────────────────────────────────

    public function submitRequest(array $head, array $lines, User $by): PurchaseRequest
    {
        $lines = array_values(array_filter($lines, fn ($l) => ! empty($l['item_id']) && (float) ($l['quantity'] ?? 0) > 0));
        if (! $lines) {
            throw new BookingException('Add at least one item.');
        }

        return DB::transaction(function () use ($head, $lines, $by) {
            $pr = PurchaseRequest::create(['department' => $head['department'], 'needed_by' => $head['needed_by'] ?? null, 'reason' => $head['reason'] ?? null, 'requested_by' => $by->id]);
            $total = 0;
            foreach ($lines as $l) {
                $cost = isset($l['unit_cost']) && $l['unit_cost'] !== '' ? (float) $l['unit_cost'] : (float) (StockItem::find($l['item_id'])?->last_cost ?? 0);
                $pr->lines()->create(['item_id' => $l['item_id'], 'quantity' => round((float) $l['quantity'], 3), 'unit_cost' => round($cost, 4)]);
                $total += (float) $l['quantity'] * $cost;
            }
            $pr->forceFill(['estimated_total' => Money::round($total)])->save();
            $this->number($pr, 'PR');
            Audit::record('inventory.request_submitted', "{$pr->department} requested {$pr->number} (about " . $this->money($pr->estimated_total) . ')', $pr, by: $by);

            return $pr;
        });
    }

    public function decideRequest(PurchaseRequest $pr, bool $approve, ?string $note, User $by): void
    {
        if ($pr->status !== 'submitted') {
            throw new BookingException("{$pr->number} has already been " . strtolower($pr->status_label) . '.');
        }
        if ($pr->requested_by === $by->id) {
            throw new BookingException('Someone else has to approve your own request.');
        }
        if (! $approve && blank($note)) {
            throw new BookingException('Say why the request is rejected.');
        }
        $pr->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_at' => now(), 'decided_by' => $by->id, 'decision_note' => $note])->save();
        Audit::record('inventory.request_' . ($approve ? 'approved' : 'rejected'), ($approve ? 'Approved' : 'Rejected') . " purchase request {$pr->number} from {$pr->department}", $pr, reason: $note, by: $by);
    }

    public function cancelRequest(PurchaseRequest $pr, User $by): void
    {
        if (! in_array($pr->status, ['submitted', 'approved'], true)) {
            throw new BookingException("{$pr->number} can no longer be cancelled.");
        }
        $pr->forceFill(['status' => 'cancelled', 'decided_at' => now(), 'decided_by' => $by->id])->save();
    }

    /** Turns an approved request into a draft purchase order with the chosen supplier. */
    public function orderRequest(PurchaseRequest $pr, int $supplierId, int $locationId, User $by): PurchaseOrder
    {
        if ($pr->status !== 'approved') {
            throw new BookingException('Only an approved request can be ordered.');
        }

        return DB::transaction(function () use ($pr, $supplierId, $locationId, $by) {
            $po = $this->stock->savePurchaseOrder(null, [
                'supplier_id' => $supplierId, 'location_id' => $locationId, 'expected_on' => $pr->needed_by,
                'notes'       => "From {$pr->number} ({$pr->department})" . ($pr->reason ? ": {$pr->reason}" : ''),
            ], $pr->lines->map(fn ($l) => ['item_id' => $l->item_id, 'quantity' => (float) $l->quantity, 'unit_cost' => (float) $l->unit_cost])->all());
            $po->forceFill(['created_by' => $by->id])->save();
            $pr->forceFill(['status' => 'ordered', 'purchase_order_id' => $po->id])->save();

            return $po;
        });
    }

    // ── Purchase order approval ───────────────────────────────────────

    public function submitOrder(PurchaseOrder $po, User $by): void
    {
        if ($po->status !== 'draft') {
            throw new BookingException('Only a draft order can be sent for approval.');
        }
        $po->forceFill(['status' => 'pending', 'submitted_at' => now(), 'submitted_by' => $by->id, 'rejection_reason' => null])->save();
        $jumps = collect($this->priceCheck($po))->where('jump', true)->count();
        Audit::record('inventory.po_submitted', "Sent {$po->number} for approval (" . $this->money($po->total) . ')' . ($jumps ? " — {$jumps} price(s) well above the last paid" : ''),
            $po, flagged: $jumps > 0, by: $by);
    }

    public function approveOrder(PurchaseOrder $po, User $by, bool $send = false): void
    {
        $this->ensurePending($po);
        if (in_array($by->id, array_filter([$po->created_by, $po->submitted_by]), true)) {
            throw new BookingException('You raised this order, so someone else has to approve it.');
        }
        DB::transaction(function () use ($po, $by, $send) {
            $po->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $by->id])->save();
            Audit::record('inventory.po_approved', "Approved {$po->number} for {$po->supplier?->name} (" . $this->money($po->total) . ')', $po, by: $by);
            if ($send) {
                $this->stock->markOrdered($po);
            }
        });
    }

    public function rejectOrder(PurchaseOrder $po, string $reason, User $by): void
    {
        $this->ensurePending($po);
        $po->forceFill(['status' => 'draft', 'rejection_reason' => $reason, 'submitted_at' => null, 'submitted_by' => null])->save();
        Audit::record('inventory.po_rejected', "Sent {$po->number} back to draft", $po, reason: $reason, by: $by);
    }

    private function ensurePending(PurchaseOrder $po): void
    {
        if ($po->status !== 'pending') {
            throw new BookingException("{$po->number} isn't waiting for approval.");
        }
    }

    /**
     * Each line's price against the last price paid for the item (any supplier).
     * @return array<int, array{last: ?float, supplier: ?string, on: ?Carbon, change: ?float, jump: bool}>
     */
    public function priceCheck(PurchaseOrder $po): array
    {
        $po->loadMissing('lines');
        $threshold = (float) config('hotel.procurement.price_jump_percent', 15);
        $out = [];
        foreach ($po->lines as $line) {
            $last = $this->lastPrice($line->item_id, $po->ordered_at ?? $po->created_at ?? now());
            $change = $last && $last['cost'] > 0 ? round(((float) $line->unit_cost - $last['cost']) / $last['cost'] * 100, 1) : null;
            $out[$line->id] = ['last' => $last['cost'] ?? null, 'supplier' => $last['supplier'] ?? null, 'on' => $last['on'] ?? null, 'change' => $change, 'jump' => $change !== null && $change > $threshold];
        }

        return $out;
    }

    /** Prices paid for an item, newest first, with the supplier. */
    public function priceHistory(int $itemId, int $limit = 12): Collection
    {
        return StockReceiptLine::query()
            ->join('stock_receipts', 'stock_receipts.id', '=', 'stock_receipt_lines.stock_receipt_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'stock_receipts.supplier_id')
            ->where('stock_receipt_lines.item_id', $itemId)->where('stock_receipt_lines.quantity', '>', 0)
            ->orderByDesc('stock_receipts.received_on')->orderByDesc('stock_receipt_lines.id')
            ->limit($limit)
            ->get(['stock_receipt_lines.unit_cost', 'stock_receipt_lines.quantity', 'stock_receipts.received_on', 'stock_receipts.number', 'stock_receipts.id as receipt_id', 'suppliers.name as supplier']);
    }

    private function lastPrice(int $itemId, $before): ?array
    {
        $row = StockReceiptLine::query()
            ->join('stock_receipts', 'stock_receipts.id', '=', 'stock_receipt_lines.stock_receipt_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'stock_receipts.supplier_id')
            ->where('stock_receipt_lines.item_id', $itemId)->where('stock_receipt_lines.quantity', '>', 0)
            ->where('stock_receipts.created_at', '<=', $before)
            ->orderByDesc('stock_receipts.received_on')->orderByDesc('stock_receipt_lines.id')
            ->first(['stock_receipt_lines.unit_cost', 'stock_receipts.received_on', 'suppliers.name as supplier']);

        return $row ? ['cost' => (float) $row->unit_cost, 'supplier' => $row->supplier, 'on' => Carbon::parse($row->received_on)] : null;
    }

    // ── Supplier invoices and payments ────────────────────────────────

    public function recordInvoice(array $data, User $by): SupplierInvoice
    {
        $receipt = ! empty($data['stock_receipt_id']) ? StockReceipt::find($data['stock_receipt_id']) : null;
        $supplierId = (int) ($receipt?->supplier_id ?? $data['supplier_id']);
        if ($receipt && isset($data['supplier_id']) && (int) $data['supplier_id'] !== $supplierId) {
            throw new BookingException("{$receipt->number} came from a different supplier.");
        }
        if (SupplierInvoice::where('supplier_id', $supplierId)->where('invoice_number', $data['invoice_number'])->where('status', '!=', 'void')->exists()) {
            throw new BookingException("Invoice {$data['invoice_number']} from this supplier is already recorded.");
        }
        $supplier = Supplier::findOrFail($supplierId);
        $date = Carbon::parse($data['invoice_date']);

        return DB::transaction(function () use ($data, $by, $receipt, $supplier, $date) {
            $inv = SupplierInvoice::create([
                'supplier_id'       => $supplier->id,
                'invoice_number'    => $data['invoice_number'],
                'stock_receipt_id'  => $receipt?->id,
                'purchase_order_id' => $receipt?->purchase_order_id ?? ($data['purchase_order_id'] ?? null),
                'invoice_date'      => $date,
                'due_date'          => isset($data['due_date']) ? Carbon::parse($data['due_date']) : $date->copy()->addDays($supplier->payment_terms_days ?? 30),
                'amount'            => Money::round((float) $data['amount']),
                'notes'             => $data['notes'] ?? null,
                'recorded_by'       => $by->id,
            ]);
            $this->number($inv, 'SI');
            if ($receipt && ! $receipt->supplier_invoice) {
                $receipt->forceFill(['supplier_invoice' => $inv->invoice_number])->save();
            }
            $match = $this->match($inv);
            Audit::record('inventory.invoice_recorded', "Recorded {$supplier->name} invoice {$inv->invoice_number} for " . $this->money($inv->amount)
                . ($match['mismatch'] ? ' — ' . $this->money(abs($match['difference'])) . ($match['difference'] > 0 ? ' more' : ' less') . ' than the goods received' : ''),
                $inv, new: ['amount' => $inv->amount, 'received_value' => $match['received']], flagged: $match['mismatch'], by: $by);

            return $inv;
        });
    }

    /** Three-way check: what was ordered, what arrived, what is being charged. */
    public function match(SupplierInvoice $inv): array
    {
        $received = $inv->stock_receipt_id ? (float) StockReceipt::whereKey($inv->stock_receipt_id)->value('total') : null;
        $ordered = $inv->purchase_order_id ? (float) PurchaseOrder::whereKey($inv->purchase_order_id)->value('total') : null;
        $difference = $received !== null ? round((float) $inv->amount - $received, 2) : null;

        return [
            'ordered'    => $ordered,
            'received'   => $received,
            'difference' => $difference,
            'mismatch'   => $difference !== null && abs($difference) > (float) config('hotel.procurement.invoice_tolerance', 500),
        ];
    }

    public function pay(SupplierInvoice $inv, array $data, User $by): SupplierPayment
    {
        if (! in_array($inv->status, ['unpaid', 'part_paid'], true)) {
            throw new BookingException("Invoice {$inv->invoice_number} is " . strtolower($inv->status_label) . '.');
        }
        $amount = Money::round((float) $data['amount']);
        if ($amount <= 0) {
            throw new BookingException('Enter the amount paid.');
        }
        if ($amount - $inv->balance() > 0.005) {
            throw new BookingException('That is more than the ' . $this->money($inv->balance()) . ' still owed on this invoice.');
        }

        return DB::transaction(function () use ($inv, $data, $by, $amount) {
            $payment = $inv->payments()->create([
                'amount' => $amount, 'method' => $data['method'], 'reference' => $data['reference'] ?? null,
                'paid_on' => $data['paid_on'] ?? today(), 'paid_by' => $by->id, 'notes' => $data['notes'] ?? null,
            ]);
            $paid = Money::round((float) $inv->paid + $amount);
            $inv->forceFill(['paid' => $paid, 'status' => $paid + 0.005 >= (float) $inv->amount ? 'paid' : 'part_paid'])->save();
            Audit::record('inventory.supplier_paid', "Paid {$inv->supplier?->name} " . $this->money($amount) . " on invoice {$inv->invoice_number} ({$payment->method_label})",
                $inv, new: ['amount' => $amount, 'reference' => $payment->reference, 'balance' => $inv->balance()],
                flagged: $amount >= (float) config('hotel.audit.flag_amount', 50000), by: $by);

            return $payment;
        });
    }

    public function voidInvoice(SupplierInvoice $inv, string $reason, User $by): void
    {
        if ($inv->status === 'void') {
            throw new BookingException('This invoice is already void.');
        }
        if ((float) $inv->paid > 0) {
            throw new BookingException('Payments have been made on this invoice, so it can\'t be voided.');
        }
        $inv->forceFill(['status' => 'void', 'void_reason' => $reason])->save();
        Audit::record('inventory.invoice_voided', "Voided {$inv->supplier?->name} invoice {$inv->invoice_number}", $inv, reason: $reason, flagged: true, by: $by);
    }

    // ------------------------------------------------------------------

    private function number(Model $doc, string $prefix): void
    {
        $doc->forceFill(['number' => $prefix . '-' . str_pad((string) $doc->getKey(), 6, '0', STR_PAD_LEFT)])->save();
    }

    private function money($amount): string
    {
        return config('hotel.currency_symbol') . number_format((float) $amount, 2);
    }
}
