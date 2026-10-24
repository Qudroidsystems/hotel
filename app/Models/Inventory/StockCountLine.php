<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['stock_count_id', 'item_id', 'expected_quantity', 'counted_quantity', 'unit_cost'];

    protected $casts = ['expected_quantity' => 'decimal:3', 'counted_quantity' => 'decimal:3', 'unit_cost' => 'decimal:4'];

    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }

    public function variance(): ?float
    {
        return $this->counted_quantity === null ? null : round((float) $this->counted_quantity - (float) $this->expected_quantity, 3);
    }
}
