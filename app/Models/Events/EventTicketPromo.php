<?php

namespace App\Models\Events;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A promo code for an event's tickets: a percentage or an amount off the order. */
class EventTicketPromo extends Model
{
    use BelongsToHotel;

    protected $attributes = ['kind' => 'percent', 'used' => 0, 'is_active' => true];

    protected $fillable = ['event_id', 'code', 'kind', 'value', 'max_uses', 'used', 'starts_at', 'ends_at', 'is_active'];

    protected $casts = ['value' => 'decimal:2', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /** Why it can't be used now, or null. */
    public function closedReason(): ?string
    {
        return match (true) {
            ! $this->is_active => 'This code is no longer valid.',
            $this->starts_at && $this->starts_at->isFuture() => 'This code works from ' . $this->starts_at->format('D j M') . '.',
            $this->ends_at && $this->ends_at->isPast() => 'This code has expired.',
            $this->max_uses !== null && $this->used >= $this->max_uses => 'This code has been used up.',
            default => null,
        };
    }

    /** Money off an order of this much. */
    public function discountOn(float $subtotal): float
    {
        $off = $this->kind === 'amount' ? (float) $this->value : $subtotal * min(100, (float) $this->value) / 100;

        return round(min($subtotal, max(0, $off)), 2);
    }

    public function getLabelAttribute(): string
    {
        return $this->kind === 'amount' ? config('hotel.currency_symbol') . number_format((float) $this->value) . ' off' : rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.') . '% off';
    }
}
