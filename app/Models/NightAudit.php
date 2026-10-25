<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** A closed business day and its report. */
class NightAudit extends Model
{
    protected $fillable = ['business_date', 'status', 'report', 'actions', 'notes', 'automatic', 'closed_by', 'closed_at'];

    protected $casts = [
        'business_date' => 'date',
        'report'        => 'array',
        'actions'       => 'array',
        'automatic'     => 'boolean',
        'closed_at'     => 'datetime',
    ];

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** The last day closed, or null before the first night audit. */
    public static function lastClosed(): ?Carbon
    {
        try {
            $d = static::max('business_date');
        } catch (\Throwable) {
            return null;   // before the table exists
        }

        return $d ? Carbon::parse($d)->startOfDay() : null;
    }

    /**
     * The day being worked: the day after the last close. Before the first night audit it is today — or
     * yesterday in the small hours, while the night shift is still finishing the day (Hotel Settings →
     * Night Audit → the day ends at).
     */
    public static function businessDate(): Carbon
    {
        $last = static::lastClosed();
        if ($last) {
            return $last->copy()->addDay();
        }
        $ends = (string) config('hotel.night_audit.day_ends_at', '06:00');

        return now()->format('H:i') < $ends ? today()->subDay() : today();
    }

    /** Has the night audit closed this date? */
    public static function isClosed($date): bool
    {
        $last = static::lastClosed();

        return $last !== null && Carbon::parse($date)->startOfDay()->lte($last);
    }
}
