<?php

namespace App\Models\Inventory;

use App\Models\Pos\PosItemComponent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockItem extends Model
{
    public const UNITS = ['pcs', 'bottle', 'can', 'crate', 'carton', 'pack', 'kg', 'g', 'litre', 'ml', 'tot', 'roll', 'bar', 'set'];

    protected $fillable = ['category_id', 'name', 'sku', 'unit', 'reorder_level', 'reorder_qty', 'preferred_supplier_id', 'notes', 'is_active'];

    protected $casts = [
        'reorder_level' => 'decimal:3',
        'reorder_qty'   => 'decimal:3',
        'avg_cost'      => 'decimal:4',
        'last_cost'     => 'decimal:4',
        'is_active'     => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(StockCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'item_id');
    }

    public function usedIn(): HasMany
    {
        return $this->hasMany(PosItemComponent::class, 'stock_item_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /** Total on hand in every store. */
    public function onHand(): float
    {
        return (float) ($this->relationLoaded('levels') ? $this->levels->sum('quantity') : $this->levels()->sum('quantity'));
    }

    public function isLow(?float $onHand = null): bool
    {
        $q = $onHand ?? $this->onHand();

        return (float) $this->reorder_level > 0 && $q <= (float) $this->reorder_level;
    }

    /** 12 → "12", 1.5 → "1.5" */
    public static function qty($q): string
    {
        return rtrim(rtrim(number_format((float) $q, 3), '0'), '.') ?: '0';
    }
}
