<?php

namespace App\Models\Booking;

use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One payment-gateway transaction (spec §9: reconcile gateway references against payments). */
class OnlinePayment extends Model
{
    protected $fillable = ['reservation_id', 'provider', 'reference', 'amount', 'currency', 'purpose', 'status', 'email'];

    protected $casts = ['amount' => 'decimal:2', 'paid_at' => 'datetime', 'meta' => 'array'];

    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
}
