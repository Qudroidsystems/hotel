<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['reservation_id', 'event', 'description', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function icon(): string
    {
        return match ($this->event) {
            'created'        => 'ri-add-circle-line',
            'confirmed'      => 'ri-calendar-check-line',
            'room_assigned'  => 'ri-door-line',
            'room_released'  => 'ri-door-closed-line',
            'checked_in'     => 'ri-login-box-line',
            'checked_out'    => 'ri-logout-box-r-line',
            'cancelled'      => 'ri-close-circle-line',
            'no_show'        => 'ri-user-unfollow-line',
            'dates_changed'  => 'ri-calendar-event-line',
            'discount'       => 'ri-price-tag-3-line',
            'charge'         => 'ri-file-list-3-line',
            'charge_voided'  => 'ri-forbid-line',
            'payment'        => 'ri-bank-card-line',
            'refund'         => 'ri-refund-2-line',
            'payment_voided' => 'ri-forbid-2-line',
            default          => 'ri-edit-line',
        };
    }
}
