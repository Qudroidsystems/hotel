<?php

namespace App\Models\Events;

use App\Enums\PaymentMethod;
use App\Models\FolioCharge;
use App\Models\HotelBankAccount;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One sale of tickets: who bought, how they paid (or the room it was charged to), and the tickets issued. */
class EventTicketOrder extends Model
{
    use BelongsToHotel;

    public const ROOM = 'room';

    protected $attributes = ['channel' => 'desk', 'status' => 'paid'];

    protected $fillable = [
        'number', 'event_id', 'channel', 'status', 'buyer_name', 'buyer_phone', 'buyer_email', 'reservation_id', 'folio_charge_id',
        'quantity', 'total', 'method', 'reference', 'bank_account_id', 'sold_by', 'paid_at', 'idempotency_key',
        'token', 'lines', 'prices', 'discount', 'promo_id', 'gateway_reference', 'expires_at', 'emailed_at',
    ];

    protected $casts = ['total' => 'decimal:2', 'discount' => 'decimal:2', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'expires_at' => 'datetime', 'emailed_at' => 'datetime', 'lines' => 'array', 'prices' => 'array'];

    /** An online order waiting for its payment holds its tickets until it expires. */
    public function scopeHolding($q)
    {
        return $q->where('status', 'pending')->where('expires_at', '>', now());
    }

    public function promo()
    {
        return $this->belongsTo(EventTicketPromo::class, 'promo_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** The buyer's page with all their tickets (QR codes). */
    public function getUrlAttribute(): ?string
    {
        return $this->token ? route('tickets.mine', $this->token) : null;
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function tickets()
    {
        return $this->hasMany(EventTicket::class, 'order_id')->orderBy('id');
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function folioCharge()
    {
        return $this->belongsTo(FolioCharge::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(HotelBankAccount::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isRoomCharge(): bool
    {
        return $this->method === self::ROOM;
    }

    public function getMethodLabelAttribute(): string
    {
        return $this->isRoomCharge() ? 'Charged to room' : (PaymentMethod::tryFrom($this->method)?->label() ?? ucfirst($this->method));
    }
}
