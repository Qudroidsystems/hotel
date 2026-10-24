<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A room to clean (see HousekeepingService for the lifecycle). */
class HousekeepingTask extends Model
{
    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const DONE = 'done';            // cleaned, waiting for a supervisor
    public const PASSED = 'passed';        // inspected — room released
    public const CANCELLED = 'cancelled';

    public const OPEN = [self::PENDING, self::IN_PROGRESS, self::DONE];

    public const TYPES = [
        'checkout' => 'Check-out clean',
        'stayover' => 'Stay-over service',
        'deep'     => 'Deep clean',
        'turndown' => 'Turn-down',
        'other'    => 'Other',
    ];

    protected $fillable = ['room_id', 'type', 'urgent', 'assigned_to', 'reservation_id', 'notes', 'created_by'];

    protected $casts = [
        'urgent'       => 'boolean',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'inspected_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function finishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finished_by');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function maintenanceOrders(): HasMany
    {
        return $this->hasMany(MaintenanceOrder::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::PENDING     => 'To clean',
            self::IN_PROGRESS => 'Cleaning',
            self::DONE        => 'Awaiting inspection',
            self::PASSED      => 'Ready',
            self::CANCELLED   => 'Cancelled',
            default           => ucfirst($this->status),
        };
    }

    public function getStatusPillAttribute(): string
    {
        return match ($this->status) {
            self::PENDING     => 'st-warning',
            self::IN_PROGRESS => 'st-info',
            self::DONE        => 'st-info',
            self::PASSED      => 'st-success',
            default           => 'st-muted',
        };
    }

    /** Minutes from the task being raised to the room being released. */
    public function turnaroundMinutes(): ?int
    {
        $end = $this->inspected_at ?? ($this->status === self::PASSED ? $this->finished_at : null);

        return $end ? (int) $this->created_at->diffInMinutes($end) : null;
    }
}
