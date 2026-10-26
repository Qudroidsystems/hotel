<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType as T;
use App\Exceptions\BookingException;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\StockCount;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLevel;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockReceipt;
use App\Models\Inventory\StockTransfer;
use App\Models\Inventory\Supplier;
use App\Models\Pos\PosOrder;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place stock quantities change. Each change is written to the
 * stock_movements ledger and the store balance in the same transaction, with
 * the balance row locked so two tills or two storekeepers can't race.
 *
 * Costing: weighted average. Receiving goods re-averages the item's cost;
 * everything going out is valued at the current average.
 */
class StockService
{
    // ── Core ──────────────────────────────────────────────────────────

    public function move(StockItem $item, StockLocation|int $location, T $type, float $quantity, ?float $unitCost = null, ?Model $source = null, ?string $note = null, $at = null): StockMovement
    {
        $locationId = $location instanceof StockLocation ? $location->id : $location;
        $quantity = round($quantity, 3);
        if (abs($quantity) < 0.0005) {
            throw new BookingException('Quantity must not be zero.');
        }

        return DB::transaction(function () use ($item, $locationId, $type, $quantity, $unitCost, $source, $note, $at) {
            $item = StockItem::whereKey($item->id)->lockForUpdate()->first();
            $level = $this->lockedLevel($item->id, $locationId);

            // Goods coming in at a known price change the average cost.
            if (in_array($type, [T::Receipt, T::Opening], true) && $unitCost !== null && $quantity > 0) {
                $totalBefore = (float) StockLevel::where('item_id', $item->id)->sum('quantity');
                $avg = $totalBefore <= 0
                    ? $unitCost
                    : (($totalBefore * (float) $item->avg_cost) + ($quantity * $unitCost)) / ($totalBefore + $quantity);
                $item->forceFill(['avg_cost' => round($avg, 4), 'last_cost' => $unitCost])->save();
            }
            $cost = $unitCost ?? (float) $item->avg_cost;

            $level->quantity = round((float) $level->quantity + $quantity, 3);
            $level->save();

            return StockMovement::create([
                'item_id'       => $item->id,
                'location_id'   => $locationId,
                'type'          => $type,
                'quantity'      => $quantity,
                'unit_cost'     => round($cost, 4),
                'value'         => Money::round($quantity * $cost),
                'balance_after' => $level->quantity,
                'source_type'   => $source?->getMorphClass(),
                'source_id'     => $source?->getKey(),
                'note'          => $note ? mb_substr($note, 0, 255) : null,
                'user_id'       => auth()->id(),
                'occurred_at'   => $at ? Carbon::parse($at) : now(),
            ]);
        });
    }

    public function onHand(int $itemId, int $locationId): float
    {
        return (float) StockLevel::where('item_id', $itemId)->where('location_id', $locationId)->value('quantity');
    }

    private function lockedLevel(int $itemId, int $locationId): StockLevel
    {
        $level = StockLevel::where('item_id', $itemId)->where('location_id', $locationId)->lockForUpdate()->first();
        if (! $level) {
            try {
                StockLevel::create(['item_id' => $itemId, 'location_id' => $locationId, 'quantity' => 0]);
            } catch (UniqueConstraintViolationException) {
                // created by a parallel request — fine
            }
            $level = StockLevel::where('item_id', $itemId)->where('location_id', $locationId)->lockForUpdate()->first();
        }

        return $level;
    }

    /** Refuse to take out more than a store holds (for deliberate moves; sales may go negative). */
    private function ensureAvailable(StockItem $item, int $locationId, float $needed): void
    {
        $have = $this->onHand($item->id, $locationId);
        if ($needed - $have > 0.0005) {
            $loc = StockLocation::find($locationId)?->name;
            throw new BookingException(sprintf('Only %s %s of %s in %s.', StockItem::qty($have), $item->unit, $item->name, $loc));
        }
    }

