<?php

namespace App\Models\Pos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosCategory extends Model
{
    protected $fillable = ['name', 'color', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(PosItem::class, 'category_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
