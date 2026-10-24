<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One visit: signed in at the gate / front desk, signed out when leaving. */
class Visitor extends Model
{
    public const PURPOSES = [
        'guest_visit' => 'Visiting a guest',
        'meeting'     => 'Meeting / appointment',
        'delivery'    => 'Delivery',
        'contractor'  => 'Contractor / repairs',
        'interview'   => 'Interview',
        'other'       => 'Other',
    ];

    public const ID_TYPES = ['NIN', "Driver's licence", 'Passport', "Voter's card", 'Staff / company ID', 'Other'];

    protected $fillable = [
        'pass_code', 'full_name', 'phone', 'id_type', 'id_number', 'company', 'purpose', 'host', 'host_user_id', 'reservation_id',
        'vehicle_plate', 'party_size', 'notes', 'signed_in_at', 'signed_in_by', 'expected_out_at',
    ];

    protected $casts = [
        'signed_in_at'    => 'datetime',
        'expected_out_at' => 'datetime',
        'signed_out_at'   => 'datetime',
        'anonymised_at'   => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function hostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function signedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_in_by');
    }

    public function signedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_out_by');
    }

    public function scopeOnSite($query)
    {
        return $query->whereNull('signed_out_at');
    }

    public function isOnSite(): bool
    {
        return $this->signed_out_at === null;
    }

    public function isOverstayed(): bool
    {
        return $this->isOnSite() && $this->expected_out_at && $this->expected_out_at->isPast();
    }

    public function getPurposeLabelAttribute(): string
    {
        return self::PURPOSES[$this->purpose] ?? ucfirst($this->purpose);
    }

    /** What the visitor is here for, in one line. */
    public function getVisitingAttribute(): string
    {
        if ($this->reservation) {
            return 'Guest ' . $this->reservation->guest?->full_name . ($this->reservation->rooms->first()?->room ? ' (room ' . $this->reservation->rooms->first()->room->number . ')' : '');
        }

        return $this->host ?: '—';
    }
}
