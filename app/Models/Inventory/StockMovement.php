<?php

namespace App\Models\Inventory;

use App\Enums\StockMovementType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One line in the stock ledger. Never edited or deleted. */
class StockMovement extends Model
{
    protected $fillable = ['item_id', 'location_id', 'type', 'quantity', 'unit_cost', 'value', 'balance_after', 'source_type', 'source_id', 'note', 'user_id', 'occurred_at'];

    protected $casts = [
        'type'          => StockMovementType::class,
        'quantity'      => 'decimal:3',
        'unit_cost'     => 'decimal:4',
        'value'         => 'decimal:2',
        'balance_after' => 'decimal:3',
        'occurred_at'   => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Link to the document that caused it, if there is a page for it. */
    public function sourceLink(): ?array
    {
        $s = $this->source;
        return match (true) {
            $s instanceof StockReceipt  => [$s->number, route('inventory.receipts.show', $s)],
            $s instanceof StockTransfer => [$s->number, route('inventory.transfers.show', $s)],
            $s instanceof StockCount    => [$s->number, route('inventory.counts.show', $s)],
            $s instanceof \App\Models\Pos\PosOrder => [$s->number, route('pos.orders', ['q' => $s->number, 'from' => $s->ordered_at->toDateString(), 'to' => $s->ordered_at->toDateString()])],
            default => null,
        };
    }
}
