<?php

namespace App\Models\Network;

use App\Models\It\ItAlert;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One internet line into the hotel: a Starlink dish, a fibre line, a 4G router. */
class NetworkLink extends Model
{
    use BelongsToHotel;

    /** provider => [label, icon] */
    public const PROVIDERS = [
        'starlink' => ['Starlink', 'ri-rocket-2-line'],
        'fibre'    => ['Fibre / cable', 'ri-git-commit-line'],
        'lte'      => ['4G / 5G', 'ri-signal-tower-line'],
        'other'    => ['Other', 'ri-global-line'],
    ];

    /** role => [label, what it carries] */
    public const ROLES = [
        'shared' => ['Shared', 'Staff and guests, balanced with the other lines'],
        'guests' => ['Guests', 'Guest Wi-Fi only'],
        'staff'  => ['Staff & systems', 'Front desk, POS, payments, cameras'],
        'backup' => ['Backup', 'Only used when another line drops'],
    ];

    /** status => [label, pill, colour] */
    public const STATUSES = [
        'online'   => ['Online', 'st-success', '#16a34a'],
        'degraded' => ['Weak', 'st-warning', '#f59e0b'],
        'offline'  => ['Offline', 'st-danger', '#dc2626'],
        'unknown'  => ['Not read yet', 'st-muted', '#94a3b8'],
    ];

    protected $fillable = ['name', 'provider', 'role', 'identifier', 'address', 'router_port', 'weight', 'data_cap_gb', 'billing_day',
        'monthly_cost', 'location', 'notes', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active'       => 'boolean',
        'data_cap_gb'     => 'float',
        'monthly_cost'    => 'float',
        'down_mbps'       => 'float',
        'up_mbps'         => 'float',
        'packet_loss'     => 'float',
        'obstruction_pct' => 'float',
        'used_gb_month'   => 'float',
        'checked_at'      => 'datetime',
        'status_since'    => 'datetime',
    ];

    public function samples(): HasMany { return $this->hasMany(NetworkSample::class, 'link_id'); }
    public function alert(): BelongsTo { return $this->belongsTo(ItAlert::class, 'alert_id'); }

    public function scopeOrdered($q) { return $q->orderBy('sort_order')->orderBy('name'); }
    public function scopeActive($q) { return $q->where('is_active', true); }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? ucfirst((string) $this->status); }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
    public function getStatusColorAttribute(): string { return self::STATUSES[$this->status][2] ?? '#94a3b8'; }
    public function getProviderLabelAttribute(): string { return self::PROVIDERS[$this->provider][0] ?? ucfirst((string) $this->provider); }
    public function getProviderIconAttribute(): string { return self::PROVIDERS[$this->provider][1] ?? 'ri-global-line'; }
    public function getRoleLabelAttribute(): string { return self::ROLES[$this->role][0] ?? ucfirst((string) $this->role); }

    /** Share of the monthly allowance used, 0–100, or null without an allowance. */
    public function getCapUsedPercentAttribute(): ?int
    {
        return $this->data_cap_gb ? (int) min(100, round($this->used_gb_month / $this->data_cap_gb * 100)) : null;
    }

    /** When the current allowance month started. */
    public function periodStart(): \Illuminate\Support\Carbon
    {
        $day = max(1, min(28, (int) $this->billing_day));
        $start = today()->day($day);

        return $start->gt(today()) ? $start->subMonthNoOverflow() : $start;
    }
}
