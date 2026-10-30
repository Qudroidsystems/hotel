<?php

namespace Database\Seeders;

use App\Models\Facility\FacilityDevice;
use App\Models\Room;
use App\Models\User;
use App\Services\Facility\FacilityService;
use Illuminate\Database\Seeder;

/**
 * Demo equipment for the call console's on/off board: the usual things in every room (on in
 * occupied rooms, mostly off in empty ones, a couple of faults) and the hotel's other areas
 * (generator, pumps, lifts, kitchen cold room, lobby, pool …), fed by a demo hardware hub:
 *   php artisan db:seed --class=CallConsoleDemoSeeder
 * Then `php artisan facility:simulate --loop` makes the demo hub report every 20 s and flip a few states, as real
 * hardware would (without it the hub's equipment shows "No signal" after 15 minutes, as it should).
 */
class CallConsoleDemoSeeder extends Seeder
{
    public function run(FacilityService $facility): void
    {
        if (FacilityDevice::exists()) {
            $this->command?->warn('Equipment is already listed — demo not added.');

            return;
        }
        $admin = User::role('Super Admin')->first() ?? User::first();
        mt_srand(42);

        $roomKinds = ['ac' => 'Air conditioner', 'lights' => 'Lights', 'tv' => 'Smart TV', 'fridge' => 'Minibar fridge', 'water_heater' => 'Water heater', 'door_lock' => 'Door lock', 'safe' => 'Room safe'];
        foreach (Room::where('is_active', true)->get() as $room) {
            $occupied = ($room->status?->value ?? $room->status) === 'occupied';
            $i = 0;
            foreach ($roomKinds as $kind => $name) {
                $state = match ($kind) {
                    'door_lock', 'safe', 'fridge' => 'on',
                    'ac' => $occupied ? (mt_rand(1, 10) > 2 ? 'on' : 'off') : (mt_rand(1, 10) > 8 ? 'on' : 'off'),
                    'lights', 'tv' => $occupied ? (mt_rand(1, 10) > 4 ? 'on' : 'off') : (mt_rand(1, 10) > 9 ? 'on' : 'off'),
                    default => $occupied ? 'on' : 'off',
                };
                if (mt_rand(1, 60) === 1) {
                    $state = 'fault';
                }
                $this->device($facility, ['room_id' => $room->id, 'name' => $name, 'kind' => $kind, 'code' => $facility->code('R' . $room->number, $kind),
                    'is_critical' => $kind === 'door_lock', 'should_be_on' => in_array($kind, ['door_lock', 'fridge'], true), 'sort_order' => $i++], $state, $kind === 'ac' && $state === 'on' ? mt_rand(19, 24) . '°C' : null);
            }
        }

        $areas = [
            'Generator house' => [['generator', 'Generator 1 (main)', 'off', false, false, 'Standby'], ['generator', 'Generator 2 (standby)', 'off', false, false, 'Standby'], ['inverter', 'Inverter bank', 'on', true, true, '86%'], ['solar', 'Solar array', 'on', false, false, '14.2 kW']],
            'Water / pump house' => [['pump', 'Borehole pump', 'on', true, true, null], ['pump', 'Booster pump', 'on', true, true, '3.1 bar'], ['water_heater', 'Central boiler', 'on', false, true, '62°C']],
            'Lifts' => [['lift', 'Lift A', 'on', true, true, null], ['lift', 'Lift B', 'fault', true, true, 'Door sensor']],
            'Kitchen' => [['cold_room', 'Cold room', 'on', true, true, '3°C'], ['cold_room', 'Freezer', 'on', true, true, '-18°C'], ['fan', 'Extractor hood', 'on', false, false, null], ['cooker', 'Combi oven', 'off', false, false, null], ['smoke', 'Smoke detector', 'on', true, true, null]],
            'Lobby' => [['lights', 'Lobby lights', 'on', false, false, null], ['ac', 'Lobby A/C', 'on', false, false, '22°C'], ['sound', 'Background music', 'on', false, false, null], ['cctv', 'CCTV entrance', 'on', true, true, null], ['wifi', 'Wi-Fi AP Lobby', 'on', true, true, null]],
            'Restaurant' => [['lights', 'Restaurant lights', 'on', false, false, null], ['ac', 'Restaurant A/C', 'on', false, false, '23°C'], ['sound', 'Music', 'off', false, false, null]],
            'Pool' => [['pump', 'Pool filter pump', 'on', false, true, null], ['lights', 'Pool lights', 'off', false, false, null]],
            'Conference hall' => [['ac', 'Hall A/C', 'off', false, false, null], ['lights', 'Hall lights', 'off', false, false, null], ['sound', 'PA system', 'off', false, false, null]],
            'Car park' => [['gate', 'Barrier gate', 'on', false, false, null], ['cctv', 'CCTV car park', 'on', true, true, null], ['lights', 'Flood lights', 'off', false, false, null]],
            'Server room' => [['ac', 'Server room A/C', 'on', true, true, '19°C'], ['inverter', 'UPS', 'on', true, true, '100%'], ['fire_panel', 'Fire alarm panel', 'on', true, true, 'Normal']],
            'Corridors' => [['lights', 'Corridor lights', 'on', false, false, null], ['smoke', 'Smoke detectors', 'on', true, true, null], ['cctv', 'CCTV floor 2', 'on', true, true, null]],
        ];
        foreach ($areas as $area => $list) {
            foreach ($list as $i => [$kind, $name, $state, $critical, $always, $reading]) {
                $this->device($facility, ['area' => $area, 'name' => $name, 'kind' => $kind, 'code' => $facility->code($area . ' ' . $name, ''),
                    'is_critical' => $critical, 'should_be_on' => $always, 'sort_order' => $i], $state, $reading);
            }
        }
        // One camera the demo hub hasn't heard from in a while: "No signal".
        FacilityDevice::where('name', 'CCTV car park')->update(['last_seen_at' => now()->subHour()]);

        [$hub, $key] = $facility->newHub('Demo building management system', $admin);
        $this->command?->info('Demo equipment added: ' . FacilityDevice::count() . " devices. Demo hub key (try it with the API): {$key}");
    }

    private function device(FacilityService $facility, array $attrs, string $state, ?string $reading): void
    {
        $d = FacilityDevice::create($attrs + ['state' => 'off']);
        // Rooms are set by staff; the plant (generators, pumps, lifts, server room …) reports through the demo hub.
        $hub = ! $d->room_id && in_array($d->area, ['Generator house', 'Water / pump house', 'Lifts', 'Server room', 'Car park'], true);
        $d->forceFill(['state' => $state, 'reading' => $reading, 'source' => $hub ? 'hub' : 'manual', 'last_seen_at' => $hub ? now() : null, 'state_changed_at' => now()->subMinutes(mt_rand(3, 900))])->save();
    }
}
