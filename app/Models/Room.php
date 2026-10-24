<?php

namespace App\Models;

use App\Enums\RoomStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Room extends Model
{
    protected $fillable = [
        'number', 'building_id', 'floor_id', 'room_type_id', 'status',
        'rate_override', 'features', 'notes', 'is_active',
    ];

    protected $casts = [
        'status'            => RoomStatus::class,
        'rate_override'     => 'decimal:2',
        'features'          => 'array',
        'is_active'         => 'boolean',
        'status_changed_at' => 'datetime',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(RoomStatusLog::class)->latest('created_at')->latest('id');
    }

    public function housekeepingTasks(): HasMany
    {
        return $this->hasMany(HousekeepingTask::class);
    }

    public function reservationRooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class);
    }

    /** Nightly rate for a given night: this room's custom rate, else the room type's rate for that night. */
    public function rateFor(\Carbon\CarbonInterface $night): float
    {
        return $this->rate_override !== null ? (float) $this->rate_override : $this->roomType->rateFor($night);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** Rooms that can be sold right now. */
    public function scopeSellable($query)
    {
        return $query->active()->where('status', RoomStatus::Available->value);
    }

    /** Effective nightly base rate (room override, else room type rate). */
    public function getRateAttribute(): float
    {
        return (float) ($this->rate_override ?? $this->roomType?->base_rate ?? 0);
    }

    /**
     * Change the status and record who did it and why. Every status change in
     * the system should go through here so the history stays complete.
     */
    public function changeStatus(RoomStatus $to, ?string $reason = null, ?User $by = null): bool
    {
        if ($this->status === $to) {
            return false;
        }

        $by ??= auth()->user();

        DB::transaction(function () use ($to, $reason, $by) {
            $from = $this->status;

            $this->forceFill([
                'status'            => $to,
                'status_changed_at' => now(),
                'status_changed_by' => $by?->id,
            ])->save();

            $this->statusLogs()->create([
                'from_status' => $from?->value,
                'to_status'   => $to->value,
                'reason'      => $reason,
                'user_id'     => $by?->id,
            ]);
        });

        return true;
    }
}
