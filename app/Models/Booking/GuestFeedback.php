<?php

namespace App\Models\Booking;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a guest thought of their stay (see FeedbackService). */
class GuestFeedback extends Model
{
    use BelongsToHotel;

    protected $table = 'guest_feedback';

    public const NEW = 'new';
    public const FOLLOW_UP = 'follow_up';
    public const RESOLVED = 'resolved';

    /** key => [label, icon] — the parts of the stay a guest can score. */
    public const ASPECTS = [
        'room'        => ['Room', 'ri-hotel-bed-line'],
        'cleanliness' => ['Cleanliness', 'ri-sparkling-line'],
        'staff'       => ['Staff & service', 'ri-user-smile-line'],
        'food'        => ['Food & drink', 'ri-restaurant-line'],
        'value'       => ['Value for money', 'ri-price-tag-3-line'],
    ];

    public const STATUSES = [
        self::NEW       => ['New', 'st-info'],
        self::FOLLOW_UP => ['Needs follow-up', 'st-danger'],
        self::RESOLVED  => ['Handled', 'st-success'],
    ];

    protected $fillable = ['reservation_id', 'guest_id', 'overall', 'ratings', 'recommend', 'comment', 'contact_ok', 'may_quote', 'source', 'status'];

    protected $casts = [
        'overall'    => 'integer',
        'ratings'    => 'array',
        'recommend'  => 'integer',
        'contact_ok' => 'boolean',
        'may_quote'  => 'boolean',
        'handled_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function isLow(): bool
    {
        return $this->overall <= (int) config('hotel.feedback.alert_below', 2);
    }

    /** promoter (9–10), passive (7–8), detractor (0–6) or null. */
    public function getNpsGroupAttribute(): ?string
    {
        return match (true) {
            $this->recommend === null => null,
            $this->recommend >= 9     => 'promoter',
            $this->recommend >= 7     => 'passive',
            default                   => 'detractor',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }
}
