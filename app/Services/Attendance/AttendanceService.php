<?php

namespace App\Services\Attendance;

use App\Exceptions\BookingException;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePoint;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\Shift;
use App\Models\Attendance\StaffLeave;
use App\Models\Attendance\StaffProfile;
use App\Models\Attendance\StaffRota;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Staff attendance.
 *
 * A punch is just "this person was at a kiosk at this time". Whether it was
 * an "in" or an "out", and the day's lateness, hours and overtime, are worked
 * out from all of that day's punches (recompute()), so punches that arrive
 * late from an offline kiosk, or are added/voided by HR, always give the
 * right answer.
 */
class AttendanceService
{
    /** A second scan this soon after the last one is a double tap, not an "out". */
    public const REPEAT_SECONDS = 120;

    /** Punches up to this long after a night shift ends still belong to that shift. */
    public const NIGHT_TAIL_HOURS = 6;

    /** Extra time only counts as overtime from this many minutes over the shift. */
    public const OVERTIME_FROM_MINUTES = 30;

    /** Unpaid break is only taken off a day worked at least this long. */
    public const BREAK_AFTER_MINUTES = 360;

    // ── Schedules ─────────────────────────────────────────────────────

    /**
     * What someone was meant to do on a date: ['shift' => ?Shift, 'leave' => ?StaffLeave, 'rota' => ?StaffRota].
     * A rota entry overrides the usual pattern; leave overrides both.
     */
    public function scheduleFor(StaffProfile $p, $date, ?array $preload = null): array
    {
        $d = Carbon::parse($date)->toDateString();
        $leave = $preload ? ($preload['leaves'][$p->user_id] ?? collect())->first(fn ($l) => $l->from_date->toDateString() <= $d && $l->to_date->toDateString() >= $d)
            : StaffLeave::approved()->where('user_id', $p->user_id)->covering($d)->first();
        $rota = $preload ? ($preload['rota'][$p->user_id . '|' . $d] ?? null)
            : StaffRota::with('shift')->where('user_id', $p->user_id)->whereDate('work_date', $d)->first();

        if ($rota) {
            $shift = $rota->shift;
        } else {
            $shift = $p->worksOn(Carbon::parse($d)->isoWeekday()) ? $p->shift : null;
        }

        return ['shift' => $shift, 'leave' => $leave, 'rota' => $rota];
    }

    /** Rota and leave for many people and dates at once (for boards and timesheets). */
    public function preload(array $userIds, $from, $to): array
    {
        $f = Carbon::parse($from)->toDateString();
        $t = Carbon::parse($to)->toDateString();

        return [
            'leaves' => StaffLeave::approved()->whereIn('user_id', $userIds)->where('from_date', '<=', $t)->where('to_date', '>=', $f)->get()->groupBy('user_id'),
            'rota'   => StaffRota::with('shift')->whereIn('user_id', $userIds)->whereBetween('work_date', [$f, $t])->get()
                ->keyBy(fn ($r) => $r->user_id . '|' . $r->work_date->toDateString()),
        ];
    }

    /** The shift day a punch belongs to. Early-morning punches after a night shift belong to the night before. */
    public function workDateFor(StaffProfile $p, Carbon $at): string
    {
        $prev = $at->copy()->subDay()->toDateString();
        $shift = $this->scheduleFor($p, $prev)['shift'];
        if ($shift && $shift->crossesMidnight()) {
            [, $end] = $shift->window($prev);
            if ($at->lte($end->copy()->addHours(self::NIGHT_TAIL_HOURS))) {
                // Unless they are clearly starting today's own shift.
                $todayShift = $this->scheduleFor($p, $at->toDateString())['shift'];
                $startsToday = $todayShift ? $todayShift->window($at->toDateString())[0] : null;
                if (! $startsToday || $at->lt($startsToday->copy()->subHours(2))) {
                    return $prev;
                }
            }
        }

        return $at->toDateString();
    }

    // ── Recording ─────────────────────────────────────────────────────

