<?php

namespace App\Models\Wellness;

use App\Models\FolioCharge;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A treatment or a session at the spa, gym or pool. See WellnessService. */
class WellnessBooking extends Model
{
    use BelongsToHotel;

    public const PENDING = 'pending';       // a visitor paying online; held until expires_at
    public const BOOKED = 'booked';
    public const ARRIVED = 'arrived';
    public const COMPLETED = 'completed';
    public const NO_SHOW = 'no_show';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    /** Statuses that hold a therapist or a place. */
    public const HOLDING = [self::PENDING, self::BOOKED, self::ARRIVED];

    public const STATUSES = [
        self::PENDING   => ['Waiting for payment', 'st-warning'],
        self::BOOKED    => ['Booked', 'st-info'],
        self::ARRIVED   => ['Here now', 'st-primary'],
        self::COMPLETED => ['Done', 'st-success'],
        self::NO_SHOW   => ['Didn’t come', 'st-danger'],
        self::CANCELLED => ['Cancelled', 'st-muted'],
        self::EXPIRED   => ['Not paid', 'st-muted'],
    ];

    public const PAYMENTS = ['room' => 'Room bill', 'online' => 'Paid online', 'desk' => 'Pays on arrival', 'free' => 'Free'];

    protected $fillable = ['venue_id', 'service_id', 'therapist_id', 'reservation_id', 'guest_name', 'phone', 'email', 'people', 'starts_at', 'ends_at', 'total', 'payment', 'status', 'via', 'note', 'token', 'expires_at'];

    protected $casts = [
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'paid_at'    => 'datetime',
        'expires_at' => 'datetime',
        'total'      => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::created(function (WellnessBooking $b) {
            $b->forceFill(['number' => 'WB-' . str_pad((string) $b->id, 5, '0', STR_PAD_LEFT)])->saveQuietly();
        });
    }

    public function venue(): BelongsTo { return $this->belongsTo(WellnessVenue::class, 'venue_id'); }
    public function service(): BelongsTo { return $this->belongsTo(WellnessService::class, 'service_id'); }
    public function therapist(): BelongsTo { return $this->belongsTo(WellnessTherapist::class, 'therapist_id'); }
    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function charge(): BelongsTo { return $this->belongsTo(FolioCharge::class, 'folio_charge_id'); }
    public function handler(): BelongsTo { return $this->belongsTo(User::class, 'handled_by'); }

    /** Holding a place now: booked, here, or paying online within its hold. */
    public function scopeHolding($q)
    {
        return $q->where(fn ($w) => $w->whereIn('status', [self::BOOKED, self::ARRIVED])
            ->orWhere(fn ($p) => $p->where('status', self::PENDING)->where('expires_at', '>', now())));
    }

    public function scopeOverlapping($q, $from, $to)
    {
        return $q->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    /** "Swedish massage (60 min)" or "Gym · 2 people" */
    public function getWhatAttribute(): string
    {
        return $this->service ? $this->service->name . ' (' . $this->service->minutes . ' min)' : ($this->venue?->name ?? 'Session') . ($this->people > 1 ? ' · ' . $this->people . ' people' : '');
    }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? $this->status; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
    public function getPaymentLabelAttribute(): string { return self::PAYMENTS[$this->payment] ?? $this->payment; }

    public function isVisitor(): bool
    {
        return $this->reservation_id === null;
    }
}
