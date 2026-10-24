<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A fault / work order (see MaintenanceService). */
class MaintenanceOrder extends Model
{
    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const ON_HOLD = 'on_hold';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const ACTIVE = [self::OPEN, self::IN_PROGRESS, self::ON_HOLD];

    /** key => [label, icon] */
    public const CATEGORIES = [
        'air_conditioning' => ['Air conditioning', 'ri-temp-cold-line'],
        'plumbing'         => ['Plumbing', 'ri-drop-line'],
        'electrical'       => ['Electrical', 'ri-flashlight-line'],
        'furniture'        => ['Furniture & fittings', 'ri-home-gear-line'],
        'wifi'             => ['Wi-Fi & TV', 'ri-wifi-line'],
        'appliance'        => ['Appliance', 'ri-fridge-line'],
        'building'         => ['Building & doors', 'ri-door-line'],
        'other'            => ['Other', 'ri-tools-line'],
    ];

    /** key => [label, pill, hours to fix] */
    public const PRIORITIES = [
        'urgent' => ['Urgent', 'st-danger', 4],
        'high'   => ['High', 'st-warning', 24],
        'normal' => ['Normal', 'st-info', 72],
        'low'    => ['Low', 'st-muted', 168],
    ];

    protected $fillable = ['room_id', 'location', 'category', 'title', 'description', 'priority', 'blocks_room', 'assigned_to', 'reported_by', 'housekeeping_task_id', 'due_at'];

    protected $casts = [
        'blocks_room'  => 'boolean',
        'due_at'       => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'cost'         => 'decimal:2',
        'photos'       => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->due_at && $this->due_at->isPast();
    }

    public function getWhereAttribute(): string
    {
        return $this->room ? 'Room ' . $this->room->number : ($this->location ?: '—');
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category][0] ?? ucfirst($this->category);
    }

    public function getCategoryIconAttribute(): string
    {
        return self::CATEGORIES[$this->category][1] ?? 'ri-tools-line';
    }

    public function getPriorityLabelAttribute(): string
    {
        return self::PRIORITIES[$this->priority][0] ?? ucfirst($this->priority);
    }

    public function getPriorityPillAttribute(): string
    {
        return self::PRIORITIES[$this->priority][1] ?? 'st-muted';
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::OPEN        => $this->assigned_to ? 'Assigned' : 'Open',
            self::IN_PROGRESS => 'In progress',
            self::ON_HOLD     => 'On hold',
            self::COMPLETED   => 'Completed',
            self::CANCELLED   => 'Cancelled',
            default           => ucfirst($this->status),
        };
    }

    public function getStatusPillAttribute(): string
    {
        return match ($this->status) {
            self::OPEN        => 'st-warning',
            self::IN_PROGRESS => 'st-info',
            self::ON_HOLD     => 'st-muted',
            self::COMPLETED   => 'st-success',
            default           => 'st-muted',
        };
    }

    /** [['url', 'stage']] for the photo gallery. */
    public function photoUrls(): array
    {
        return array_map(fn ($p) => ['url' => Storage::disk('public')->url($p['path']), 'stage' => $p['stage'] ?? 'report'], $this->photos ?? []);
    }
}