    private function number(Model $doc, string $prefix): void
    {
        $doc->forceFill(['number' => $prefix . '-' . str_pad((string) $doc->getKey(), 6, '0', STR_PAD_LEFT)])->save();
    }

    // ── Items ─────────────────────────────────────────────────────────

    public function opening(StockItem $item, int $locationId, float $quantity, ?float $unitCost): void
    {
        if ($quantity > 0) {
            $this->move($item, $locationId, T::Opening, $quantity, $unitCost ?? (float) $item->avg_cost, null, 'Opening stock');
        }
    }

    /** Use, waste or correct stock by hand. $quantity is what changes: negative takes out. */
    public function adjust(StockItem $item, int $locationId, T $type, float $quantity, string $reason): StockMovement
    {
        if (! in_array($type, T::manual(), true)) {
            throw new BookingException('That kind of change has its own screen.');
        }
        if (in_array($type, [T::Issue, T::Waste], true)) {
            $quantity = -abs($quantity);
        }
        if ($quantity < 0) {
            $this->ensureAvailable($item, $locationId, abs($quantity));
        }

        return $this->move($item, $locationId, $type, $quantity, null, null, $reason);
    }

    // ── Receiving ─────────────────────────────────────────────────────

    /**
     * Book goods in. $lines: [['item_id', 'quantity', 'unit_cost', 'purchase_order_line_id'?]].
     */
    public function receive(array $head, array $lines, ?PurchaseOrder $po = null): StockReceipt
    {
        if (! empty($head['idempotency_key']) && ($done = StockReceipt::where('idempotency_key', $head['idempotency_key'])->first())) {
            return $done;
        }
        // A line can be all rejected (nothing booked in) but still recorded on the note.
        $lines = array_values(array_filter($lines, fn ($l) => (float) ($l['quantity'] ?? 0) > 0 || (float) ($l['rejected_quantity'] ?? 0) > 0));
        if (! $lines) {
            throw new BookingException('Enter a quantity for at least one item.');
        }
        foreach ($lines as $l) {
            if ((float) ($l['rejected_quantity'] ?? 0) > 0 && blank($l['reject_reason'] ?? null)) {
                throw new BookingException('Say why goods were rejected (damaged, expired, wrong item…).');
            }
        }
        if ($po && ! $po->canReceive()) {
            throw new BookingException("Purchase order {$po->number} is " . strtolower($po->status_label) . '.');
        }

        return DB::transaction(function () use ($head, $lines, $po) {
            $receipt = StockReceipt::create([
                'supplier_id'       => $head['supplier_id'] ?? $po?->supplier_id,
                'purchase_order_id' => $po?->id,
                'location_id'       => $head['location_id'] ?? $po?->location_id,
                'supplier_invoice'  => $head['supplier_invoice'] ?? null,
                'received_on'       => $head['received_on'] ?? today(),
                'notes'             => $head['notes'] ?? null,
                'idempotency_key'   => $head['idempotency_key'] ?? null,
                'received_by'       => auth()->id(),
            ]);
            $this->number($receipt, 'GRN');

            $total = 0;
            foreach ($lines as $l) {
                $item = StockItem::findOrFail($l['item_id']);
                $qty = round((float) ($l['quantity'] ?? 0), 3);
                $rejected = round((float) ($l['rejected_quantity'] ?? 0), 3);
                $cost = round((float) $l['unit_cost'], 4);
                $poLineId = null;
                if ($po && ! empty($l['purchase_order_line_id'])) {
                    $poLine = $po->lines()->whereKey($l['purchase_order_line_id'])->lockForUpdate()->first();
                    if ($poLine) {
                        $poLine->increment('received_quantity', $qty);   // rejected goods stay outstanding on the order
                        $poLineId = $poLine->id;
                    }
                }
                $receipt->lines()->create(['item_id' => $item->id, 'purchase_order_line_id' => $poLineId, 'quantity' => $qty, 'unit_cost' => $cost,
                    'rejected_quantity' => $rejected, 'reject_reason' => $rejected > 0 ? \Illuminate\Support\Str::limit($l['reject_reason'], 117) : null]);
                if ($qty > 0) {
                    $this->move($item, $receipt->location_id, T::Receipt, $qty, $cost, $receipt,
                        'From ' . ($receipt->supplier?->name ?? 'supplier') . ($receipt->supplier_invoice ? ' · inv ' . $receipt->supplier_invoice : ''), $receipt->received_on->setTimeFrom(now()));
                }
                $total += $qty * $cost;
            }
            $receipt->forceFill(['total' => Money::round($total)])->save();

            if ($po) {
                $po->load('lines');
                $done = $po->lines->every(fn ($pl) => $pl->outstanding() <= 0.0005);
                $po->forceFill(['status' => $done ? 'received' : 'partial'])->save();
            }

            return $receipt;
        });
    }

