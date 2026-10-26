<?php

namespace App\Models\Inventory;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferLine extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $fillable = ['stock_transfer_id', 'item_id', 'quantity'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }
}