    /**
     * Record one punch. $data: uuid, punched_at, point_id, device_id, was_offline, photo (data URL), note, source.
     * Returns [punch, result] where result is ok | duplicate | repeat.
     */
    public function punch(StaffProfile $p, array $data, ?User $by = null): array
    {
        $uuid = $data['uuid'] ?? (string) Str::uuid();
        if ($existing = AttendancePunch::where('uuid', $uuid)->first()) {
            return [$existing, 'duplicate'];
        }

        $note = $data['note'] ?? null;
        $at = Carbon::parse($data['punched_at'] ?? now())->setTimezone(config('app.timezone'));
        if ($at->gt(now()->addMinutes(10))) {
            $note = trim(($note ? $note . ' ' : '') . 'Kiosk clock was ahead (' . $at->format('j M H:i') . ').');
            $at = now();
        }
        $workDate = $this->workDateFor($p, $at);

        $last = AttendancePunch::counting()->where('user_id', $p->user_id)->where('punched_at', '<=', $at)->orderByDesc('punched_at')->first();
        $repeat = ($data['source'] ?? 'kiosk') === 'kiosk' && $last && $last->punched_at->diffInSeconds($at) < self::REPEAT_SECONDS;

        try {
            $punch = DB::transaction(function () use ($p, $data, $uuid, $at, $workDate, $repeat, $note, $by) {
                $punch = AttendancePunch::create([
                    'uuid'        => $uuid,
                    'user_id'     => $p->user_id,
                    'point_id'    => ! empty($data['point_id']) && AttendancePoint::whereKey($data['point_id'])->exists() ? $data['point_id'] : null,
                    'punched_at'  => $at,
                    'work_date'   => $workDate,
                    'source'      => $data['source'] ?? 'kiosk',
                    'device_id'   => $data['device_id'] ?? null,
                    'was_offline' => (bool) ($data['was_offline'] ?? false),
                    'ignored'     => $repeat,
                    'note'        => $note ? mb_substr($note, 0, 255) : null,
                    'recorded_by' => $by?->id,
                    'synced_at'   => now(),
                ]);
                if (! empty($data['photo'])) {
                    $punch->forceFill(['photo_path' => $this->savePhoto($punch, $data['photo'])])->save();
                }
                $this->recompute($p, $workDate);

                return $punch->fresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = AttendancePunch::where('uuid', $uuid)->first()) {
                return [$existing, 'duplicate'];
            }
            throw $e;
        }

        return [$punch, $repeat ? 'repeat' : 'ok'];
    }

    private function savePhoto(AttendancePunch $punch, string $dataUrl): ?string
    {
        if (! preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#', $dataUrl, $m)) {
            return null;
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) > 400_000 || @getimagesizefromstring($bytes) === false) {
            return null;
        }
        $path = 'attendance/' . $punch->punched_at->format('Y/m') . '/' . $punch->uuid . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    /** HR adds a punch someone forgot (or a badge failed). */
    public function manualPunch(User $staff, Carbon $at, string $reason, User $by): AttendancePunch
    {
        $p = StaffProfile::where('user_id', $staff->id)->first() ?? throw new BookingException('This person has no staff profile yet.');
        if ($at->gt(now()->addMinutes(5))) {
            throw new BookingException('You can’t add a clock-in in the future.');
        }
        [$punch] = $this->punch($p, ['punched_at' => $at, 'source' => 'manual', 'note' => $reason], $by);

        return $punch;
    }

    public function voidPunch(AttendancePunch $punch, string $reason, User $by): void
    {
        if ($punch->voided_at) {
            throw new BookingException('This clock-in is already removed.');
        }
        DB::transaction(function () use ($punch, $reason, $by) {
            $punch->forceFill(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason])->save();
            $this->recompute(StaffProfile::where('user_id', $punch->user_id)->firstOrFail(), $punch->work_date);
        });
    }

    // ── The day's summary ─────────────────────────────────────────────

    /** Work out a person's day from their punches. Stored for days with punches; removed otherwise. */
    public function recompute(StaffProfile $p, $date): ?AttendanceDay
    {
        $d = Carbon::parse($date)->toDateString();
        $punches = AttendancePunch::counting()->where('user_id', $p->user_id)->whereDate('work_date', $d)->orderBy('punched_at')->orderBy('id')->get();

        // In, out, in, out… in the order they happened.
        foreach ($punches->values() as $i => $pu) {
            $dir = $i % 2 === 0 ? 'in' : 'out';
            if ($pu->direction !== $dir) {
                $pu->forceFill(['direction' => $dir])->saveQuietly();
            }
        }
        // Voided / repeat scans have no direction.
        AttendancePunch::where('user_id', $p->user_id)->whereDate('work_date', $d)
            ->where(fn ($q) => $q->whereNotNull('voided_at')->orWhere('ignored', true))->whereNotNull('direction')->update(['direction' => null]);

        $existing = AttendanceDay::where('user_id', $p->user_id)->whereDate('work_date', $d)->first();
        if ($punches->isEmpty()) {
            $existing?->delete();

            return null;
        }

        $row = $this->summarise($p, $d, $punches);
        $day = $existing ?? new AttendanceDay(['user_id' => $p->user_id, 'work_date' => $d]);
        $day->fill($row)->save();

        return $day;
    }

