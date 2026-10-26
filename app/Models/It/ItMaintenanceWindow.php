<?php

namespace App\Models\It;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Planned maintenance, announced to staff; alerts for the listed monitors are held while it runs (spec §11.4). */
class ItMaintenanceWindow extends Model
{
    use BelongsToHotel;

    protected $fillable = ['title', 'description', 'starts_at', 'ends_at', 'affects', 'monitor_ids', 'created_by'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'monitor_ids' => 'array'];

    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeLive($q) { return $q->whereNull('cancelled_at'); }
    public function scopeNow($q) { return $q->live()->where('starts_at', '<=', now())->where('ends_at', '>', now()); }
    public function scopeUpcoming($q) { return $q->live()->where('ends_at', '>', now()); }

    public function isRunning(): bool { return ! $this->cancelled_at && $this->starts_at->lte(now()) && $this->ends_at->gt(now()); }

    public function covers(int $monitorId): bool
    {
        return in_array($monitorId, array_map('intval', $this->monitor_ids ?? []), true);
    }

    public function getStateAttribute(): string
    {
        return match (true) {
            (bool) $this->cancelled_at   => 'cancelled',
            $this->ends_at->lte(now())   => 'done',
            $this->starts_at->lte(now()) => 'running',
            default                      => 'planned',
        };
    }
}
