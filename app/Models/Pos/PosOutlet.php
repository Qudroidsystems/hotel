<?php

namespace App\Models\Pos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosOutlet extends Model
{
    public const TYPES = [
        'bar'          => 'Bar',
        'restaurant'   => 'Restaurant',
        'room_service' => 'Room service',
        'pool'         => 'Pool / lounge',
        'cafe'         => 'Café',
        'other'        => 'Other',
    ];

    protected $fillable = ['name', 'code', 'type', 'stock_location_id', 'color', 'allow_room_charge', 'receipt_footer', 'is_active', 'sort_order'];

    protected $casts = [
        'allow_room_charge' => 'boolean',
        'is_active'         => 'boolean',
    ];

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(PosItem::class, 'pos_item_outlet', 'outlet_id', 'item_id');
    }

    /** The store its sales take stock from. */
    public function stockLocation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Inventory\StockLocation::class, 'stock_location_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'outlet_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
