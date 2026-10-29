<?php

namespace App\Models\Booking;

use App\Models\Reservation;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing on a guest's luggage list (see LuggageService). */
class LuggageItem extends Model
{
    use BelongsToHotel;

    protected $fillable = ['reservation_id', 'name', 'quantity', 'bag', 'packed_at', 'position'];

    protected $casts = ['quantity' => 'integer', 'position' => 'integer', 'packed_at' => 'datetime'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function isPacked(): bool
    {
        return $this->packed_at !== null;
    }

    /** "2 × Phone charger" */
    public function getLabelAttribute(): string
    {
        return ($this->quantity > 1 ? $this->quantity . ' × ' : '') . $this->name;
    }
}
