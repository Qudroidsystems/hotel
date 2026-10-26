<?php

namespace App\Models\Inventory;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestLine extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $fillable = ['purchase_request_id', 'item_id', 'quantity', 'unit_cost'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:4'];

    public function item(): BelongsTo { return $this->belongsTo(StockItem::class, 'item_id'); }
}
