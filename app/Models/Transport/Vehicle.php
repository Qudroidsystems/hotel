<?php

namespace App\Models\Transport;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One of the hotel's cars. */
class Vehicle extends Model
{
    use BelongsToHotel;

    public const KINDS = ['sedan' => 'Saloon car', 'suv' => 'SUV', 'van' => 'Van / minibus', 'bus' => 'Bus'];

    protected $fillable = ['name', 'plate', 'color', 'kind', 'seats', 'status', 'notes', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'seats' => 'integer'];

    public function getLabelAttribute(): string
    {
        return trim(($this->color ? $this->color . ' ' : '') . $this->name) . ' · ' . $this->plate;
    }

    public function scopeUsable($q)
    {
        return $q->where('is_active', true)->where('status', 'available');
    }
}
