<?php

namespace App\Models\Inventory;

use App\Models\Pos\PosOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLocation extends Model
{
    public const TYPES = ['store' => 'Main store', 'bar' => 'Bar', 'kitchen' => 'Kitchen', 'housekeeping' => 'Housekeeping', 'other' => 'Other'];

    protected $fillable = ['name', 'code', 'type', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'location_id');
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(PosOutlet::class, 'stock_location_id');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
