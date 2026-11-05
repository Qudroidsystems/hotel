<?php

namespace App\Models\Transport;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A car booked by a guest (see RideService for the lifecycle). */
class Ride extends Model
{
    use BelongsToHotel;

    public const REQUESTED = 'requested';
    public const ASSIGNED = 'assigned';
    public const ON_THE_WAY = 'on_the_way';   // driver heading to the pickup
    public const ARRIVED = 'arrived';         // driver waiting at the pickup
    public const ON_TRIP = 'on_trip';         // guest in the car
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const OPEN = [self::REQUESTED, self::ASSIGNED, self::ON_THE_WAY, self::ARRIVED, self::ON_TRIP];
    public const ACTIVE = [self::ASSIGNED, self::ON_THE_WAY, self::ARRIVED, self::ON_TRIP];

    /** status => [guest wording, staff wording, pill] */
    public const STATUSES = [
        self::REQUESTED  => ['Requested — finding you a driver', 'New', 'st-warning'],
        self::ASSIGNED   => ['Driver assigned', 'Assigned', 'st-info'],
        self::ON_THE_WAY => ['Your driver is on the way', 'Driving to pick-up', 'st-primary'],
        self::ARRIVED    => ['Your driver is here', 'At pick-up', 'st-primary'],
        self::ON_TRIP    => ['On your way', 'On the trip', 'st-success'],
        self::COMPLETED  => ['Completed', 'Completed', 'st-muted'],
        self::CANCELLED  => ['Cancelled', 'Cancelled', 'st-danger'],
    ];

    protected $fillable = [
        'reservation_id', 'guest_id', 'guest_name', 'guest_phone', 'direction', 'place_id', 'place_label', 'place_lat', 'place_lng',
        'pickup_at', 'passengers', 'bags', 'flight', 'notes', 'distance_km', 'fare', 'payment', 'status', 'via',
    ];

    protected $casts = [
        'pickup_at' => 'datetime', 'assigned_at' => 'datetime', 'started_at' => 'datetime', 'arrived_at' => 'datetime',
        'picked_up_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        'fare' => 'decimal:2', 'distance_km' => 'float', 'cash_collected' => 'decimal:2', 'cash_handed_at' => 'datetime', 'no_show' => 'boolean',
        'reminded_at' => 'datetime', 'driver_reminded_at' => 'datetime', 'start_km' => 'integer', 'end_km' => 'integer', 'place_lat' => 'float', 'place_lng' => 'float', 'passengers' => 'integer', 'bags' => 'integer',
    ];

    protected static function booted(): void
    {
        static::created(fn (Ride $r) => $r->forceFill(['number' => 'RD-' . str_pad((string) $r->id, 5, '0', STR_PAD_LEFT)])->saveQuietly());
    }

    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function guest(): BelongsTo { return $this->belongsTo(Guest::class); }
    public function driver(): BelongsTo { return $this->belongsTo(Driver::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function place(): BelongsTo { return $this->belongsTo(RidePlace::class); }
    public function assigner(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }

    public function scopeOpen($q)
    {
        return $q->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function toHotel(): bool
    {
        return $this->direction === 'to_hotel';
    }

    /** "Airport → Hotel" */
    public function getRouteLabelAttribute(): string
    {
        $hotel = config('hotel.name');

        return $this->toHotel() ? "{$this->place_label} → {$hotel}" : "{$hotel} → {$this->place_label}";
    }

    public function getGuestLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? $this->status; }
    public function getStaffLabelAttribute(): string { return self::STATUSES[$this->status][1] ?? $this->status; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][2] ?? 'st-muted'; }

    public function getWhenLabelAttribute(): string
    {
        return $this->pickup_at ? $this->pickup_at->format($this->pickup_at->isToday() ? '\T\o\d\a\y g:i A' : 'D j M, g:i A') : 'As soon as possible';
    }
}
