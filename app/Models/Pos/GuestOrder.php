<?php

namespace App\Models\Pos;

use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Food or drinks a guest ordered to their room (guest app or the room's QR card). See GuestOrderService. */
class GuestOrder extends Model
{
    use BelongsToHotel;

    public const PLACED = 'placed';
    public const ACCEPTED = 'accepted';
    public const ON_THE_WAY = 'on_the_way';
    public const DELIVERED = 'delivered';
    public const DECLINED = 'declined';
    public const CANCELLED = 'cancelled';

    public const LIVE = [self::PLACED, self::ACCEPTED, self::ON_THE_WAY];

    public const STATUSES = [
        self::PLACED     => ['Sent — waiting for the kitchen', 'st-warning'],
        self::ACCEPTED   => ['Being prepared', 'st-info'],
        self::ON_THE_WAY => ['On its way to your room', 'st-primary'],
        self::DELIVERED  => ['Delivered', 'st-success'],
        self::DECLINED   => ['Not accepted', 'st-danger'],
        self::CANCELLED  => ['Cancelled', 'st-muted'],
    ];

    /** Staff wording (the list on the website and the staff app). */
    public const STAFF_LABELS = [
        self::PLACED => 'New', self::ACCEPTED => 'Preparing', self::ON_THE_WAY => 'On the way',
        self::DELIVERED => 'Delivered', self::DECLINED => 'Declined', self::CANCELLED => 'Cancelled by guest',
    ];

    protected $fillable = ['uuid', 'number', 'reservation_id', 'outlet_id', 'room_number', 'guest_name', 'lines', 'note', 'total', 'status', 'via'];

    protected $casts = [
        'lines'        => 'array',
        'total'        => 'decimal:2',
        'accepted_at'  => 'datetime',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (GuestOrder $o) {
            $o->forceFill(['number' => 'RS-' . str_pad((string) $o->id, 5, '0', STR_PAD_LEFT)])->saveQuietly();
        });
    }

    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function outlet(): BelongsTo { return $this->belongsTo(PosOutlet::class, 'outlet_id'); }
    public function posOrder(): BelongsTo { return $this->belongsTo(PosOrder::class, 'pos_order_id'); }
    public function handler(): BelongsTo { return $this->belongsTo(User::class, 'handled_by'); }

    public function scopeLive($q)
    {
        return $q->whereIn('status', self::LIVE);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? $this->status; }
    public function getStaffLabelAttribute(): string { return self::STAFF_LABELS[$this->status] ?? $this->status; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }

    /** "2× Jollof rice, 1× Chapman" */
    public function getSummaryAttribute(): string
    {
        return collect($this->lines)->map(fn ($l) => rtrim(rtrim(number_format((float) $l['quantity'], 2), '0'), '.') . '× ' . $l['name'])->implode(', ');
    }
}
