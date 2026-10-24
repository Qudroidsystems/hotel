<?php

namespace App\Models\Pos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosOrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'item_id', 'name', 'charge_type', 'quantity', 'unit_price', 'total', 'note'];

    protected $casts = [
        'quantity'   => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PosItem::class, 'item_id');
    }
}
