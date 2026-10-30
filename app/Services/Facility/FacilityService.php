<?php

namespace App\Services\Facility;

use App\Models\Facility\FacilityDevice;
use App\Models\Facility\FacilityDeviceEvent;
use App\Models\Facility\FacilityHub;
use App\Models\Room;
use App\Models\User;
use App\Support\Audit;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The on/off board of the hotel's equipment: every room's air conditioner, lights, TV, fridge, water heater,
 * door lock …, and the other places (lobby, kitchen, pool, generator house …).
 *
 * States arrive two ways:
 *  - staff switch them on the board (source "manual");
 *  - a hardware hub reports them: POST /api/v1/facility/report with "Authorization: Bearer <hub key>" and
 *    {"devices": [{"code": "R101-AC", "state": "on", "reading": "22°C"}, …]} (source "hub"). A hub that
 *    stops reporting a device for a while makes it show "No signal".
 */
class FacilityService
{
    public const MAX_REPORT = 500;

    /** Change a device's state. Answers whether anything changed. */
    public function set(FacilityDevice $d, string $state, ?string $reading = null, string $source = 'manual', ?User $by = null): bool
    {
        $state = in_array($state, ['on', 'off', 'fault'], true) ? $state : throw new \InvalidArgumentException('State must be on, off or fault.');
        $reading = $reading !== null ? Str::limit(trim($reading), 28, '') : $d->reading;
        $from = $d->state;
        $changed = $from !== $state;
        $d->forceFill([
            'state' => $state, 'reading' => $reading ?: null,
            'state_changed_at' => $changed ? now() : $d->state_changed_at,
            'last_seen_at' => $source === 'hub' ? now() : $d->last_seen_at,
            'source' => $source,   // staff switching it by hand is the latest word until the hub reports again
        ])->save();
        if ($changed) {
            FacilityDeviceEvent::create(['device_id' => $d->id, 'from_state' => $from, 'state' => $state,
                'reading' => $reading, 'source' => $source, 'user_id' => $by?->id, 'created_at' => now()]);
            if ($source === 'manual') {
                Audit::record('facility.switch', "{$d->place}: {$d->name} switched " . $state, $d, by: $by);
            }
        }

        return $changed;
    }

    /** A hub's report. Answers counts and the codes it doesn't know. */
    public function report(FacilityHub $hub, array $devices, ?string $ip = null): array
    {
        CurrentHotel::get()->adopt($hub);
        $rows = collect($devices)->take(self::MAX_REPORT);
        $codes = $rows->pluck('code')->filter()->map(fn ($c) => (string) $c)->unique();
        $known = FacilityDevice::whereIn('code', $codes)->get()->keyBy('code');
        $changed = 0;
        $seen = 0;
        $unknown = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            $state = $this->normalise($row['state'] ?? null);
            if (! $code || ! $state) {
                continue;
            }
            $d = $known[$code] ?? null;
            if (! $d) {
                $unknown[] = $code;

                continue;
            }
            $seen++;
            $changed += (int) $this->set($d, $state, isset($row['reading']) ? (string) $row['reading'] : null, 'hub');
        }
        $hub->forceFill(['last_seen_at' => now(), 'last_ip' => $ip])->save();

