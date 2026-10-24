<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/** A reported incident and what was done about it (spec §10.2). */
class Incident extends Model
{
    public const TYPES = [
        'security'        => ['Security / intrusion', 'ri-shield-flash-line'],
        'theft'           => ['Theft / loss', 'ri-spy-line'],
        'property_damage' => ['Property damage', 'ri-hammer-line'],
        'guest_complaint' => ['Guest complaint', 'ri-emotion-unhappy-line'],
        'disturbance'     => ['Disturbance / fight', 'ri-volume-up-line'],
        'injury'          => ['Injury / medical', 'ri-first-aid-kit-line'],
        'fire_safety'     => ['Fire / safety hazard', 'ri-fire-line'],
        'operational'     => ['Operational disruption', 'ri-error-warning-line'],
        'other'           => ['Other', 'ri-file-warning-line'],
    ];

    /** key => [label, pill] */
    public const SEVERITIES = [
        'critical' => ['Critical', 'st-danger'],
        'high'     => ['High', 'st-warning'],
        'medium'   => ['Medium', 'st-info'],
        'low'      => ['Low', 'st-muted'],
    ];

    public const STATUSES = [
        'open'          => ['Open', 'st-warning'],
        'investigating' => ['Investigating', 'st-info'],
        'resolved'      => ['Resolved', 'st-success'],
        'closed'        => ['Closed', 'st-muted'],
    ];

    public const ACTIVE = ['open', 'investigating'];

    protected $fillable = ['type', 'severity', 'title', 'description', 'location', 'room_id', 'reservation_id', 'occurred_at', 'people_involved', 'police_reference', 'reported_by', 'assigned_to'];

    protected $casts = [
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
        'photos'      => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderBy('id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isSerious(): bool
    {
        return in_array($this->severity, ['high', 'critical'], true);
    }

    public function getTypeLabelAttribute(): string { return self::TYPES[$this->type][0] ?? ucfirst($this->type); }
    public function getTypeIconAttribute(): string { return self::TYPES[$this->type][1] ?? 'ri-file-warning-line'; }
    public function getSeverityLabelAttribute(): string { return self::SEVERITIES[$this->severity][0] ?? ucfirst($this->severity); }
    public function getSeverityPillAttribute(): string { return self::SEVERITIES[$this->severity][1] ?? 'st-muted'; }
    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? ucfirst($this->status); }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }

    public function getWhereAttribute(): string
    {
        return trim(($this->room ? 'Room ' . $this->room->number : '') . ($this->room && $this->location ? ' · ' : '') . ($this->location ?? '')) ?: '—';
    }

    public function photoUrls(): array
    {
        return array_map(fn ($p) => Storage::disk('public')->url($p), $this->photos ?? []);
    }
}
