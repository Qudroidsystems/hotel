<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['purchase_order_id', 'item_id', 'quantity', 'unit_cost', 'received_quantity'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'received_quantity' => 'decimal:3'];

    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }

    public function outstanding(): float { return max(0, round((float) $this->quantity - (float) $this->received_quantity, 3)); }
}
