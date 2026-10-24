<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    protected $fillable = ['name', 'code', 'description', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function floors(): HasMany
    {
        return $this->hasMany(Floor::class)->orderBy('level');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
