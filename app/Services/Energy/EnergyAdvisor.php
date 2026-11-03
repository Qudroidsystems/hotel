<?php

namespace App\Services\Energy;

use App\Enums\ReservationStatus as S;
use App\Enums\RoomStatus;
use App\Models\Booking\GuestArrival;
use App\Models\Energy\EnergyAction;
use App\Models\Energy\EnergyEvent;
use App\Models\Energy\EnergyFuelDelivery;
use App\Models\Energy\EnergySample;
use App\Models\Energy\EnergySource;
use App\Models\Energy\EnergyZone;
use App\Models\Events\Event;
use App\Models\Facility\FacilityDevice;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Facility\FacilityService;
use App\Services\Mobile\StaffAlerts;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Energy Saver. Works only from what the hotel system already knows — who is in which room and until when, who
 * arrives when (and who is on the way right now), rooms waiting for cleaning or being repaired, hall bookings,
 * outlets' opening hours, room sensors when the hotel has them — to say, at any moment:
 *
 *   - which rooms and places can be switched off (or down), and until when;
 *   - which rooms to switch on before a guest arrives (so the room is cool, not cooled all day);
 *   - how much power the hotel needs over the next 24 hours, and the smallest generator that carries it.
 *
 * Every suggestion is kept as an EnergyAction the control room ticks off; what was switched off is counted as
 * saved until the room or place is needed again.
 *
 * Where the equipment board (IT Control Room → Equipment) has a room's AC, lights, TV … — switched by staff or
 * reported by a hub — only rooms with equipment really left on are flagged, naming it, and "Done" switches it off
 * on the board. A generator or the grid linked to a board device is read from there too.
 */
class EnergyAdvisor
{
    /** Needs, from most to least power. */
    public const NEEDS = ['on', 'precool', 'eco', 'low', 'off'];

    /** What a piece of equipment draws when on (W), roughly. */
    public const DEVICE_WATTS = ['ac' => 1200, 'water_heater' => 1500, 'lights' => 150, 'tv' => 100, 'fan' => 60, 'sound' => 200, 'cooker' => 2500, 'socket' => 50, 'other' => 100];

    /** What to switch for each need: off (empty), low (waiting for cleaning: lights allowed), eco (guest out). */
    private const SWITCH = [
        'off' => ['ac', 'water_heater', 'lights', 'tv', 'fan', 'sound'],
        'low' => ['ac', 'water_heater', 'tv', 'fan', 'sound'],
        'eco' => ['lights', 'tv', 'water_heater', 'fan', 'sound'],
    ];

    private ?array $ctx = null;

