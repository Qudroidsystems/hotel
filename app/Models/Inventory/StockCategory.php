<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCategory extends Model
{
    protected $fillable = ['name', 'sort_order'];

    public function items(): HasMany
    {
        return $this->hasMany(StockItem::class, 'category_id');
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }
}
