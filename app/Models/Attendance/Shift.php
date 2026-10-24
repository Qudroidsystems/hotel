<?php

namespace App\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Shift extends Model
{
    protected $fillable = ['name', 'code', 'start_time', 'end_time', 'grace_minutes', 'early_grace_minutes', 'break_minutes', 'color', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** A night shift: ends the next morning. */
    public function crossesMidnight(): bool
    {
        return substr($this->end_time, 0, 5) <= substr($this->start_time, 0, 5);
    }

    /** [start, end] of this shift on a work date. */
    public function window($workDate): array
    {
        $d = Carbon::parse($workDate)->toDateString();
        $start = Carbon::parse($d . ' ' . substr($this->start_time, 0, 5));
        $end = Carbon::parse($d . ' ' . substr($this->end_time, 0, 5));
        if ($this->crossesMidnight()) {
            $end->addDay();
        }

        return [$start, $end];
    }

    /** Scheduled working minutes (less the unpaid break). */
    public function minutes(): int
    {
        [$s, $e] = $this->window(today());

        return max(0, (int) $s->diffInMinutes($e) - (int) $this->break_minutes);
    }

    public function getHoursLabelAttribute(): string
    {
        return Carbon::parse($this->start_time)->format('g:i A') . ' – ' . Carbon::parse($this->end_time)->format('g:i A');
    }
}
