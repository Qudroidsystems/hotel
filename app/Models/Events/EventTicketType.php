<?php

namespace App\Models\Events;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A kind of ticket for an event: Regular, VIP, a table for ten. */
class EventTicketType extends Model
{
    use BelongsToHotel;

    protected $attributes = ['price' => 0, 'admits' => 1, 'max_per_order' => 10, 'is_active' => true, 'sort_order' => 0];

    protected $fillable = ['event_id', 'name', 'description', 'price', 'admits', 'quantity', 'max_per_order', 'sales_start_at', 'sales_end_at', 'is_active', 'sort_order'];

    protected $casts = ['price' => 'decimal:2', 'sales_start_at' => 'datetime', 'sales_end_at' => 'datetime', 'is_active' => 'boolean'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function tickets()
    {
        return $this->hasMany(EventTicket::class, 'ticket_type_id');
    }

    /** Tickets sold and not cancelled. */
    public function sold(): int
    {
        return $this->tickets()->where('status', '!=', 'void')->count();
    }

    /** Tickets left of this type (null = no limit of its own). */
    public function left(): ?int
    {
        return $this->quantity === null ? null : max(0, (int) $this->quantity - $this->sold());
    }

    /** Why it can't be sold now, or null. */
    public function closedReason(): ?string
    {
        return match (true) {
            ! $this->is_active => 'not on sale',
            $this->sales_start_at && $this->sales_start_at->isFuture() => 'on sale from ' . $this->sales_start_at->format('D j M, g:i A'),
            $this->sales_end_at && $this->sales_end_at->isPast() => 'sales ended',
            $this->left() === 0 => 'sold out',
            default => null,
        };
    }
}
