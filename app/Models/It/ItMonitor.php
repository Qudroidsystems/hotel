<?php

namespace App\Models\It;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something the control room checks automatically — a website, a router, the
 * database, the backup folder (spec §11.1–11.3).
 */
class ItMonitor extends Model
{
    use BelongsToHotel;

    /** type => [label, icon, what "target" means, what "threshold" means, default threshold] */
    public const TYPES = [
        'http'     => ['Website / API', 'ri-global-line', 'URL, e.g. https://smarthotel.com/up', 'Slow above (ms)', 3000],
        'ping'     => ['Ping (device)', 'ri-radar-line', 'IP address or host name', 'Slow above (ms)', 200],
        'tcp'      => ['Port check', 'ri-plug-line', 'IP address or host name (+ port)', 'Slow above (ms)', 1000],
        'ssl'      => ['SSL certificate', 'ri-lock-line', 'Domain, e.g. smarthotel.com', 'Warn when fewer days left than', 21],
        'database' => ['Database', 'ri-database-2-line', '(this system\'s database)', 'Slow above (ms)', 500],
        'disk'     => ['Disk space', 'ri-hard-drive-2-line', 'Folder on the server (blank = this system)', 'Warn when used % above', 85],
        'backup'   => ['Backups', 'ri-archive-drawer-line', 'Backup folder, e.g. storage/app/backups', 'Warn when newest backup older than (hours)', 26],
        'queue'    => ['Background jobs', 'ri-stack-line', '(this system\'s job queue)', 'Warn when failed jobs (24 h) more than', 0],
    ];

    public const CATEGORIES = [
        'network'        => ['Network', 'ri-router-line'],
        'application'    => ['Applications', 'ri-apps-2-line'],
        'infrastructure' => ['Infrastructure', 'ri-server-line'],
    ];

    /** status => [label, pill, colour] */
    public const STATUSES = [
        'up'          => ['Up', 'st-success', '#16a34a'],
        'degraded'    => ['Degraded', 'st-warning', '#f59e0b'],
        'down'        => ['Down', 'st-danger', '#dc2626'],
        'maintenance' => ['Maintenance', 'st-info', '#0284c7'],
        'paused'      => ['Paused', 'st-muted', '#94a3b8'],
        'unknown'     => ['Not checked', 'st-muted', '#94a3b8'],
    ];

    protected $fillable = ['name', 'category', 'type', 'target', 'port', 'asset_id', 'severity', 'interval_minutes', 'timeout_seconds', 'alert_after', 'expect_status', 'keyword', 'threshold', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active'       => 'boolean',
        'threshold'       => 'float',
        'last_checked_at' => 'datetime',
        'status_since'    => 'datetime',
    ];

    public function asset(): BelongsTo { return $this->belongsTo(ItAsset::class, 'asset_id'); }
    public function checks(): HasMany { return $this->hasMany(ItCheck::class, 'monitor_id'); }
    public function alerts(): HasMany { return $this->hasMany(ItAlert::class, 'monitor_id'); }

    public function scopeOrdered($q) { return $q->orderBy('category')->orderBy('sort_order')->orderBy('name'); }
    public function scopeActive($q) { return $q->where('is_active', true); }

    /**
     * Active monitors whose next check is due. "Now" comes from the app (not
     * the database clock) so it matches how last_checked_at was stored.
     */
    public function scopeDue($q)
    {
        $now = now()->addSeconds(10)->format('Y-m-d H:i:s');
        $next = match ($this->getConnection()->getDriverName()) {
            'sqlite' => "datetime(last_checked_at, '+' || interval_minutes || ' minutes')",
            'pgsql'  => "last_checked_at + (interval_minutes || ' minutes')::interval",
            default  => 'DATE_ADD(last_checked_at, INTERVAL interval_minutes MINUTE)',
        };

        return $q->active()->where(fn ($w) => $w->whereNull('last_checked_at')->orWhereRaw("{$next} <= ?", [$now]));
    }

    public function getTypeLabelAttribute(): string { return self::TYPES[$this->type][0] ?? $this->type; }
    public function getTypeIconAttribute(): string { return self::TYPES[$this->type][1] ?? 'ri-pulse-line'; }
    public function getDisplayStatusAttribute(): string { return $this->is_active ? $this->status : 'paused'; }
    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->display_status][0]; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->display_status][1]; }
    public function getStatusColorAttribute(): string { return self::STATUSES[$this->display_status][2]; }

    /** The threshold that applies: the monitor's own, or the type's default. */
    public function limit(): float
    {
        return $this->threshold ?? (float) (self::TYPES[$this->type][4] ?? 0);
    }

    public function getTargetLabelAttribute(): string
    {
        return match ($this->type) {
            'database' => 'This system\'s database',
            'queue'    => 'Background job queue',
            'disk'     => $this->target ?: 'This server',
            'tcp'      => $this->target . ($this->port ? ':' . $this->port : ''),
            default    => (string) $this->target,
        };
    }
}
