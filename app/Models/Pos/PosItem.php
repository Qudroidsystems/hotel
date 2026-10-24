<?php

namespace App\Models\Pos;

use App\Enums\ChargeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PosItem extends Model
{
    /** How a sold item shows on a guest's bill when charged to the room. */
    public const CHARGE_TYPES = [ChargeType::Food, ChargeType::Beverage, ChargeType::Minibar, ChargeType::Service, ChargeType::Other];

    protected $fillable = ['category_id', 'name', 'sku', 'price', 'charge_type', 'description', 'is_active', 'sort_order'];

    protected $casts = [
        'price'       => 'decimal:2',
        'charge_type' => ChargeType::class,
        'is_active'   => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PosCategory::class, 'category_id');
    }

    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(PosOutlet::class, 'pos_item_outlet', 'item_id', 'outlet_id');
    }

    /** Recipe: stock used per one sold. */
    public function components(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PosItemComponent::class, 'pos_item_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
