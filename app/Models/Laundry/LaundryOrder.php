<?php

namespace App\Models\Laundry;

use App\Models\FolioCharge;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A guest's laundry bag. See LaundryService. */
class LaundryOrder extends Model
{
    use BelongsToHotel;

    public const REQUESTED = 'requested';
    public const COLLECTED = 'collected';
    public const READY = 'ready';
    public const RETURNED = 'returned';
    public const DECLINED = 'declined';
    public const CANCELLED = 'cancelled';

    public const LIVE = [self::REQUESTED, self::COLLECTED, self::READY];

    /** status => [guest wording, pill] */
    public const STATUSES = [
        self::REQUESTED => ['Waiting to be collected', 'st-warning'],
        self::COLLECTED => ['At the laundry', 'st-info'],
        self::READY     => ['Clean — on its way back to you', 'st-primary'],
        self::RETURNED  => ['Returned to your room', 'st-success'],
        self::DECLINED  => ['Not accepted', 'st-danger'],
        self::CANCELLED => ['Cancelled', 'st-muted'],
    ];

    public const STAFF_LABELS = [
        self::REQUESTED => 'To collect', self::COLLECTED => 'At the laundry', self::READY => 'Ready to take up',
        self::RETURNED => 'Returned', self::DECLINED => 'Declined', self::CANCELLED => 'Cancelled by guest',
    ];

    protected $fillable = ['number', 'reservation_id', 'room_number', 'guest_name', 'lines', 'asked', 'express', 'subtotal', 'express_fee', 'total', 'note', 'status', 'via'];

    protected $casts = [
        'lines'        => 'array',
        'asked'        => 'array',
        'express'      => 'boolean',
        'subtotal'     => 'decimal:2',
        'express_fee'  => 'decimal:2',
        'total'        => 'decimal:2',
        'collected_at' => 'datetime',
        'due_at'       => 'datetime',
        'ready_at'     => 'datetime',
        'returned_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (LaundryOrder $o) {
            $o->forceFill(['number' => 'LD-' . str_pad((string) $o->id, 5, '0', STR_PAD_LEFT)])->saveQuietly();
        });
    }

    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function charge(): BelongsTo { return $this->belongsTo(FolioCharge::class, 'folio_charge_id'); }
    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collected_by'); }
    public function handler(): BelongsTo { return $this->belongsTo(User::class, 'handled_by'); }

    public function scopeLive($q)
    {
        return $q->whereIn('status', self::LIVE);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    /** At the laundry past the time promised to the guest. */
    public function isLate(): bool
    {
        return $this->status === self::COLLECTED && $this->due_at && $this->due_at->isPast();
    }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? $this->status; }
    public function getStaffLabelAttribute(): string { return self::STAFF_LABELS[$this->status] ?? $this->status; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }

    public function getPiecesAttribute(): int
    {
        return (int) collect($this->lines)->sum('quantity');
    }

    /** "2× Shirt (wash & iron), 1× Suit (dry clean)" */
    public function getSummaryAttribute(): string
    {
        return self::describe($this->lines ?? []);
    }

    public static function describe(array $lines): string
    {
        return collect($lines)->map(fn ($l) => (int) $l['quantity'] . '× ' . $l['name'] . ' (' . mb_strtolower(LaundryItem::serviceLabel($l['service'])) . ')')->implode(', ');
    }
}
