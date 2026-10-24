<?php

namespace App\Models;

use App\Models\Pos\PosOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One cashier's drawer from opening float to cash count (see CashShiftService). */
class CashShift extends Model
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';
    public const REVIEWED = 'reviewed';

    protected $fillable = ['user_id', 'station', 'outlet_id', 'opening_float', 'opened_at'];

    protected $casts = [
        'opening_float' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'counted_cash'  => 'decimal:2',
        'variance'      => 'decimal:2',
        'summary'       => 'array',
        'denominations' => 'array',
        'opened_at'     => 'datetime',
        'closed_at'     => 'datetime',
        'reviewed_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashShiftMovement::class)->orderBy('id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::OPEN     => 'Open',
            self::CLOSED   => 'Awaiting review',
            self::REVIEWED => 'Signed off',
            default        => ucfirst($this->status),
        };
    }

    public function getStatusPillAttribute(): string
    {
        return match ($this->status) {
            self::OPEN     => 'st-info',
            self::CLOSED   => 'st-warning',
            self::REVIEWED => 'st-success',
            default        => 'st-muted',
        };
    }

    /** Short / over / balanced, for the variance badge. */
    public function varianceLabel(): ?string
    {
        if ($this->variance === null) {
            return null;
        }
        $v = (float) $this->variance;

        return abs($v) < 0.01 ? 'Balanced' : ($v < 0 ? 'Short' : 'Over');
    }
}