    /** The numbers for one day from its (counting) punches. */
    private function summarise(StaffProfile $p, string $d, Collection $punches): array
    {
        $sched = $this->scheduleFor($p, $d);
        $shift = $sched['shift'];
        [$start, $end] = $shift ? $shift->window($d) : [null, null];

        $first = $punches->first()->punched_at;
        $isIn = $punches->count() % 2 === 1;
        $lastOut = $isIn ? ($punches->count() > 1 ? $punches[$punches->count() - 2]->punched_at : null) : $punches->last()->punched_at;

        $worked = 0;
        foreach ($punches->chunk(2) as $pair) {
            $pair = $pair->values();
            if ($pair->count() === 2) {
                $worked += (int) $pair[0]->punched_at->diffInMinutes($pair[1]->punched_at);
            }
        }
        if ($shift && $shift->break_minutes && $worked >= self::BREAK_AFTER_MINUTES) {
            $worked -= (int) $shift->break_minutes;
        }

        $late = $early = $overtime = 0;
        if ($shift) {
            if ($first->gt($start->copy()->addMinutes((int) $shift->grace_minutes))) {
                $late = (int) $start->diffInMinutes($first);
            }
            if (! $isIn && $lastOut && $lastOut->lt($end->copy()->subMinutes((int) $shift->early_grace_minutes))) {
                $early = (int) $lastOut->diffInMinutes($end);
            }
            $extra = $worked - $shift->minutes();
            $overtime = $extra >= self::OVERTIME_FROM_MINUTES ? $extra : 0;
        } else {
            $overtime = $worked; // worked on a day off
        }

        // Still "in" long after the shift (or the day) ended = forgot to clock out.
        $dayOver = $shift ? now()->gt($end->copy()->addHours(self::NIGHT_TAIL_HOURS)) : Carbon::parse($d)->endOfDay()->addHours(self::NIGHT_TAIL_HOURS)->isPast();
        $status = $isIn && $dayOver ? 'missing_out' : ($late > 0 ? 'late' : 'present');

        return [
            'shift_id'         => $shift?->id,
            'expected_start'   => $start,
            'expected_end'     => $end,
            'first_in'         => $first,
            'last_out'         => $lastOut,
            'worked_minutes'   => $worked,
            'late_minutes'     => $late,
            'early_minutes'    => $early,
            'overtime_minutes' => $overtime,
            'is_in'            => $isIn && ! $dayOver,
            'status'           => $status,
        ];
    }

    /**
     * A person's day for display — from the stored summary if they punched,
     * otherwise from their schedule (absent / on leave / day off / not in yet).
     */
    public function dayView(StaffProfile $p, string $d, ?AttendanceDay $row, ?array $preload = null): array
    {
        $sched = $this->scheduleFor($p, $d, $preload);
        $shift = $sched['shift'];
        [$start, $end] = $shift ? $shift->window($d) : [null, null];
        $started = $p->employment_start === null || $p->employment_start->toDateString() <= $d;

        if ($row) {
            $status = $row->status;
            if ($row->is_in && $status !== 'missing_out') {
                $status = $row->late_minutes > 0 ? 'late' : 'in';
            }
        } elseif (! $started) {
            $status = 'off';
        } elseif ($sched['leave']) {
            $status = 'leave';
        } elseif (! $shift) {
            $status = 'off';
        } elseif (now()->lt($start)) {
            $status = 'upcoming';
        } elseif (now()->lt($end)) {
            $status = now()->gt($start->copy()->addMinutes((int) $shift->grace_minutes)) ? 'due' : 'upcoming';
        } else {
            $status = 'absent';
        }

        return [
            'date'     => $d,
            'status'   => $status,
            'shift'    => $shift,
            'leave'    => $sched['leave'],
            'rota'     => $sched['rota'],
            'start'    => $start,
            'end'      => $end,
            'row'      => $row,
            'is_in'    => (bool) $row?->is_in,
            'late'     => (int) ($row?->late_minutes ?? 0),
            'early'    => (int) ($row?->early_minutes ?? 0),
            'worked'   => (int) ($row?->worked_minutes ?? 0),
            'overtime' => (int) ($row?->overtime_minutes ?? 0),
            'overdue'  => $status === 'due' ? (int) $start->diffInMinutes(now()) : 0,
        ];
    }

