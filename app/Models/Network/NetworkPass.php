<?php

namespace App\Models\Network;

use App\Models\FolioCharge;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Wi-Fi code: lets a guest (or visitor) on the guest network until it expires. */
class NetworkPass extends Model
{
    use BelongsToHotel;

    /** kind => label */
    public const KINDS = [
        'stay'    => 'Hotel guest',
        'visitor' => 'Visitor / day guest',
        'event'   => 'Event / conference',
        'staff'   => 'Staff device',
    ];

    /** status => [label, pill] */
    public const STATUSES = [
        'active'  => ['Active', 'st-success'],
        'expired' => ['Expired', 'st-muted'],
        'revoked' => ['Cut off', 'st-danger'],
    ];

    protected $fillable = ['code', 'kind', 'reservation_id', 'plan_id', 'holder', 'room_number', 'devices', 'down_mbps', 'up_mbps', 'data_cap_mb',
        'starts_at', 'expires_at', 'status', 'issued_by'];

    protected $casts = [
        'starts_at'    => 'datetime',
        'expires_at'   => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at'   => 'datetime',
        'synced_at'    => 'datetime',
        'down_mbps'    => 'float',
        'up_mbps'      => 'float',
        'used_mb'      => 'float',
    ];

    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function plan(): BelongsTo { return $this->belongsTo(NetworkPlan::class, 'plan_id'); }
    public function issuedBy(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
    public function revokedBy(): BelongsTo { return $this->belongsTo(User::class, 'revoked_by'); }
    public function charge(): BelongsTo { return $this->belongsTo(FolioCharge::class, 'folio_charge_id'); }

    public function scopeActive($q) { return $q->where('status', 'active'); }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? ucfirst((string) $this->status); }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
    public function getKindLabelAttribute(): string { return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind); }

    public function getSpeedLabelAttribute(): string
    {
        return NetworkPlan::mbps($this->down_mbps) . ' / ' . NetworkPlan::mbps($this->up_mbps);
    }

    /** Data used today against the daily allowance, 0–100 (null when unlimited). */
    public function getCapUsedPercentAttribute(): ?int
    {
        if (! $this->data_cap_mb) {
            return null;
        }
        $days = max(1, (int) ceil($this->starts_at->diffInHours(min(now(), $this->expires_at)) / 24));

        return (int) min(100, round($this->used_mb / ($this->data_cap_mb * $days) * 100));
    }
}
