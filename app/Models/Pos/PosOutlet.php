<?php

namespace App\Models\Pos;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosOutlet extends Model
{
    use BelongsToHotel;

    public const TYPES = [
        'bar'          => 'Bar',
        'restaurant'   => 'Restaurant',
        'room_service' => 'Room service',
        'pool'         => 'Pool / lounge',
        'cafe'         => 'Café',
        'other'        => 'Other',
    ];

    protected $fillable = ['name', 'code', 'type', 'stock_location_id', 'color', 'allow_room_charge', 'guest_orders', 'guest_hours', 'delivery_minutes', 'receipt_footer', 'is_active', 'sort_order'];

    protected $casts = [
        'allow_room_charge' => 'boolean',
        'guest_orders'      => 'boolean',
        'is_active'         => 'boolean',
    ];

    /** Whether guests can order from it right now (its hours, if it has any; they may run past midnight). */
    public function openForGuests(?\Illuminate\Support\Carbon $at = null): bool
    {
        if (! $this->guest_orders || ! $this->is_active || ! $this->allow_room_charge) {
            return false;
        }
        if (! $this->guest_hours || ! preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $this->guest_hours, $m)) {
            return true;
        }
        $now = ($at ?? now())->format('H:i');

        return $m[1] <= $m[2] ? ($now >= $m[1] && $now < $m[2]) : ($now >= $m[1] || $now < $m[2]);
    }

    /** "7:00 AM – 11:00 PM", or null when always open. */
    public function getGuestHoursLabelAttribute(): ?string
    {
        if (! $this->guest_hours || ! str_contains($this->guest_hours, '-')) {
            return null;
        }
        [$a, $b] = explode('-', $this->guest_hours);
        $f = fn ($t) => \Illuminate\Support\Carbon::createFromFormat('H:i', $t)->format('g:i A');

        return $f($a) . ' – ' . $f($b);
    }

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
