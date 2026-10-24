<?php

namespace App\Models\Pos;

use App\Models\Inventory\StockItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Recipe line: one POS item uses this much of a stock item. */
class PosItemComponent extends Model
{
    public $timestamps = false;

    protected $fillable = ['pos_item_id', 'stock_item_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function posItem(): BelongsTo { return $this->belongsTo(PosItem::class, 'pos_item_id'); }
    public function stockItem(): BelongsTo { return $this->belongsTo(StockItem::class, 'stock_item_id'); }
}