        return ['received' => $rows->count(), 'updated' => $seen, 'changed' => $changed, 'unknown' => array_values(array_unique($unknown))];
    }

    /** "1", true, "ON", "running" → on; "0", "stopped" → off; "alarm", "error" → fault. */
    public function normalise(mixed $state): ?string
    {
        if (is_bool($state)) {
            return $state ? 'on' : 'off';
        }
        $s = Str::lower(trim((string) $state));

        return match (true) {
            in_array($s, ['on', '1', 'true', 'running', 'open', 'online', 'up'], true) => 'on',
            in_array($s, ['off', '0', 'false', 'stopped', 'closed', 'idle', 'standby', 'down'], true) => 'off',
            in_array($s, ['fault', 'error', 'alarm', 'failed', 'tripped'], true) => 'fault',
            default => null,
        };
    }

    /** A new hub key; the plain key is shown once. */
    public function newHub(string $name, User $by): array
    {
        $key = 'fh_' . Str::random(40);
        $hub = FacilityHub::create(['name' => Str::limit($name, 78), 'token_hash' => hash('sha256', $key), 'token_hint' => substr($key, -4), 'is_active' => true, 'created_by' => $by->id]);
        Audit::record('facility.hub_created', "Created hardware hub key \"{$hub->name}\"", $hub, by: $by);

        return [$hub, $key];
    }

    public static function hubFor(?string $key): ?FacilityHub
    {
        if (! $key || strlen($key) < 20) {
            return null;
        }

        return FacilityHub::withoutGlobalScope('hotel')->where('token_hash', hash('sha256', $key))->where('is_active', true)->first();
    }

    /**
     * The board for the console: rooms (by floor) and other areas, each with its devices,
     * plus totals and the alarms.
     */
    public function board(): array
    {
        $devices = FacilityDevice::active()->with('room:id,number,floor_id,status')->orderBy('sort_order')->orderBy('name')->get();
        $rooms = Room::where('is_active', true)->with('floor:id,name,level')->orderBy('number')->get(['id', 'number', 'floor_id', 'status']);
        $byRoom = $devices->whereNotNull('room_id')->groupBy('room_id');
        $dev = fn (FacilityDevice $d) => [
            'id' => $d->id, 'name' => $d->name, 'kind' => $d->kind, 'icon' => $d->kind_icon, 'state' => $d->live_state,
            'reading' => $d->reading, 'alarm' => $d->alarm, 'source' => $d->source, 'critical' => $d->is_critical,
            'since' => $d->state_changed_at?->diffForHumans(short: true),
        ];
        $summary = fn (Collection $g) => [
            'on' => $g->filter(fn ($d) => $d->live_state === 'on')->count(), 'off' => $g->filter(fn ($d) => $d->live_state === 'off')->count(),
            'fault' => $g->filter(fn ($d) => $d->live_state === 'fault')->count(), 'offline' => $g->filter(fn ($d) => $d->live_state === 'offline')->count(),
            'alarms' => $g->filter(fn ($d) => $d->alarm)->count(),
        ];

        $floors = $rooms->groupBy(fn ($r) => $r->floor?->name ?? 'Rooms')->map(fn ($list, $floor) => [
            'floor' => $floor,
            'rooms' => $list->map(fn (Room $r) => [
                'id' => $r->id, 'number' => $r->number, 'status' => $r->status?->value ?? (string) $r->status,
                'occupied' => ($r->status?->value ?? $r->status) === 'occupied',
                'devices' => ($byRoom[$r->id] ?? collect())->map($dev)->values(),
            ] + $summary($byRoom[$r->id] ?? collect()))->values(),
        ])->values();

        $areas = $devices->whereNull('room_id')->groupBy(fn ($d) => $d->area ?: 'Hotel')->map(fn ($g, $name) => [
            'name' => $name, 'devices' => $g->map($dev)->values(),
        ] + $summary($g))->sortKeys()->values();

        $alarms = $devices->filter(fn ($d) => $d->alarm)->sortByDesc(fn ($d) => [$d->is_critical, $d->live_state === 'fault'])->take(40)->map(fn ($d) => [
            'id' => $d->id, 'place' => $d->place, 'name' => $d->name, 'icon' => $d->kind_icon, 'state' => $d->live_state, 'critical' => $d->is_critical,
        ])->values();

        // Rooms nobody is in with things left running (energy).
        $wasted = $devices->filter(fn ($d) => $d->room && ($d->room->status?->value ?? $d->room->status) !== 'occupied' && $d->live_state === 'on'
            && in_array($d->kind, ['ac', 'lights', 'tv', 'water_heater', 'fan'], true))->count();

        return ['floors' => $floors, 'areas' => $areas, 'alarms' => $alarms, 'totals' => $summary($devices) + ['total' => $devices->count(), 'empty_rooms_running' => $wasted]];
    }

    /** Set up the usual equipment for rooms that have none yet (Console → Equipment → "Add the usual"). */
    public function addStandard(User $by): int
    {
        $kinds = ['ac' => 'Air conditioner', 'lights' => 'Lights', 'tv' => 'TV', 'fridge' => 'Minibar fridge', 'water_heater' => 'Water heater', 'door_lock' => 'Door lock'];
        $n = 0;
        $have = FacilityDevice::whereNotNull('room_id')->pluck('room_id')->unique()->flip();
        foreach (Room::where('is_active', true)->get(['id', 'number']) as $room) {
            if (isset($have[$room->id])) {
                continue;
            }
            $i = 0;
            foreach ($kinds as $kind => $name) {
                FacilityDevice::create(['room_id' => $room->id, 'name' => $name, 'kind' => $kind, 'code' => $this->code('R' . $room->number, $kind),
                    'state' => $kind === 'door_lock' ? 'on' : 'off', 'is_critical' => $kind === 'door_lock', 'should_be_on' => $kind === 'door_lock', 'sort_order' => $i++]);
                $n++;
            }
        }
        if ($n) {
            Audit::record('facility.standard', "Added the usual equipment to rooms ({$n} devices)", by: $by);
        }

        return $n;
    }

    /** A unique hardware code: "R101-AC", "LOBBY-LIGHTS-2". */
    public function code(string $prefix, string $kind): string
    {
        $base = Str::upper(Str::slug($prefix . '-' . $kind));
        $code = $base;
        for ($i = 2; FacilityDevice::where('code', $code)->exists(); $i++) {
            $code = $base . '-' . $i;
        }

        return $code;
    }
}
