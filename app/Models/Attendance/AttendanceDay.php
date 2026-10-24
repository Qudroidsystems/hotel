<?php

namespace App\Models\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AttendanceDay extends Model
{
    public const STATUSES = [
        'present'     => ['On time', 'st-success'],
        'late'        => ['Late', 'st-warning'],
        'missing_out' => ['No clock-out', 'st-violet'],
        'absent'      => ['Absent', 'st-danger'],
        'leave'       => ['On leave', 'st-info'],
        'off'         => ['Day off', 'st-muted'],
        'in'          => ['In now', 'st-success'],
        'due'         => ['Not in yet', 'st-muted'],
        'upcoming'    => ['Later', 'st-muted'],
    ];

    protected $fillable = ['user_id', 'work_date', 'shift_id', 'expected_start', 'expected_end', 'first_in', 'last_out', 'worked_minutes', 'late_minutes', 'early_minutes', 'overtime_minutes', 'is_in', 'status'];

    protected $casts = [
        'work_date'      => 'date',
        'expected_start' => 'datetime',
        'expected_end'   => 'datetime',
        'first_in'       => 'datetime',
        'last_out'       => 'datetime',
        'is_in'          => 'boolean',
    ];

    public function setWorkDateAttribute($value): void
    {
        $this->attributes['work_date'] = Carbon::parse($value)->toDateString();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public static function label(string $status): string
    {
        return self::STATUSES[$status][0] ?? ucfirst($status);
    }

    public static function pill(string $status): string
    {
        return self::STATUSES[$status][1] ?? 'st-muted';
    }

    public static function hours(int $minutes): string
    {
        return $minutes ? intdiv($minutes, 60) . 'h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm' : '—';
    }
}