    /** Everyone tracked, for one date. */
    public function board(string $d, ?int $departmentId = null): Collection
    {
        $profiles = StaffProfile::with(['user', 'department', 'shift'])->where('tracks_attendance', true)
            ->whereHas('user', fn ($q) => $q->where('is_disabled', false))
            ->when($departmentId, fn ($q, $v) => $q->where('department_id', $v))->get();
        $rows = AttendanceDay::whereDate('work_date', $d)->whereIn('user_id', $profiles->pluck('user_id'))->get()->keyBy('user_id');
        $prevDate = Carbon::parse($d)->subDay()->toDateString();
        // Night shifts that started yesterday and are still going.
        $stillIn = AttendanceDay::whereDate('work_date', $prevDate)->where('is_in', true)->whereIn('user_id', $profiles->pluck('user_id'))->get()->keyBy('user_id');
        $pre = $this->preload($profiles->pluck('user_id')->all(), $prevDate, $d);

        return $profiles->map(function (StaffProfile $p) use ($d, $prevDate, $rows, $stillIn, $pre) {
            $view = $this->dayView($p, $d, $rows[$p->user_id] ?? null, $pre);
            if (isset($stillIn[$p->user_id]) && ! isset($rows[$p->user_id]) && in_array($view['status'], ['upcoming', 'off', 'leave', 'due'], true)) {
                $view = $this->dayView($p, $prevDate, $stillIn[$p->user_id], $pre) + ['from_yesterday' => true];
            }

            return ['profile' => $p] + $view;
        })
            ->sortBy(fn ($r) => [array_search($r['status'], ['due', 'late', 'missing_out', 'in', 'present', 'absent', 'upcoming', 'leave', 'off'], true), $r['profile']->user->name])
            ->values();
    }

    /** Totals per person over a date range. */
    public function timesheet($from, $to, ?int $departmentId = null, ?int $userId = null): Collection
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->startOfDay()->min(today());
        $profiles = StaffProfile::with(['user', 'department', 'shift'])->where('tracks_attendance', true)
            ->when($departmentId, fn ($q, $v) => $q->where('department_id', $v))
            ->when($userId, fn ($q, $v) => $q->where('user_id', $v))->get()
            ->sortBy(fn ($p) => $p->user->name)->values();
        $ids = $profiles->pluck('user_id')->all();
        $rows = AttendanceDay::whereIn('user_id', $ids)->whereBetween('work_date', [$f->toDateString(), $t->toDateString()])->get()
            ->keyBy(fn ($r) => $r->user_id . '|' . $r->work_date->toDateString());
        $pre = $this->preload($ids, $f, $t);

