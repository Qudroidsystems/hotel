<?php

namespace App\Models\Events;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One ticket, with its own QR code, scanned once at the door. */
class EventTicket extends Model
{
    use BelongsToHotel;

    protected $attributes = ['status' => 'valid'];

    protected $fillable = ['event_id', 'order_id', 'ticket_type_id', 'code', 'price', 'status'];

    protected $casts = ['price' => 'decimal:2', 'checked_in_at' => 'datetime'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function order()
    {
        return $this->belongsTo(EventTicketOrder::class, 'order_id');
    }

    public function type()
    {
        return $this->belongsTo(EventTicketType::class, 'ticket_type_id');
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    /** The page the buyer opens or is sent: the ticket and its QR code. */
    public function getUrlAttribute(): string
    {
        return route('tickets.public', $this->code);
    }
}
