<?php

namespace App\Models\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AttendancePunch extends Model
{
    protected $fillable = ['uuid', 'user_id', 'point_id', 'punched_at', 'work_date', 'direction', 'source', 'photo_path', 'device_id', 'was_offline', 'ignored', 'note', 'recorded_by', 'synced_at'];

    protected $casts = [
        'punched_at'  => 'datetime',
        'work_date'   => 'date',
        'was_offline' => 'boolean',
        'ignored'     => 'boolean',
        'synced_at'   => 'datetime',
        'voided_at'   => 'datetime',
    ];

    public function setWorkDateAttribute($value): void
    {
        $this->attributes['work_date'] = Carbon::parse($value)->toDateString();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function point(): BelongsTo
    {
        return $this->belongsTo(AttendancePoint::class, 'point_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** Punches that count (not voided, not a repeat scan). */
    public function scopeCounting($query)
    {
        return $query->whereNull('voided_at')->where('ignored', false);
    }
}
