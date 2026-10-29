<?php

namespace App\Models;

use App\Models\Attendance\Department;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/** A job for a person or a department (spec §12.1). */
class StaffTask extends Model
{
    use BelongsToHotel;

    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const AWAITING_CHECK = 'awaiting_check';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';

    public const ACTIVE = [self::OPEN, self::IN_PROGRESS];
    public const STATUSES = [
        self::OPEN           => ['To do', 'st-warning'],
        self::IN_PROGRESS    => ['In progress', 'st-info'],
        self::AWAITING_CHECK => ['Waiting for check', 'st-violet'],
        self::DONE           => ['Done', 'st-success'],
        self::CANCELLED      => ['Cancelled', 'st-muted'],
    ];

    /** key => [label, pill, hours overdue before management is told] */
    public const PRIORITIES = [
        'urgent' => ['Urgent', 'st-danger', 1],
        'high'   => ['High', 'st-warning', 2],
        'normal' => ['Normal', 'st-info', 4],
        'low'    => ['Low', 'st-muted', 24],
    ];

    protected $fillable = ['title', 'details', 'department_id', 'assigned_to', 'room_id', 'location', 'priority', 'due_at', 'needs_check', 'attachments', 'created_by'];

    protected $casts = [
        'due_at'       => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'checked_at'   => 'datetime',
        'escalated_at' => 'datetime',
        'needs_check'  => 'boolean',
        'attachments'  => 'array',
    ];

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function completedBy(): BelongsTo { return $this->belongsTo(User::class, 'completed_by'); }
    public function checkedBy(): BelongsTo { return $this->belongsTo(User::class, 'checked_by'); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function updates(): HasMany { return $this->hasMany(StaffTaskUpdate::class)->latest('id'); }

    public function scopeActive($q) { return $q->whereIn('status', self::ACTIVE); }

    public function scopeOverdue($q) { return $q->active()->whereNotNull('due_at')->where('due_at', '<', now()); }

    public function isActive(): bool { return in_array($this->status, self::ACTIVE, true); }

    public function isOverdue(): bool { return $this->isActive() && $this->due_at && $this->due_at->isPast(); }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? ucfirst($this->status); }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
    public function getPriorityLabelAttribute(): string { return self::PRIORITIES[$this->priority][0] ?? ucfirst($this->priority); }
    public function getPriorityPillAttribute(): string { return self::PRIORITIES[$this->priority][1] ?? 'st-muted'; }

    /** Who it is for, in words. */
    public function getForLabelAttribute(): string
    {
        return $this->assignee?->name ?? ($this->department ? $this->department->name . ' (anyone)' : 'Unassigned');
    }

    public function getWhereAttribute(): ?string
    {
        return $this->room ? 'Room ' . $this->room->number : $this->location;
    }

    /** [['url', 'name', 'image', 'stage']] */
    public function files(): array
    {
        return array_map(fn ($a) => [
            'url'   => Storage::disk('public')->url($a['path']),
            'name'  => $a['name'] ?? basename($a['path']),
            'image' => str_starts_with($a['type'] ?? '', 'image/'),
            'stage' => $a['stage'] ?? 'brief',
        ], $this->attachments ?? []);
    }
}
