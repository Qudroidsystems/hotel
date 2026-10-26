<?php

namespace App\Models\It;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A problem the monitors found (or raised by hand) waiting for IT to act (spec §11.3 alert escalation). */
class ItAlert extends Model
{
    use BelongsToHotel;

    public const STATUSES = [
        'open'         => ['Open', 'st-danger'],
        'acknowledged' => ['Being handled', 'st-warning'],
        'resolved'     => ['Resolved', 'st-success'],
    ];

    protected $fillable = ['monitor_id', 'severity', 'title', 'message', 'status', 'opened_at'];

    protected $casts = ['opened_at' => 'datetime', 'acknowledged_at' => 'datetime', 'resolved_at' => 'datetime', 'auto_resolved' => 'boolean'];

    public function monitor(): BelongsTo { return $this->belongsTo(ItMonitor::class, 'monitor_id'); }
    public function ticket(): BelongsTo { return $this->belongsTo(ItTicket::class, 'ticket_id'); }
    public function acknowledgedBy(): BelongsTo { return $this->belongsTo(User::class, 'acknowledged_by'); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'resolved_by'); }

    public function scopeActive($q) { return $q->whereIn('status', ['open', 'acknowledged']); }
    public function isActive(): bool { return $this->status !== 'resolved'; }

    public function getSeverityLabelAttribute(): string { return ItTicket::PRIORITIES[$this->severity][0] ?? ucfirst($this->severity); }
    public function getSeverityPillAttribute(): string { return ItTicket::PRIORITIES[$this->severity][1] ?? 'st-muted'; }
    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? $this->status; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }

    /** How long it was (or has been) open. */
    public function duration(): string
    {
        return $this->opened_at->diffForHumans($this->resolved_at ?? now(), true);
    }
}