    public function __construct(private StaffAlerts $alerts)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('hotel.energy.enabled', true);
    }

    public function settings(): array
    {
        $c = config('hotel.energy', []);

        return [
            'room_watts'      => max(100, (int) ($c['room_watts'] ?? 1800)),
            'standby_watts'   => max(0, (int) ($c['standby_watts'] ?? 120)),
            'precool_minutes' => max(0, (int) ($c['precool_minutes'] ?? 40)),
            'hall_setup_hours' => max(0, (int) ($c['hall_setup_hours'] ?? 2)),
            'headroom'        => min(100, max(30, (int) ($c['headroom'] ?? 80))),
            'grid_price'      => (float) ($c['grid_price'] ?? 225),
            'diesel_price'    => (float) ($c['diesel_price'] ?? 1250),
            'alerts'          => (bool) ($c['alerts'] ?? true),
            'empty_minutes'   => max(5, (int) ($c['empty_minutes'] ?? 30)),
        ];
    }

    /** Watts a room draws for each need. */
    public function roomWatts(string $need): int
    {
        $s = $this->settings();

        return match ($need) {
            'on', 'precool' => $s['room_watts'],
            'eco'           => (int) round($s['room_watts'] * 0.35),    // AC on a warm setting, lights off
            'low'           => $s['standby_watts'] + 150,              // lights only while someone works in it
            default         => $s['standby_watts'],
        };
    }

    // ── What everything needs, now and over the next day ─────────────────

    /**
     * The whole picture: every room and place with its need, the floors, the load now and for the next 24 hours,
     * the power sources and which to run, and the suggestions.
     */
    public function snapshot(?Carbon $now = null): array
    {
        $now ??= now();
        $this->readSwitches();
        $this->ctx = $this->context($now);
        $ctx = $this->ctx;

        $rooms = $ctx['rooms']->map(function (Room $room) use ($now, $ctx) {
            $need = $this->roomNeed($room, $now, true);
            $devices = $ctx['roomDevices'][$room->id] ?? collect();

            // From the equipment board: what is actually on in the room, and whether that is waste.
            return ['room' => $room] + $need + [
                'devices_known' => $devices->isNotEmpty(),
                'devices_on' => $devices->filter(fn (FacilityDevice $d) => $d->live_state === 'on' && in_array($d->kind, self::SWITCH['off'], true))->values(),
            ];
        });
        $zones = $ctx['zones']->map(fn (EnergyZone $z) => ['zone' => $z] + $this->zoneNeed($z, $now, $rooms));

        $forecast = [];
        for ($h = 0; $h <= 24; $h++) {
            $t = $h === 0 ? $now->copy() : $now->copy()->startOfHour()->addHours($h);
            $forecast[] = ['at' => $t, 'label' => $t->format('g A'), 'kw' => $h === 0 ? $this->kw($rooms, $zones) : $this->loadAt($t)];
        }

        $floors = $rooms->groupBy(fn ($r) => $r['room']->floor_id ?: 0)->map(function (Collection $list, $floorId) {
            $floor = $list->first()['room']->floor;
            $needed = $list->filter(fn ($r) => in_array($r['need'], ['on', 'precool', 'eco'], true))->count();

            return [
                'id' => (int) $floorId, 'name' => $floor ? ($floor->building ? $floor->building->name . ' · ' : '') . $floor->name : 'Rooms',
                'level' => $floor?->level ?? 0, 'rooms' => $list->values(), 'in_use' => $needed, 'empty' => $needed === 0,
                'kw' => round($list->sum(fn ($r) => $this->roomWatts($r['need'])) / 1000, 1),
            ];
        })->sortBy('level')->values();

        $power = $this->power($forecast);
        $s = $this->settings();

        return [
            'at' => $now, 'settings' => $s, 'rooms' => $rooms->values(), 'zones' => $zones->values(), 'floors' => $floors,
            'forecast' => $forecast, 'power' => $power,
            'now_kw' => $forecast[0]['kw'],
            'all_on_kw' => round(($ctx['rooms']->count() * $s['room_watts'] + $ctx['zones']->sum('watts')) / 1000, 1),
            'counts' => [
                'rooms' => $rooms->count(),
                'in_use' => $rooms->whereIn('need', ['on', 'eco'])->count(),
                'precool' => $rooms->where('need', 'precool')->count(),
                'off' => $rooms->whereIn('need', ['off', 'low'])->count(),
                'zones_off' => $zones->where('need', 'low')->count(),
            ],
            'suggestions' => $this->suggestions($rooms, $zones, $power, $now),
        ];
    }

    /** Rooms, places and bookings around a moment, loaded once. */
    private function context(Carbon $now): array
    {
        $stays = Reservation::whereIn('status', [S::Pending->value, S::Confirmed->value, S::CheckedIn->value])
            ->where('check_out_date', '>=', $now->copy()->subDay()->toDateString())
            ->where('check_in_date', '<=', $now->copy()->addDays(2)->toDateString())
            ->with(['guest:id,first_name,last_name,is_vip', 'rooms:id,reservation_id,room_id'])
            ->get();
        $byRoom = [];
        foreach ($stays as $r) {
            foreach ($r->rooms as $rr) {
                if ($rr->room_id) {
                    $byRoom[$rr->room_id][] = $r;
                }
            }
        }
        $sharing = GuestArrival::whereIn('reservation_id', $stays->pluck('id'))->where('status', 'sharing')
            ->where('last_seen_at', '>', $now->copy()->subMinutes(30))->get()->keyBy('reservation_id');

        return [
            'rooms' => Room::where('is_active', true)->with(['floor.building', 'roomType:id,name'])->orderBy('number')->get(),
            'zones' => EnergyZone::where('is_active', true)->with(['hall:id,name', 'outlet:id,name,guest_hours,is_active', 'floor:id,name'])->orderBy('sort_order')->orderBy('name')->get(),
            'stays' => $byRoom,
            'unassigned' => $stays->filter(fn ($r) => $r->status !== S::CheckedIn && $r->check_in_date->isSameDay($now) && $r->rooms->whereNull('room_id')->isNotEmpty())->count(),
            'sharing' => $sharing,
            'cleaning' => HousekeepingTask::open()->whereNotNull('room_id')->get()->groupBy('room_id'),
            'repairs' => MaintenanceOrder::active()->whereNotNull('room_id')->get()->groupBy('room_id'),
            'roomDevices' => $this->devices()->whereNotNull('room_id')->groupBy('room_id'),
            'areaDevices' => $this->devices()->whereNull('room_id')->groupBy(fn ($d) => mb_strtolower(trim((string) $d->area))),
            'events' => Event::holding()->overlapping($now->copy()->subDay(), $now->copy()->addDays(3))->orderBy('starts_at')->get(['id', 'title', 'hall_id', 'starts_at', 'ends_at', 'status'])->groupBy('hall_id'),
        ];
    }

    /**
     * What a room needs at a moment: on (guest in), precool (guest arriving soon), eco (guest out, by sensor),
     * low (waiting for cleaning) or off — with the reason, and when that changes.
     *
     * @return array{need: string, reason: string, until: ?Carbon, guest: ?string, next: ?Carbon}
     */
    public function roomNeed(Room $room, Carbon $t, bool $live = false): array
    {
        $ctx = $this->ctx ??= $this->context($t);
        $s = $this->settings();
        $out = fn (string $need, string $reason, ?Carbon $until = null, ?string $guest = null, ?Carbon $next = null) => compact('need', 'reason', 'until', 'guest', 'next');

        if ($room->status === RoomStatus::OutOfService) {
            return $out('off', 'Out of service');
        }

        $stays = collect($ctx['stays'][$room->id] ?? []);
        $checkout = fn (Reservation $r) => $r->check_out_date->copy()->setTimeFromTimeString((string) config('hotel.booking.check_out_time', '12:00'));
        $name = fn (Reservation $r) => trim(($r->guest?->first_name ?? '') . ' ' . ($r->guest?->last_name ?? '')) ?: 'Guest';

        // In house: on until they leave (still here after check-out time: on a while longer).
        foreach ($stays->where('status', S::CheckedIn) as $r) {
            $leave = $checkout($r);
            if ($r->check_out_date->isSameDay($t) && $live && $t->gte($leave)) {
                $leave = $t->copy()->addHour();
            }
            if ($t->lt($leave)) {
                if ($live && $room->presence === 'empty' && $room->presence_at && $room->presence_at->lte($t->copy()->subMinutes($s['empty_minutes']))) {
                    return $out('eco', 'Guest is out (room sensor, ' . $room->presence_at->diffForHumans(short: true) . '): lights off, AC on eco', null, $name($r));
                }

                return $out('on', 'Guest in: ' . $name($r) . ', leaves ' . $leave->format($leave->isSameDay($t) ? 'g:i A' : 'D j M'), $leave, $name($r));
            }
        }

        // Arriving: on a little before they are expected.
        $next = null;
        foreach ($stays->where('status', '!=', S::CheckedIn)->sortBy('check_in_date') as $r) {
            if ($checkout($r)->lte($t)) {
                continue;
            }
            $arrive = $this->arrivalAt($r, $t);
            $from = $arrive->copy()->subMinutes($s['precool_minutes']);
            if ($t->gte($from)) {
                $coming = isset($ctx['sharing'][$r->id]) ? 'on the way, here in about ' . max(1, (int) $ctx['sharing'][$r->id]->eta_minutes) . ' min' : 'expected ' . $arrive->format($arrive->isSameDay($t) ? 'g:i A' : 'D g:i A');

                return $out('precool', "Arriving: {$name($r)}, {$coming}", null, $name($r));
            }
            $next = $next ? $next->min($from) : $from;
        }

        // Right now only: cleaning and repairs need power while someone works.
        if ($live) {
            $task = ($ctx['cleaning'][$room->id] ?? collect())->first();
            if ($task?->status === HousekeepingTask::IN_PROGRESS) {
                return $out('on', 'Being cleaned', null, null, $next);
            }
            $repair = ($ctx['repairs'][$room->id] ?? collect())->first();
            if ($repair?->status === MaintenanceOrder::IN_PROGRESS) {
                return $out('on', 'Repair in progress: ' . $repair->title, null, null, $next);
            }
            if ($task && $task->status === HousekeepingTask::PENDING) {
                return $out('low', 'Waiting for cleaning: lights only while the room attendant is in, AC off', $next, null, $next);
            }
        }

        $why = match (true) {
            $room->status === RoomStatus::Maintenance => 'Under repair, no guest',
            $next !== null => 'Empty. Next guest: switch on at ' . $next->format($next->isSameDay($t) ? 'g:i A' : 'D g:i A'),
            default => 'Empty: no guest booked in the next two days',
        };

        return $out('off', $why, $next, null, $next);
    }

    /** Switchable equipment on the board (never critical or must-stay-on things). */
    private function devices(): Collection
    {
        static $has = null;
        $has ??= Schema::hasTable('facility_devices');

        return $has ? FacilityDevice::active()->where('is_critical', false)->where('should_be_on', false)->with('room:id,number')->get() : collect();
    }

    /** Board devices of a place, matched by name ("Kitchen" ↔ "Kitchen extraction & lights"). */
    private function zoneDevices(EnergyZone $z): Collection
    {
        $name = mb_strtolower($z->name);
        $match = collect($this->ctx['areaDevices'] ?? [])->filter(fn ($list, $area) => $area !== '' && (str_contains($name, $area) || str_contains($area, $name)));

        return $match->flatten(1)->values();
    }

    /** Generators and the grid linked to a board device take their on/off from it (a hub reports it). */
    private function readSwitches(): void
    {
        foreach (EnergySource::whereNotNull('device_id')->with('device')->get() as $g) {
            $state = $g->device?->live_state;
            if (! in_array($state, ['on', 'off'], true) || $g->running === ($state === 'on')) {
                continue;
            }
            if ($g->kind === 'grid') {
                $g->forceFill(['running' => $state === 'on', 'running_since' => now()])->save();
                EnergyEvent::create(['source_id' => $g->id, 'kind' => $state, 'by' => 'device', 'note' => $state === 'on' ? 'Grid power back (equipment board)' : 'Grid power off (equipment board)']);
            } else {
                $this->setRunning($g, $state === 'on', null, 'device');
            }
        }
    }

    private function names(Collection $devices): string
    {
        // "air conditioner", "lights", "TV" (short all-capital names keep their capitals).
        $names = $devices->map(fn (FacilityDevice $d) => FacilityDevice::KINDS[$d->kind][0] ?? $d->name)
            ->map(fn (string $n) => $n === mb_strtoupper($n) ? $n : mb_strtolower($n))->unique()->values();

        return $names->count() > 1 ? $names->slice(0, -1)->join(', ') . ' and ' . $names->last() : (string) $names->first();
    }

    /** When a guest is expected: on the way now (arrival sharing), their arrival time, or check-in time. */
    private function arrivalAt(Reservation $r, Carbon $t): Carbon
    {
        $sharing = $this->ctx['sharing'][$r->id] ?? null;
        if ($sharing && $sharing->eta_minutes !== null) {
            return now()->addMinutes((int) $sharing->eta_minutes);
        }
        $time = preg_match('/(\d{1,2}):(\d{2})/', (string) $r->expected_arrival_time, $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : (string) config('hotel.booking.check_in_time', '14:00');
        $at = $r->check_in_date->copy()->setTimeFromTimeString($time);

        // Due already but not here yet: expect them within the hour.
        return $at->lt($t) ? $t->copy()->addMinutes(30) : $at;
    }

    /**
     * What a place needs at a moment: on, or low (its low_watts: security lights, fridges …).
     * @return array{need: string, reason: string, until: ?Carbon, next: ?Carbon}
     */
    public function zoneNeed(EnergyZone $z, Carbon $t, ?Collection $rooms = null): array
    {
        $ctx = $this->ctx ??= $this->context($t);
        $s = $this->settings();
        $out = fn (string $need, string $reason, ?Carbon $until = null, ?Carbon $next = null) => compact('need', 'reason', 'until', 'next');

        if ($z->always_on) {
            return $out('on', 'Always on');
        }
        if ($z->hall_id) {
            foreach ($ctx['events'][$z->hall_id] ?? [] as $e) {
                $from = $e->starts_at->copy()->subHours($s['hall_setup_hours']);
                $to = $e->ends_at->copy()->addHour();
                if ($t->gte($from) && $t->lt($to)) {
                    return $out('on', ($t->lt($e->starts_at) ? 'Setting up for ' : 'Event: ') . $e->title . ' (' . $e->starts_at->format('g:i A') . ' – ' . $e->ends_at->format('g:i A') . ')', $to);
                }
                if ($from->gt($t)) {
                    return $out('low', 'No event now. Next: ' . $e->title . ', switch on at ' . $from->format($from->isSameDay($t) ? 'g:i A' : 'D g:i A'), $from, $from);
                }
            }

            return $out('low', 'No event booked in the next three days');
        }
        if ($z->floor_id && $z->kind === 'corridor') {
            $roomsOnFloor = $rooms
                ? $rooms->filter(fn ($r) => $r['room']->floor_id === $z->floor_id)
                : $ctx['rooms']->where('floor_id', $z->floor_id)->map(fn (Room $room) => ['room' => $room] + $this->roomNeed($room, $t));
            $busy = $roomsOnFloor->filter(fn ($r) => in_array($r['need'], ['on', 'precool', 'eco', 'low'], true))->count();
            if ($busy === 0) {
                $next = $roomsOnFloor->pluck('next')->filter()->min();

                return $out('low', 'Every room on this floor is empty' . ($next ? '. First guest: switch on at ' . $next->format($next->isSameDay($t) ? 'g:i A' : 'D g:i A') : ''), $next, $next);
            }
            if (! $z->opens) {
                return $out('on', "{$busy} room(s) in use on this floor");
            }
        }
        $hours = $z->opens && $z->closes ? [$z->opens, $z->closes]
            : ($z->outlet && preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', (string) $z->outlet->guest_hours, $m) ? [$m[1], $m[2]] : null);
        if ($z->outlet && ! $z->outlet->is_active) {
            return $out('low', $z->outlet->name . ' is closed');
        }
        if ($hours) {
            [$a, $b] = $hours;
            $now = $t->format('H:i');
            $open = $a <= $b ? ($now >= $a && $now < $b) : ($now >= $a || $now < $b);
            $at = fn (string $hm) => ($c = $t->copy()->setTimeFromTimeString($hm))->lte($t) ? $c->addDay() : $c;
            $fmt = fn (string $hm) => date('g:i A', strtotime($hm));

            return $open ? $out('on', 'Open until ' . $fmt($b), $at($b)) : $out('low', 'Closed now. Opens ' . $fmt($a), $at($a), $at($a));
        }

        return $out('on', 'In use');
    }

    /** Hotel load (kW) at a later moment. */
    private function loadAt(Carbon $t): float
    {
        $rooms = $this->ctx['rooms']->map(fn (Room $room) => ['room' => $room] + $this->roomNeed($room, $t));
        $zones = $this->ctx['zones']->map(fn (EnergyZone $z) => ['zone' => $z] + $this->zoneNeed($z, $t, $rooms));

        return $this->kw($rooms, $zones);
    }

    private function kw(Collection $rooms, Collection $zones): float
    {
        $w = $rooms->sum(fn ($r) => $this->roomWatts($r['need'])) + $zones->sum(fn ($z) => $z['need'] === 'on' ? $z['zone']->watts : $z['zone']->low_watts);

        return round($w / 1000, 1);
    }

    // ── Power: the grid, generators, and which to run ─────────────────────

    public function power(array $forecast): array
    {
        $s = $this->settings();
        $sources = EnergySource::where('is_active', true)->orderBy('capacity_kw')->get();
        $grid = $sources->firstWhere('kind', 'grid');
        $sets = $sources->where('kind', '!=', 'grid')->where('capacity_kw', '>', 0)->values();
        $running = $sets->where('running', true)->values();
        $gridOn = $grid ? $grid->running : $sets->isEmpty();
        $usable = fn (EnergySource $g) => $g->capacity_kw * $s['headroom'] / 100;
        $pick = fn (float $kw) => $sets->first(fn ($g) => $usable($g) >= $kw);
        $now = $forecast[0]['kw'];
        $peak3 = max(array_map(fn ($f) => $f['kw'], array_slice($forecast, 0, 4)));
        $peak24 = max(array_map(fn ($f) => $f['kw'], $forecast));

        // If the grid goes (or is) off: the smallest set for each hour, grouped into stretches.
        $plan = [];
        foreach ($forecast as $i => $f) {
            $g = $pick($f['kw']) ?? $sets->last();
            $litres = $g ? $g->litresAt($f['kw']) : 0;
            $last = end($plan);
            if ($last && $last['source_id'] === $g?->id) {
                $plan[key($plan)]['to'] = $f['at'];
                $plan[key($plan)]['peak_kw'] = max($last['peak_kw'], $f['kw']);
                $plan[key($plan)]['hours']++;
                $plan[key($plan)]['litres'] += $i < 24 ? $litres : 0;
            } else {
                $plan[] = ['from' => $f['at'], 'to' => $f['at'], 'source_id' => $g?->id, 'source' => $g?->name, 'capacity_kw' => $g?->capacity_kw,
                    'peak_kw' => $f['kw'], 'hours' => 1, 'litres' => $i < 24 ? $litres : 0];
            }
        }
        $planLitres = round(array_sum(array_column($plan, 'litres')), 1);
        $biggest = $sets->where('kind', 'generator')->last();
        $bigLitres = $biggest ? round(array_sum(array_map(fn ($f) => $biggest->litresAt($f['kw']), array_slice($forecast, 0, 24))), 1) : 0;

        $choice = $pick($peak3);
        $state = $gridOn ? 'grid' : ($running->isEmpty() ? 'dark' : 'generator');
        $carrying = $running->sum('capacity_kw');
        $overloaded = ! $gridOn && $running->isNotEmpty() && $now > $carrying * $s['headroom'] / 100;
        $oversized = ! $gridOn && $running->count() === 1 && $choice && $running->first()->id !== $choice->id && $running->first()->capacity_kw > $choice->capacity_kw;

        $line = match (true) {
            $gridOn && $running->isNotEmpty() => 'Grid power is on, but ' . $running->pluck('name')->join(', ', ' and ') . ' still running: switch off.',
            $gridOn => 'Grid power is on. Generators can stay off.',
            $state === 'dark' && $choice => "Grid is off and no generator is recorded as running. Start {$choice->name} ({$choice->capacity_kw} kW): enough for the next three hours ({$peak3} kW at most).",
            $state === 'dark' => 'Grid is off and no generator is recorded as running. Add your generators on the Energy Saver page to get a suggestion.',
            $overloaded => "Load {$now} kW is more than " . $running->pluck('name')->join(' + ') . " should carry ({$s['headroom']}% of {$carrying} kW). Start a bigger set or switch off the places below.",
            $oversized => "{$running->first()->name} ({$running->first()->capacity_kw} kW) is bigger than needed. {$choice->name} ({$choice->capacity_kw} kW) carries the next three hours ({$peak3} kW at most) and saves about "
                . round($running->first()->litresAt($now) - $choice->litresAt($now), 1) . ' L of diesel an hour.',
            default => $running->pluck('name')->join(' + ') . " is the right size for now ({$now} kW of {$carrying} kW).",
        };

        return [
            'state' => $state, 'grid' => $grid, 'grid_on' => $gridOn, 'sources' => $sources, 'running' => $running,
            'line' => $line, 'urgent' => $state === 'dark' || $overloaded,
            'choice' => $choice, 'overloaded' => $overloaded, 'oversized' => $oversized,
            'now_kw' => $now, 'peak3_kw' => $peak3, 'peak24_kw' => $peak24,
            'litres_per_hour' => $gridOn ? 0 : round($running->sum(fn ($g) => $g->litresAt($now * $g->capacity_kw / max(1, $carrying))), 1),
            'plan' => $plan, 'plan_litres' => $planLitres, 'biggest' => $biggest, 'biggest_litres' => $bigLitres,
            'plan_saves_litres' => max(0, round($bigLitres - $planLitres, 1)),
        ];
    }

    // ── Suggestions and the control room's to-do list ──────────────────────

    /** @return list<array{key: string, target: string, target_id: ?int, action: string, title: string, reason: string, watts: int, until: ?Carbon, urgent: bool}> */
    private function suggestions(Collection $rooms, Collection $zones, array $power, Carbon $now): array
    {
        $s = $this->settings();
        $out = [];
        foreach ($rooms as $r) {
            $room = $r['room'];
            $need = $r['need'];
            $devices = $this->ctx['roomDevices'][$room->id] ?? collect();
            $base = ['target' => 'room', 'target_id' => $room->id, 'reason' => $r['reason'], 'until' => $r['until'], 'urgent' => false];

            if (in_array($need, ['off', 'low', 'eco'], true)) {
                if ($devices->isNotEmpty() && $devices->doesntContain(fn ($d) => $d->live_state === 'offline')) {
                    // The board knows: only what is really on, by name.
                    $on = $devices->filter(fn ($d) => $d->live_state === 'on' && in_array($d->kind, self::SWITCH[$need], true));
                    if ($on->isEmpty()) {
                        continue;   // already off
                    }
                    $watts = (int) $on->sum(fn ($d) => self::DEVICE_WATTS[$d->kind] ?? 100);
                    $verb = $need === 'eco' ? 'switch off the ' . $this->names($on) . ' (AC to eco)' : 'switch off the ' . $this->names($on);
                    $out[] = $base + ['key' => "room:{$room->id}:{$need}", 'action' => $need, 'title' => "Room {$room->number}: {$verb}", 'watts' => $watts,
                        'devices' => $on->pluck('id')->values()->all(), 'reason' => $r['reason'] . ' · on since ' . ($on->min('state_changed_at')?->diffForHumans(short: true) ?? 'a while')];
                    continue;
                }
                $what = match ($need) {
                    'off' => ["Room {$room->number}: switch off", $s['room_watts'] - $s['standby_watts']],
                    'low' => ["Room {$room->number}: AC off, lights only while cleaning", $s['room_watts'] - $this->roomWatts('low')],
                    default => ["Room {$room->number}: lights off, AC on eco", $s['room_watts'] - $this->roomWatts('eco')],
                };
                $out[] = $base + ['key' => "room:{$room->id}:{$need}", 'action' => $need, 'title' => $what[0], 'watts' => $what[1], 'devices' => null];
            } elseif ($need === 'precool') {
                $ac = $devices->where('kind', 'ac');
                if ($ac->isNotEmpty() && $ac->every(fn ($d) => $d->live_state === 'on')) {
                    continue;   // already cooling
                }
                $out[] = $base + ['key' => "room:{$room->id}:precool", 'action' => 'precool', 'title' => "Room {$room->number}: switch on" . ($ac->isNotEmpty() ? ' the air conditioner' : '') . ' now',
                    'watts' => $s['room_watts'], 'devices' => $ac->isNotEmpty() ? $ac->pluck('id')->values()->all() : null];
            }
        }
        $switchedOff = EnergyAction::where('target', 'zone')->where('action', 'low')->where('status', EnergyAction::DONE)->whereNull('closed_at')->pluck('target_id')->all();
        foreach ($zones as $z) {
            $zone = $z['zone'];
            if ($z['need'] === 'low' && $zone->watts > $zone->low_watts) {
                $board = $this->zoneDevices($zone);
                $on = $board->filter(fn ($d) => $d->live_state === 'on');
                if ($board->isNotEmpty() && $board->doesntContain(fn ($d) => $d->live_state === 'offline') && $on->isEmpty()) {
                    continue;   // the board says it is all off already
                }
                $out[] = ['key' => "zone:{$zone->id}:low", 'target' => 'zone', 'target_id' => $zone->id, 'action' => 'low',
                    'title' => $zone->name . ($on->isNotEmpty() ? ': switch off the ' . $this->names($on) : ($zone->low_watts > 0 ? ': switch down to essentials' : ': switch off')),
                    'reason' => $z['reason'], 'watts' => $zone->watts - $zone->low_watts, 'until' => $z['until'], 'urgent' => false,
                    'devices' => $on->isNotEmpty() ? $on->pluck('id')->values()->all() : null];
            } elseif ($z['need'] === 'on' && in_array($zone->id, $switchedOff, true)) {
                $out[] = ['key' => "zone:{$zone->id}:on", 'target' => 'zone', 'target_id' => $zone->id, 'action' => 'on',
                    'title' => "{$zone->name}: switch back on", 'reason' => $z['reason'], 'watts' => $zone->watts, 'until' => null, 'urgent' => false];
            }
        }
        // The power source.
        $choice = $power['choice'];
        if ($power['grid_on']) {
            foreach ($power['running'] as $g) {
                $out[] = ['key' => "source:{$g->id}:stop", 'target' => 'source', 'target_id' => $g->id, 'action' => 'stop', 'title' => "{$g->name}: switch off, grid power is on",
                    'reason' => 'Burning about ' . $g->litresAt($power['now_kw']) . ' L of diesel an hour for nothing', 'watts' => 0, 'until' => null, 'urgent' => false];
            }
        } elseif ($choice && ($power['state'] === 'dark' || $power['oversized'] || $power['overloaded'])) {
            $out[] = ['key' => "source:{$choice->id}:start", 'target' => 'source', 'target_id' => $choice->id, 'action' => 'start',
                'title' => "Run {$choice->name} ({$choice->capacity_kw} kW)" . ($power['oversized'] ? ' instead of ' . $power['running']->first()->name : ''),
                'reason' => $power['line'], 'watts' => 0, 'until' => null, 'urgent' => $power['urgent']];
        }

        return $out;
    }

    /**
     * Bring the to-do list up to date: new suggestions are added, ones no longer right expire, rooms switched off
     * that are needed again are closed with what they saved. Tells the control room about new ones.
     * @return array{new: int, open: int, closed: int}
     */
    public function sync(?Carbon $now = null, bool $alert = true): array
    {
        $now ??= now();
        if (! $this->enabled()) {
            return ['new' => 0, 'open' => 0, 'closed' => 0];
        }
        $snap = $this->snapshot($now);
        $wanted = collect($snap['suggestions'])->keyBy('key');
        $live = EnergyAction::whereIn('status', [EnergyAction::OPEN, EnergyAction::DONE])->whereNull('closed_at')->get();
        $closed = 0;

        DB::transaction(function () use ($wanted, $live, $now, &$closed) {
            foreach ($live as $a) {
                if ($wanted->has($a->key)) {
                    $w = $wanted[$a->key];
                    $a->fill(['reason' => mb_substr($w['reason'], 0, 255), 'until' => $w['until'], 'watts' => $w['watts']]
                        + ($a->status === EnergyAction::OPEN ? ['title' => mb_substr($w['title'], 0, 160), 'devices' => $w['devices'] ?? null] : []))->save();
                    continue;
                }
                if ($a->status === EnergyAction::OPEN) {
                    $a->forceFill(['status' => EnergyAction::EXPIRED, 'closed_at' => $now])->save();
                } else {
                    // Switched off and now needed again: count what was saved.
                    $a->forceFill(['closed_at' => $now, 'kwh_saved' => $a->saves() ? $this->kwhSaved($a, $now) : null])->save();
                }
                $closed++;
            }
            $have = $live->pluck('key')->all();
            foreach ($wanted as $key => $w) {
                if (! in_array($key, $have, true)) {
                    EnergyAction::create(['key' => $key, 'target' => $w['target'], 'target_id' => $w['target_id'], 'action' => $w['action'],
                        'title' => mb_substr($w['title'], 0, 160), 'reason' => mb_substr($w['reason'], 0, 255), 'watts' => $w['watts'], 'until' => $w['until'], 'devices' => $w['devices'] ?? null]);
                }
            }
        });

        $this->sample($snap, $now);
        $fresh = EnergyAction::where('status', EnergyAction::OPEN)->whereNull('alerted_at')->get();
        if ($alert && $fresh->isNotEmpty()) {
            $this->alert($fresh, $snap);
        }

        return ['new' => $fresh->count(), 'open' => EnergyAction::where('status', EnergyAction::OPEN)->count(), 'closed' => $closed];
    }

    /** Note the hotel's power now, at most every 10 minutes (the reports and the diesel check are made from these). */
    private function sample(array $snap, Carbon $now): void
    {
        if (! Schema::hasTable('energy_samples') || EnergySample::where('at', '>', $now->copy()->subMinutes(10))->exists()) {
            return;
        }
        $p = $snap['power'];
        EnergySample::create([
            'at' => $now, 'kw' => $snap['now_kw'], 'grid_on' => $p['grid_on'],
            'source_id' => $p['grid_on'] ? null : $p['running']->sortByDesc('capacity_kw')->first()?->id,
            'litres_per_hour' => $p['litres_per_hour'], 'rooms_in_use' => $snap['counts']['in_use'],
            'waste_kw' => round(collect($snap['suggestions'])->filter(fn ($x) => in_array($x['action'], ['off', 'low', 'eco'], true))->sum('watts') / 1000, 1),
        ]);
    }

    /**
     * Diesel a generator should have burned between two moments, from the samples: each sample's burn rate for
     * the time until the next one (at most 30 minutes, so a gap in sampling doesn't count as running).
     */
    public function expectedLitres(EnergySource $g, Carbon $from, Carbon $to): float
    {
        $samples = EnergySample::where('source_id', $g->id)->whereBetween('at', [$from, $to])->orderBy('at')->get(['at', 'litres_per_hour'])->values();
        $all = EnergySample::whereBetween('at', [$from, $to->copy()->addMinutes(30)])->orderBy('at')->pluck('at')->values();
        $litres = 0.0;
        foreach ($samples as $smp) {
            $next = $all->first(fn ($t) => $t->gt($smp->at));
            $hours = min(30, $next ? $smp->at->diffInMinutes($next) : 15) / 60;
            $litres += $smp->litres_per_hour * $hours;
        }

        return round($litres, 1);
    }

    private function kwhSaved(EnergyAction $a, Carbon $until): float
    {
        $hours = min(24 * 7, max(0, $a->done_at?->diffInMinutes($until) ?? 0) / 60);

        return round($a->watts * $hours / 1000, 2);
    }

    /** One notification for the control room: what's new, most important first. */
    private function alert(Collection $fresh, array $snap): void
    {
        $fresh->each->forceFill(['alerted_at' => now()])->each->save();
        if (! $this->settings()['alerts']) {
            return;
        }
        $source = $fresh->firstWhere('target', 'source');
        $rooms = $fresh->where('target', 'room');
        $off = $rooms->whereIn('action', ['off', 'low', 'eco']);
        $on = $rooms->where('action', 'precool');
        $zones = $fresh->where('target', 'zone');
        $num = fn (Collection $c) => $c->map(fn ($a) => preg_replace('/^Room (\S+):.*/', '$1', $a->title))->take(8)->join(', ') . ($c->count() > 8 ? ' …' : '');

        $lines = array_filter([
            $source?->title,
            $on->isNotEmpty() ? 'Switch on for arriving guests: ' . $num($on) : null,
            $off->isNotEmpty() ? $off->count() . ' room(s) can be switched off: ' . $num($off) : null,
            $zones->isNotEmpty() ? 'Places: ' . $zones->pluck('title')->take(4)->join('; ') : null,
        ]);
        $kw = round($fresh->filter->saves()->sum('watts') / 1000, 1);
        $title = $source ? ($source->title) : ($off->isNotEmpty() || $zones->where('action', 'low')->isNotEmpty() ? "Energy: save about {$kw} kW now" : 'Energy: rooms to switch on');

        $this->alerts->notify('View energy saver', [
            'title' => mb_substr($title, 0, 120), 'body' => mb_substr(implode("\n", $lines), 0, 400),
            'group' => 'energy', 'level' => $fresh->contains(fn ($a) => $a->target === 'source' && $snap['power']['urgent']) ? 'urgent' : 'normal',
            'url' => 'energy', 'thread' => 'energy', 'kind' => 'energy',
        ]);
    }

    // ── What the control room does ───────────────────────────────────────

    public function done(EnergyAction $a, User $user, ?string $note = null): EnergyAction
    {
        if ($a->status === EnergyAction::OPEN) {
            $a->forceFill(['status' => EnergyAction::DONE, 'done_by' => $user->id, 'done_at' => now(), 'note' => $note ? mb_substr($note, 0, 255) : null])->save();
            // Keep the equipment board true: what was switched off (or on) shows so there.
            if ($a->devices && Schema::hasTable('facility_devices')) {
                $to = $a->saves() ? 'off' : 'on';
                foreach (FacilityDevice::whereIn('id', $a->devices)->get() as $d) {
                    app(FacilityService::class)->set($d, $to, null, 'manual', $user);
                }
            }
            // Switching on or a generator change finishes the job; switching off counts until needed again.
            if (! $a->saves() || $a->target === 'source') {
                $a->forceFill(['closed_at' => now()])->save();
            }
            if ($a->target === 'source') {
                $g = EnergySource::find($a->target_id);
                if ($g && $a->action === 'start') {
                    $this->setRunning($g, true, $user);
                    EnergySource::where('kind', '!=', 'grid')->where('id', '!=', $g->id)->where('running', true)->get()->each(fn ($o) => $this->setRunning($o, false, $user));
                } elseif ($g && $a->action === 'stop') {
                    $this->setRunning($g, false, $user);
                }
            }
        }

        return $a;
    }

    public function skip(EnergyAction $a, User $user, ?string $note = null): EnergyAction
    {
        if ($a->status === EnergyAction::OPEN) {
            $a->forceFill(['status' => EnergyAction::SKIPPED, 'done_by' => $user->id, 'done_at' => now(), 'closed_at' => now(), 'note' => $note ? mb_substr($note, 0, 255) : null])->save();
        }

        return $a;
    }

    /** The grid came back or went off (a person, or a sensor on the changeover switch). Re-plans at once. */
    public function setGrid(bool $on, ?User $user = null, string $by = 'staff'): void
    {
        $grid = EnergySource::firstOrCreate(['kind' => 'grid'], ['name' => 'Grid (public power)', 'capacity_kw' => 0, 'running' => true]);
        if ($grid->running !== $on) {
            $grid->forceFill(['running' => $on, 'running_since' => now()])->save();
            EnergyEvent::create(['source_id' => $grid->id, 'kind' => $on ? 'on' : 'off', 'user_id' => $user?->id, 'by' => $by, 'note' => $on ? 'Grid power back' : 'Grid power off']);
            $this->ctx = null;
            $this->sync();
        }
    }

    public function setRunning(EnergySource $g, bool $on, ?User $user = null, string $by = 'staff'): void
    {
        if ($g->running === $on) {
            return;
        }
        $g->forceFill(['running' => $on, 'running_since' => now()])->save();
        EnergyEvent::create(['source_id' => $g->id, 'kind' => $on ? 'on' : 'off', 'user_id' => $user?->id, 'by' => $by, 'note' => $g->name . ($on ? ' started' : ' stopped')]);
    }

    /** A reading of the tank. Compared with the last reading, the deliveries since and what the generator should have burned. */
    public function fuel(EnergySource $g, float $litres, ?User $user = null, string $by = 'staff'): ?array
    {
        $prev = EnergyEvent::where('source_id', $g->id)->where('kind', 'fuel')->latest('created_at')->latest('id')->first();
        $g->forceFill(['fuel_litres' => max(0, $litres), 'fuel_at' => now()])->save();
        EnergyEvent::create(['source_id' => $g->id, 'kind' => 'fuel', 'value' => $litres, 'user_id' => $user?->id, 'by' => $by, 'note' => "Fuel {$litres} L"]);

        return $prev && $prev->value !== null ? $this->checkFuel($g, (float) $prev->value, $prev->created_at, $litres) : null;
    }

    /**
     * Diesel gone between two readings (start + delivered − now) against what the generator should have burned.
     * Much more gone than burned — a leak, a wrong reading, or diesel taken — tells the control room.
     *
     * @return array{used: float, expected: float, delivered: float, hours: float, flagged: bool}
     */
    public function checkFuel(EnergySource $g, float $before, Carbon $since, float $now): array
    {
        $delivered = (float) EnergyFuelDelivery::where('source_id', $g->id)->whereBetween('delivered_at', [$since, now()])->sum('litres');
        $used = round($before + $delivered - $now, 1);
        $expected = $this->expectedLitres($g, $since, now());
        $hours = round($since->diffInMinutes(now()) / 60, 1);
        $flagged = $hours >= 6 && $expected > 0 && $used > $expected * 1.25 && $used - $expected >= 20;
        if ($flagged) {
            $msg = "{$g->name}: " . number_format($used) . ' L of diesel gone since ' . $since->format('D g:i A') . ', but it should have burned about ' . number_format($expected) . ' L'
                . ($delivered > 0 ? ' (' . number_format($delivered) . ' L delivered in between)' : '') . '. Check the tank and the readings.';
            Audit::record('energy.fuel_variance', $msg, $g, flagged: true);
            $this->alerts->notify('View energy saver', ['title' => 'Diesel: more gone than burned', 'body' => $msg, 'group' => 'energy', 'level' => 'urgent', 'url' => 'energy', 'thread' => "fuel:{$g->id}", 'kind' => 'energy']);
        }

        return compact('used', 'expected', 'delivered', 'hours', 'flagged');
    }

    /**
     * Diesel delivered into a tank: logged once, added to the tank (or the reading taken after filling), and — when
     * asked — recorded as an expense under "Diesel / generator fuel".
     * data: litres, price_per_litre, supplier_id, supplier_name, invoice_ref, level_after, notes, delivered_at, expense (bool)
     */
    public function delivery(EnergySource $g, array $data, ?User $user = null): EnergyFuelDelivery
    {
        $litres = round((float) $data['litres'], 1);
        if ($litres <= 0) {
            throw new \App\Exceptions\BookingException('Enter the litres delivered.');
        }
        $price = round((float) ($data['price_per_litre'] ?? 0), 2);
        $supplierName = $data['supplier_name'] ?? null;
        if (! empty($data['supplier_id']) && Schema::hasTable('suppliers')) {
            $supplierName = \App\Models\Inventory\Supplier::find($data['supplier_id'])?->name ?? $supplierName;
        }
        $d = DB::transaction(function () use ($g, $data, $litres, $price, $supplierName, $user) {
            $d = EnergyFuelDelivery::create([
                'source_id' => $g->id, 'delivered_at' => $data['delivered_at'] ?? now(), 'litres' => $litres, 'price_per_litre' => $price,
                'total' => round($litres * $price, 2), 'supplier_id' => $data['supplier_id'] ?? null, 'supplier_name' => $supplierName,
                'invoice_ref' => $data['invoice_ref'] ?? null, 'level_after' => isset($data['level_after']) && $data['level_after'] !== '' ? (float) $data['level_after'] : null,
                'received_by' => $user?->id, 'notes' => $data['notes'] ?? null,
            ]);
            EnergyEvent::create(['source_id' => $g->id, 'kind' => 'delivery', 'value' => $litres, 'user_id' => $user?->id, 'by' => $user ? 'staff' : 'device',
                'note' => number_format($litres) . " L of diesel delivered into {$g->name}" . ($supplierName ? " by {$supplierName}" : '')]);

            return $d;
        });

        // The tank: a reading after filling is best; otherwise add the delivery to the last known level.
        if ($d->level_after !== null) {
            $this->fuel($g, $d->level_after, $user);
        } elseif ($g->fuel_litres !== null) {
            $level = $g->fuel_litres + $litres;
            $g->forceFill(['fuel_litres' => $g->tank_litres ? min($g->tank_litres, $level) : $level, 'fuel_at' => now()])->save();
        }

        if (! empty($data['expense']) && $user && $d->total > 0) {
            $cat = \App\Models\Finance\ExpenseCategory::where('is_active', true)->where(fn ($q) => $q->where('name', 'like', '%diesel%')->orWhere('name', 'like', '%fuel%'))->orderBy('sort_order')->first()
                ?? \App\Models\Finance\ExpenseCategory::where('is_active', true)->where('group', 'utilities')->orderBy('sort_order')->first();
            if ($cat) {
                $e = app(\App\Services\Finance\ExpenseService::class)->record([
                    'category_id' => $cat->id, 'description' => 'Diesel for ' . $g->name . ' (' . number_format($litres) . ' L at ' . config('hotel.currency_symbol') . number_format($price, 2) . ')',
                    'supplier_id' => $d->supplier_id, 'payee' => $d->supplier_id ? null : $supplierName, 'amount' => $d->total, 'tax_amount' => 0,
                    'expense_date' => $d->delivered_at->toDateString(), 'invoice_ref' => $d->invoice_ref, 'notes' => 'From Energy Saver → Diesel deliveries',
                ], [], $user);
                $d->forceFill(['expense_id' => $e->id])->save();
            }
        }
        Audit::record('energy.diesel_delivered', number_format($litres) . " L of diesel into {$g->name}" . ($d->total > 0 ? ' (' . config('hotel.currency_symbol') . number_format($d->total, 2) . ')' : ''), $g);

        return $d;
    }

    /** A room sensor / key-card switch: someone is in, or the room is empty. */
    public function presence(Room $room, bool $occupied): void
    {
        $state = $occupied ? 'occupied' : 'empty';
        if ($room->presence !== $state) {
            $room->forceFill(['presence' => $state, 'presence_at' => now()])->saveQuietly();
        }
    }

    // ── Figures ─────────────────────────────────────────────────────────

    /** What switching off saved: closed actions plus those still off, since a date. */
    public function savings(Carbon $since, ?Carbon $until = null): array
    {
        $s = $this->settings();
        $closed = (float) EnergyAction::whereNotNull('kwh_saved')->where('closed_at', '>=', $since)->when($until, fn ($q) => $q->where('closed_at', '<=', $until))->sum('kwh_saved');
        // Still switched off: counted to now (only when the range runs to now).
        $ongoing = $until && $until->lt(now()->subMinute()) ? 0.0 : EnergyAction::where('status', EnergyAction::DONE)->whereNull('closed_at')->whereIn('action', ['off', 'low', 'eco'])->get()
            ->sum(fn (EnergyAction $a) => $this->kwhSaved($a, now()));
        $kwh = round($closed + $ongoing, 1);
        $litres = round($kwh * 0.3, 1);   // a generator part-loaded uses about 0.3 L per kWh

        return [
            'kwh' => $kwh, 'grid_value' => round($kwh * $s['grid_price']), 'litres' => $litres, 'diesel_value' => round($litres * $s['diesel_price']),
            'done' => EnergyAction::where('status', EnergyAction::DONE)->where('done_at', '>=', $since)->when($until, fn ($q) => $q->where('done_at', '<=', $until))->count(),
            'skipped' => EnergyAction::where('status', EnergyAction::SKIPPED)->where('done_at', '>=', $since)->when($until, fn ($q) => $q->where('done_at', '<=', $until))->count(),
        ];
    }

    /** Generator run time today, from the on/off log. */
    public function runHours(Carbon $from): array
    {
        $out = [];
        foreach (EnergySource::where('kind', '!=', 'grid')->get() as $g) {
            $events = EnergyEvent::where('source_id', $g->id)->whereIn('kind', ['on', 'off'])->where('created_at', '>=', $from)->orderBy('created_at')->get();
            $last = EnergyEvent::where('source_id', $g->id)->whereIn('kind', ['on', 'off'])->where('created_at', '<', $from)->latest('created_at')->first();
            $on = $last?->kind === 'on' ? $from->copy() : null;
            $mins = 0;
            foreach ($events as $e) {
                if ($e->kind === 'on') {
                    $on ??= $e->created_at;
                } elseif ($on) {
                    $mins += $on->diffInMinutes($e->created_at);
                    $on = null;
                }
            }
            if ($on) {
                $mins += $on->diffInMinutes(now());
            }
            $out[$g->id] = round($mins / 60, 1);
        }

        return $out;
    }
}