    // ── Transfers ─────────────────────────────────────────────────────

    public function transfer(int $fromId, int $toId, array $lines, ?string $notes = null, ?string $key = null): StockTransfer
    {
        if ($key && ($done = StockTransfer::where('idempotency_key', $key)->first())) {
            return $done;
        }
        if ($fromId === $toId) {
            throw new BookingException('Choose two different stores.');
        }
        $lines = array_values(array_filter($lines, fn ($l) => (float) ($l['quantity'] ?? 0) > 0));
        if (! $lines) {
            throw new BookingException('Enter a quantity for at least one item.');
        }

        return DB::transaction(function () use ($fromId, $toId, $lines, $notes, $key) {
            $t = StockTransfer::create(['from_location_id' => $fromId, 'to_location_id' => $toId, 'notes' => $notes, 'idempotency_key' => $key, 'created_by' => auth()->id()]);
            $this->number($t, 'TRF');
            $from = StockLocation::find($fromId);
            $to = StockLocation::find($toId);

            foreach ($lines as $l) {
                $item = StockItem::findOrFail($l['item_id']);
                $qty = round((float) $l['quantity'], 3);
                $this->ensureAvailable($item, $fromId, $qty);
                $t->lines()->create(['item_id' => $item->id, 'quantity' => $qty]);
                $out = $this->move($item, $fromId, T::TransferOut, -$qty, null, $t, "To {$to->name}");
                $this->move($item, $toId, T::TransferIn, $qty, (float) $out->unit_cost, $t, "From {$from->name}");
            }

            return $t;
        });
    }

    // ── Stocktakes ────────────────────────────────────────────────────

    /** Freeze the system quantities for a store (optionally one category) and start counting. */
    public function startCount(int $locationId, ?int $categoryId, ?string $notes = null): StockCount
    {
        if (StockCount::where('location_id', $locationId)->where('status', 'counting')->exists()) {
            throw new BookingException('A stocktake is already open for this store. Finish or cancel it first.');
        }

        return DB::transaction(function () use ($locationId, $categoryId, $notes) {
            $count = StockCount::create(['location_id' => $locationId, 'category_id' => $categoryId, 'status' => 'counting', 'notes' => $notes, 'started_by' => auth()->id()]);
            $this->number($count, 'CNT');

            // Items kept in this store before, plus active items of the category.
            $items = StockItem::active()
                ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
                ->where(fn ($q) => $q->whereHas('levels', fn ($l) => $l->where('location_id', $locationId))
                    ->when($categoryId, fn ($w) => $w->orWhere('category_id', $categoryId)))
                ->orderBy('name')->get();
            if ($items->isEmpty()) {
                throw new BookingException('There is nothing to count in this store yet.');
            }
            foreach ($items as $item) {
                $count->lines()->create([
                    'item_id'           => $item->id,
                    'expected_quantity' => $this->onHand($item->id, $locationId),
                    'unit_cost'         => $item->avg_cost,
                ]);
            }

            return $count;
        });
    }

