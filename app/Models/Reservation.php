<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use App\Enums\BookingSource;
use App\Enums\ReservationStatus;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Reservation extends Model
{
    use BelongsToHotel;

    protected $fillable = [
        'reference', 'qr_token', 'guest_id', 'status', 'source', 'check_in_date', 'check_out_date',
        'adults', 'children', 'expected_arrival_time', 'room_total', 'discount_amount', 'discount_reason',
        'total_amount', 'special_requests', 'notes', 'cancel_reason', 'created_by',
        'rate_plan_id', 'rate_plan_name', 'promo_code', 'company_id',
        'channel', 'channel_reference', 'channel_booking_id', 'via_group', 'commission_percent',
    ];

    protected $casts = [
        'status'          => ReservationStatus::class,
        'source'          => BookingSource::class,
        'check_in_date'   => 'date',
        'check_out_date'  => 'date',
        'room_total'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'confirmed_at'    => 'datetime',
        'checked_in_at'   => 'datetime',
        'checked_out_at'  => 'datetime',
        'cancelled_at'    => 'datetime',
        'hold_expires_at' => 'datetime',
        'via_group'       => 'boolean',
        'commission_percent' => 'decimal:2',
        'commission_amount'  => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // Group-site bookings: commission follows the booking's total, and nothing is owed on a stay that didn't happen.
        static::saving(function (self $r) {
            if ($r->via_group && $r->commission_percent !== null) {
                $lost = in_array($r->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true);
                $r->commission_amount = $lost ? 0 : round((float) $r->total_amount * (float) $r->commission_percent / 100, 2);
            }
        });
    }

    // Store stay dates as plain Y-m-d so date comparisons behave the same on MySQL and SQLite.
    public function setCheckInDateAttribute($value): void
    {
        $this->attributes['check_in_date'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    public function setCheckOutDateAttribute($value): void
    {
        $this->attributes['check_out_date'] = $value ? Carbon::parse($value)->toDateString() : null;
    }

    // ── Relationships ─────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Rates\RatePlan::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class)->orderBy('id');
    }

    public function folio(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Folio::class);
    }

    public function onlinePayments(): HasMany
    {
        return $this->hasMany(\App\Models\Booking\OnlinePayment::class);
    }

    public function guestRequests(): HasMany
    {
        return $this->hasMany(\App\Models\Booking\GuestRequest::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReservationEvent::class)->latest('created_at')->latest('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────

    /** Reservations that hold rooms (pending, confirmed, checked in). */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ReservationStatus::holding());
    }

    /** Stays that share at least one night with [in, out). */
    public function scopeOverlapping($query, $in, $out)
    {
        return $query->where('check_in_date', '<', Carbon::parse($out)->toDateString())
                     ->where('check_out_date', '>', Carbon::parse($in)->toDateString());
    }

    public function scopeArrivingOn($query, $date)
    {
        return $query->whereDate('check_in_date', Carbon::parse($date)->toDateString())
                     ->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value]);
    }

    public function scopeDepartingOn($query, $date)
    {
        return $query->whereDate('check_out_date', Carbon::parse($date)->toDateString())
                     ->where('status', ReservationStatus::CheckedIn->value);
    }

    public function scopeInHouse($query)
    {
        return $query->where('status', ReservationStatus::CheckedIn->value);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    public function getNightsAttribute(): int
    {
        return (int) $this->check_in_date->diffInDays($this->check_out_date);
    }

    /** Each night of the stay as Y-m-d strings (the check-out day is not a night). */
    public function nightDates(): array
    {
        return self::nightsBetween($this->check_in_date, $this->check_out_date);
    }

    public static function nightsBetween($in, $out): array
    {
        $in = Carbon::parse($in)->startOfDay();
        $out = Carbon::parse($out)->startOfDay();

        if ($out->lte($in)) {
            return [];
        }

        return collect(CarbonPeriod::create($in, $out->copy()->subDay()))->map(fn ($d) => $d->toDateString())->all();
    }

    public function isArrivalDue(): bool
    {
        return in_array($this->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true)
            && $this->check_in_date->lte(today());
    }

    public function allRoomsAssigned(): bool
    {
        return $this->rooms->every(fn (ReservationRoom $r) => $r->room_id !== null);
    }

    public function recalculateTotals(): void
    {
        $roomTotal = (float) $this->rooms()->sum('amount');
        $discount = min((float) $this->discount_amount, $roomTotal);

        $this->forceFill([
            'room_total'      => $roomTotal,
            'discount_amount' => $discount,
            'total_amount'    => $roomTotal - $discount,
        ])->save();
    }

    public function log(string $event, string $description, ?User $by = null): ReservationEvent
    {
        return $this->events()->create([
            'event'       => $event,
            'description' => $description,
            'user_id'     => ($by ?? auth()->user())?->id,
        ]);
    }

    /** Short, unambiguous booking reference, e.g. SH-7K3M9Q. */
    public static function newReference(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // no 0/O, 1/I
        do {
            $code = 'SH-';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (self::withoutGlobalScope('hotel')->where('reference', $code)->exists());

        return $code;
    }

    /** Random, unguessable token for the guest's QR code (spec §5: no personal data in the QR). */
    /** The guest's private link to their booking (not the same as the check-in QR token). */
    public static function newGuestToken(): string
    {
        do {
            $token = \Illuminate\Support\Str::random(48);
        } while (static::withoutGlobalScope('hotel')->where('guest_token', $token)->exists());

        return $token;
    }

    public static function newQrToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::withoutGlobalScope('hotel')->where('qr_token', $token)->exists());

        return $token;
    }
}