        return $profiles->map(function (StaffProfile $p) use ($f, $t, $rows, $pre) {
            $tot = ['scheduled' => 0, 'present' => 0, 'late' => 0, 'late_minutes' => 0, 'absent' => 0, 'leave' => 0, 'missing_out' => 0,
                    'worked' => 0, 'overtime' => 0, 'early' => 0, 'days' => []];
            for ($d = $f->copy(); $d->lte($t); $d->addDay()) {
                $ds = $d->toDateString();
                $v = $this->dayView($p, $ds, $rows[$p->user_id . '|' . $ds] ?? null, $pre);
                $tot['days'][$ds] = $v;
                if ($v['shift'] && ! $v['leave']) {
                    $tot['scheduled']++;
                }
                match ($v['status']) {
                    'present', 'in', 'late' => $tot['present']++,
                    'missing_out'           => [$tot['present']++, $tot['missing_out']++],
                    'absent'                => $tot['absent']++,
                    'leave'                 => $tot['leave']++,
                    default                 => null,
                };
                if ($v['late'] > 0) {
                    $tot['late']++;
                }
                $tot['late_minutes'] += $v['late'];
                $tot['worked'] += $v['worked'];
                $tot['overtime'] += $v['overtime'];
                $tot['early'] += $v['early'] ? 1 : 0;
            }
            $tot['punctuality'] = $tot['present'] ? round(100 * ($tot['present'] - $tot['late']) / $tot['present']) : null;

            return ['profile' => $p] + $tot;
        });
    }

    // ── Kiosks ────────────────────────────────────────────────────────

    /** What a kiosk keeps so it can recognise badges offline. Tokens are sent hashed, never as-is. */
    public function kioskData(): array
    {
        $today = today()->toDateString();
        $profiles = StaffProfile::with(['user', 'department'])->where('tracks_attendance', true)
            ->whereHas('user', fn ($q) => $q->where('is_disabled', false))->get();
        $in = AttendanceDay::where('is_in', true)->whereIn('user_id', $profiles->pluck('user_id'))->pluck('user_id')->flip();
        $last = AttendancePunch::counting()->where('punched_at', '>=', now()->subDay())->whereIn('user_id', $profiles->pluck('user_id'))
            ->selectRaw('user_id, max(punched_at) as at')->groupBy('user_id')->pluck('at', 'user_id');

        return [
            'generated_at' => now()->toIso8601String(),
            'hotel'        => config('hotel.name'),
            'points'       => AttendancePoint::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'location', 'take_photo'])->values(),
            'staff'        => $profiles->map(fn (StaffProfile $p) => [
                'h'     => hash('sha256', $p->badge_token),
                'id'    => $p->user_id,
                'name'  => $p->user->name,
                'no'    => $p->staff_no,
                'dept'  => $p->department?->name,
                'title' => $p->job_title,
                'in'    => isset($in[$p->user_id]),
                'last'  => isset($last[$p->user_id]) ? Carbon::parse($last[$p->user_id])->toIso8601String() : null,
            ])->values(),
            'repeat_seconds' => self::REPEAT_SECONDS,
            'today'          => $today,
        ];
    }

    /** Punches sent by a kiosk. Safe to receive twice. One result per punch. */
    public function sync(array $punches, User $by): array
    {
        return array_map(function ($row) use ($by) {
            $uuid = is_array($row) ? (string) ($row['uuid'] ?? '') : '';
            try {
                $v = Validator::make(is_array($row) ? $row : [], [
                    'uuid'        => 'required|uuid',
                    'token'       => 'required|string|max:120',
                    'punched_at'  => 'required|date',
                    'point_id'    => 'nullable|integer',
                    'device_id'   => 'nullable|string|max:20',
                    'was_offline' => 'nullable|boolean',
                    'photo'       => 'nullable|string|max:600000',
                ]);
                if ($v->fails()) {
                    return ['uuid' => $uuid, 'result' => 'rejected', 'message' => implode(' ', $v->errors()->all())];
                }
                $data = $v->validated();
                $token = Str::startsWith($data['token'], StaffProfile::QR_PREFIX) ? Str::after($data['token'], StaffProfile::QR_PREFIX) : $data['token'];
                $profile = StaffProfile::with('user')->where('badge_token', $token)->first();
                if (! $profile) {
                    return ['uuid' => $uuid, 'result' => 'rejected', 'message' => 'Unknown or replaced badge.'];
                }
                [$punch, $result] = $this->punch($profile, $data + ['source' => 'kiosk'], $by);
                $day = AttendanceDay::where('user_id', $profile->user_id)->whereDate('work_date', $punch->work_date)->first();

                return [
                    'uuid'      => $uuid,
                    'result'    => $result,
                    'name'      => $profile->user->name,
                    'direction' => $punch->direction,
                    'late'      => (int) ($day?->late_minutes ?? 0),
                    'status'    => $day?->status,
                ];
            } catch (\Throwable $e) {
                Log::error('Attendance sync failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);

                return ['uuid' => $uuid, 'result' => 'rejected', 'message' => 'Server error: ' . $e->getMessage()];
            }
        }, array_values($punches));
    }

    // ── Staff set-up ──────────────────────────────────────────────────

    public function ensureProfile(User $user): StaffProfile
    {
        if ($existing = StaffProfile::where('user_id', $user->id)->first()) {
            return $existing;
        }
        $profile = StaffProfile::create([
            'user_id'         => $user->id,
            'staff_no'        => StaffProfile::nextStaffNo(),
            'shift_id'        => Shift::where('is_active', true)->orderBy('start_time')->value('id'),
            'work_days'       => [1, 2, 3, 4, 5, 6],
            'badge_token'     => StaffProfile::newBadgeToken(),
            'badge_issued_at' => now(),
        ]);
        $user->setRelation('staffProfile', $profile);

        return $profile;
    }

    /** A lost badge: issue a new code; the old card stops working everywhere. */
    public function reissueBadge(StaffProfile $p): void
    {
        $p->forceFill(['badge_token' => StaffProfile::newBadgeToken(), 'badge_issued_at' => now()])->save();
    }
}