    /** Save what has been counted so far. $counted: [line_id => quantity|null]. */
    public function saveCount(StockCount $count, array $counted): void
    {
        if (! $count->isOpen()) {
            throw new BookingException('This stocktake is closed.');
        }
        foreach ($count->lines as $line) {
            if (array_key_exists($line->id, $counted)) {
                $v = $counted[$line->id];
                $line->update(['counted_quantity' => ($v === null || $v === '') ? null : round((float) $v, 3)]);
            }
        }
    }

    /** Book every difference between counted and expected. Lines not counted are left alone. */
    public function postCount(StockCount $count): void
    {
        DB::transaction(function () use ($count) {
            $count = StockCount::whereKey($count->id)->lockForUpdate()->first();
            if (! $count->isOpen()) {
                throw new BookingException('This stocktake is already ' . $count->status . '.');
            }
            $count->load('lines.item');
            if ($count->lines->whereNotNull('counted_quantity')->isEmpty()) {
                throw new BookingException('Nothing has been counted yet.');
            }
            $value = 0;
            foreach ($count->lines as $line) {
                $diff = $line->variance();
                if ($diff === null || abs($diff) < 0.0005) {
                    continue;
                }
                $m = $this->move($line->item, $count->location_id, T::Count, $diff, (float) $line->unit_cost, $count,
                    sprintf('Stocktake %s: counted %s, expected %s', $count->number, StockItem::qty($line->counted_quantity), StockItem::qty($line->expected_quantity)));
                $value += (float) $m->value;
            }
            $count->forceFill(['status' => 'posted', 'posted_at' => now(), 'posted_by' => auth()->id(), 'variance_value' => Money::round($value)])->save();
        });
    }

    public function cancelCount(StockCount $count): void
    {
        if (! $count->isOpen()) {
            throw new BookingException('Only an open stocktake can be cancelled.');
        }
        $count->update(['status' => 'cancelled']);
    }

    // ── Purchase orders ───────────────────────────────────────────────

    /** $lines: [['item_id', 'quantity', 'unit_cost']] */
    public function savePurchaseOrder(?PurchaseOrder $po, array $head, array $lines): PurchaseOrder
    {
        if ($po && ! $po->isEditable()) {
            throw new BookingException('Only a draft order can be changed.');
        }
        $lines = array_values(array_filter($lines, fn ($l) => ! empty($l['item_id']) && (float) ($l['quantity'] ?? 0) > 0));
        if (! $lines) {
            throw new BookingException('Add at least one item.');
        }

        return DB::transaction(function () use ($po, $head, $lines) {
            $po ??= new PurchaseOrder(['status' => 'draft', 'created_by' => auth()->id()]);
            $po->fill(array_intersect_key($head, array_flip(['supplier_id', 'location_id', 'expected_on', 'notes'])));
            $po->save();
            if (! $po->number) {
                $this->number($po, 'PO');
            }
            $po->lines()->delete();
            $total = 0;
            foreach ($lines as $l) {
                $po->lines()->create(['item_id' => $l['item_id'], 'quantity' => round((float) $l['quantity'], 3), 'unit_cost' => round((float) ($l['unit_cost'] ?? 0), 4)]);
                $total += (float) $l['quantity'] * (float) ($l['unit_cost'] ?? 0);
            }
            $po->forceFill(['total' => Money::round($total)])->save();

            return $po->fresh('lines');
        });
    }

    public function markOrdered(PurchaseOrder $po): void
    {
        if (! in_array($po->status, ['draft', 'approved'], true)) {
            throw new BookingException($po->status === 'pending' ? 'This order is still waiting for approval.' : 'This order has already been sent.');
        }
        if (! $po->canSend()) {
            throw new BookingException('Orders over ' . config('hotel.currency_symbol') . number_format((float) config('hotel.procurement.approval_limit'), 2) . ' need approval first. Send it for approval.');
        }
        $po->forceFill(['status' => 'ordered', 'ordered_at' => now(), 'ordered_by' => auth()->id()])->save();
    }

