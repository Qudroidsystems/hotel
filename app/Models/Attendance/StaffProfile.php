<?php

namespace App\Models\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StaffProfile extends Model
{
    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /** What a badge QR code says: a prefix plus the random token. */
    public const QR_PREFIX = 'SHSTAFF:';

    protected $fillable = ['user_id', 'staff_no', 'department_id', 'shift_id', 'work_days', 'job_title', 'employment_start', 'tracks_attendance', 'badge_token', 'badge_issued_at'];

    protected $casts = [
        'work_days'         => 'array',
        'employment_start'  => 'date',
        'tracks_attendance' => 'boolean',
        'badge_issued_at'   => 'datetime',
    ];

    protected $hidden = ['badge_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public static function newBadgeToken(): string
    {
        do {
            $t = Str::random(40);
        } while (static::where('badge_token', $t)->exists());

        return $t;
    }

    public static function nextStaffNo(): string
    {
        $n = (int) static::max('id') + 1;
        do {
            $no = 'STF-' . str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (static::where('staff_no', $no)->exists());

        return $no;
    }

    public function getBadgeQrAttribute(): string
    {
        return self::QR_PREFIX . $this->badge_token;
    }

    public function worksOn(int $isoWeekday): bool
    {
        return in_array($isoWeekday, $this->work_days ?? [1, 2, 3, 4, 5, 6], true);
    }

    public function getWorkDaysLabelAttribute(): string
    {
        $days = $this->work_days ?? [1, 2, 3, 4, 5, 6];
        sort($days);

        return $days === [1, 2, 3, 4, 5, 6, 7] ? 'Every day' : ($days === [1, 2, 3, 4, 5] ? 'Mon–Fri' : ($days === [1, 2, 3, 4, 5, 6] ? 'Mon–Sat'
            : implode(', ', array_map(fn ($d) => self::DAYS[$d], $days))));
    }
}
