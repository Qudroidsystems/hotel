<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Servicing that repeats: the generator every month, AC units every quarter… (see MaintenancePlanService). */
class MaintenancePlan extends Model
{
    use BelongsToHotel;

    public const UNITS = ['day' => ['day', 'days'], 'week' => ['week', 'weeks'], 'month' => ['month', 'months']];

    protected $fillable = ['title', 'category', 'scope', 'room_id', 'location', 'description', 'every', 'unit', 'next_due_on', 'lead_days', 'priority', 'assigned_to', 'active', 'created_by'];

    protected $casts = [
        'every'        => 'integer',
        'lead_days'    => 'integer',
        'active'       => 'boolean',
        'next_due_on'  => 'date',
        'last_done_on' => 'date',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MaintenanceOrder::class);
    }

    /** The date after $from, one interval on. */
    public function after(Carbon $from): Carbon
    {
        return match ($this->unit) {
            'day'   => $from->copy()->addDays($this->every),
            'week'  => $from->copy()->addWeeks($this->every),
            default => $from->copy()->addMonthsNoOverflow($this->every),
        };
    }

    /** "Every 3 months" */
    public function getIntervalLabelAttribute(): string
    {
        [$one, $many] = self::UNITS[$this->unit] ?? ['month', 'months'];

        return $this->every === 1 ? 'Every ' . $one : "Every {$this->every} {$many}";
    }

    public function getWhereAttribute(): string
    {
        return match (true) {
            $this->scope === 'rooms' => 'Every room',
            (bool) $this->room       => 'Room ' . $this->room->number,
            default                  => $this->location ?: '—',
        };
    }

    public function isOverdue(): bool
    {
        return $this->active && $this->next_due_on->lt(today());
    }

    /** When the work order for the next service is opened. */
    public function opensOn(): Carbon
    {
        return $this->next_due_on->copy()->subDays($this->lead_days);
    }
}