    public function cancelPurchaseOrder(PurchaseOrder $po, string $reason): void
    {
        if (! $po->canCancel()) {
            throw new BookingException('This order can no longer be cancelled.');
        }
        // A part-received order is closed: what arrived stays in stock.
        $po->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
    }

    /** Draft purchase orders for everything at or below its reorder level, one per supplier. */
    public function draftReorders(int $locationId): array
    {
        $items = StockItem::active()->where('reorder_level', '>', 0)->with('levels')->get()
            ->filter(fn (StockItem $i) => $i->isLow() && $i->preferred_supplier_id);
        $created = [];
        foreach ($items->groupBy('preferred_supplier_id') as $supplierId => $group) {
            $lines = $group->map(fn (StockItem $i) => [
                'item_id'   => $i->id,
                'quantity'  => (float) ($i->reorder_qty ?: max(1, (float) $i->reorder_level * 2 - $i->onHand())),
                'unit_cost' => (float) ($i->last_cost ?? $i->avg_cost),
            ])->all();
            $created[] = $this->savePurchaseOrder(null, ['supplier_id' => $supplierId, 'location_id' => $locationId, 'notes' => 'Reorder for items at or below their reorder level.'], $lines);
        }

        return $created;
    }

    // ── POS sales ─────────────────────────────────────────────────────

    /**
     * Take the ingredients of a POS sale out of the outlet's store. Never refuses:
     * the sale has already happened (maybe offline), so a store can go below zero —
     * that shows up as "negative stock" for a supervisor to check.
     */
    public function consumePosOrder(PosOrder $order): int
    {
        $order->loadMissing(['outlet', 'items.item.components.stockItem']);
        $locationId = $order->outlet?->stock_location_id;
        if (! $locationId) {
            return 0;
        }
        $use = [];
        foreach ($order->items as $line) {
            foreach ($line->item?->components ?? [] as $c) {
                $use[$c->stock_item_id] ??= ['item' => $c->stockItem, 'qty' => 0, 'what' => []];
                $use[$c->stock_item_id]['qty'] += (float) $line->quantity * (float) $c->quantity;
                $use[$c->stock_item_id]['what'][] = StockItem::qty($line->quantity) . '× ' . $line->name;
            }
        }
        foreach ($use as $u) {
            $this->move($u['item'], $locationId, T::Sale, -$u['qty'], null, $order, "{$order->number}: " . implode(', ', array_unique($u['what'])), $order->ordered_at);
        }

        return count($use);
    }

    /** Put back what a voided sale used, at the cost it went out at. */
    public function reversePosOrder(PosOrder $order): void
    {
        $out = StockMovement::where('source_type', $order->getMorphClass())->where('source_id', $order->id)->where('type', T::Sale->value)->with('item')->get();
        foreach ($out as $m) {
            $this->move($m->item, $m->location_id, T::SaleVoid, abs((float) $m->quantity), (float) $m->unit_cost, $order, "{$order->number} voided");
        }
    }

    // ── Reports helpers ───────────────────────────────────────────────

    /** Value of stock on hand at average cost, optionally for one store. */
    public function stockValue(?int $locationId = null): float
    {
        return (float) DB::table('stock_levels')->join('stock_items', 'stock_items.id', '=', 'stock_levels.item_id')
            ->when($locationId, fn ($q) => $q->where('stock_levels.location_id', $locationId))
            ->when(\App\Support\Tenancy\CurrentHotel::get()->id(), fn ($q, $h) => $q->where('stock_levels.hotel_id', $h))
            ->where('stock_levels.quantity', '>', 0)
            ->selectRaw('sum(stock_levels.quantity * stock_items.avg_cost) as v')->value('v');
    }
}
