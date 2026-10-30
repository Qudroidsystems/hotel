<?php

namespace App\Models\Facility;

use App\Models\Room;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One piece of equipment in a room or another part of the hotel (an air conditioner, the lobby lights,
 * the pool pump, the generator) and whether it is on, off or faulty. States come from staff switching
 * them on the board, or from a building-management / IoT hub posting to /api/v1/facility/report.
 */
class FacilityDevice extends Model
{
    use BelongsToHotel;

    /** kind => [label, icon, usually critical] */
    public const KINDS = [
        'ac'           => ['Air conditioner', 'ri-snowflake-line', false],
        'lights'       => ['Lights', 'ri-lightbulb-line', false],
        'tv'           => ['TV', 'ri-tv-2-line', false],
        'fridge'       => ['Fridge / minibar', 'ri-fridge-line', false],
        'water_heater' => ['Water heater', 'ri-fire-line', false],
        'door_lock'    => ['Door lock', 'ri-door-lock-line', true],
        'safe'         => ['Safe', 'ri-safe-2-line', false],
        'socket'       => ['Power sockets', 'ri-plug-line', false],
        'fan'          => ['Fan / extractor', 'ri-windy-line', false],
        'wifi'         => ['Wi-Fi access point', 'ri-wifi-line', true],
        'cctv'         => ['CCTV camera', 'ri-camera-lens-line', true],
        'smoke'        => ['Smoke detector', 'ri-alarm-warning-line', true],
        'fire_panel'   => ['Fire alarm panel', 'ri-fire-line', true],
        'generator'    => ['Generator', 'ri-flashlight-line', true],
        'inverter'     => ['Inverter / UPS', 'ri-battery-2-charge-line', true],
        'solar'        => ['Solar', 'ri-sun-line', false],
        'pump'         => ['Water pump', 'ri-drop-line', true],
        'lift'         => ['Lift', 'ri-arrow-up-down-line', true],
        'cold_room'    => ['Cold room / freezer', 'ri-temp-cold-line', true],
        'cooker'       => ['Cooker / oven', 'ri-restaurant-line', false],
        'sound'        => ['Music / PA', 'ri-music-2-line', false],
        'gate'         => ['Gate / barrier', 'ri-door-open-line', false],
        'other'        => ['Other', 'ri-settings-3-line', false],
    ];

    /** Places that aren't rooms, offered when adding equipment (any other name can be typed). */
    public const AREAS = [
        'Lobby', 'Reception', 'Restaurant', 'Bar', 'Kitchen', 'Laundry', 'Pool', 'Gym', 'Spa', 'Conference hall',
        'Corridors', 'Lifts', 'Car park', 'Gate house', 'Generator house', 'Water / pump house', 'Server room', 'Roof', 'Garden', 'Staff quarters',
    ];

    public const STATES = [
        'on'      => ['On', '#22c55e'],
        'off'     => ['Off', '#64748b'],
        'fault'   => ['Fault', '#ef4444'],
        'offline' => ['No signal', '#f59e0b'],
    ];

    protected $fillable = ['room_id', 'area', 'name', 'kind', 'code', 'state', 'reading', 'source', 'is_critical', 'should_be_on', 'is_active', 'sort_order', 'notes'];

    protected $casts = [
        'is_critical' => 'boolean', 'should_be_on' => 'boolean', 'is_active' => 'boolean',
        'state_changed_at' => 'datetime', 'last_seen_at' => 'datetime',
    ];

    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function events(): HasMany { return $this->hasMany(FacilityDeviceEvent::class, 'device_id'); }

    public function scopeActive($q) { return $q->where('is_active', true); }

    /** Where it is: "Room 101" or "Kitchen". */
    public function getPlaceAttribute(): string
    {
        return $this->room_id ? 'Room ' . ($this->room?->number ?? '?') : ($this->area ?: 'Hotel');
    }

    public function getKindLabelAttribute(): string { return self::KINDS[$this->kind][0] ?? ucfirst(str_replace('_', ' ', $this->kind)); }
    public function getKindIconAttribute(): string { return self::KINDS[$this->kind][1] ?? 'ri-settings-3-line'; }

    /** A hub-fed device that has gone quiet shows "No signal" rather than a state that may be stale. */
    public function getLiveStateAttribute(): string
    {
        $stale = (int) config('hotel.call_console.device_stale_minutes', 15);
        if ($this->source === 'hub' && (! $this->last_seen_at || $this->last_seen_at->lt(now()->subMinutes($stale)))) {
            return 'offline';
        }

        return $this->state;
    }

    /** Needs attention: a fault, a critical device off or silent, or something that should always run but doesn't. */
    public function getAlarmAttribute(): bool
    {
        $s = $this->live_state;

        return $s === 'fault' || ($s !== 'on' && ($this->is_critical || $this->should_be_on));
    }
}
