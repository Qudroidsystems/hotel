<?php

namespace Database\Seeders;

use App\Enums\RoomStatus;
use App\Models\HousekeepingTask;
use App\Models\Incident;
use App\Models\It\ItTicket;
use App\Models\MaintenanceOrder;
use App\Models\Room;
use App\Models\User;
use App\Models\Visitor;
use App\Services\It\ServiceDesk;
use App\Services\Operations\HousekeepingService;
use App\Services\Operations\MaintenanceService;
use App\Services\Security\SecurityService;
use Illuminate\Database\Seeder;

/**
 * Day-to-day work so the operations screens and the staff app have something to show: rooms to clean (one
 * waiting for inspection), repairs at each stage, visitors on site, lost property, security incidents and
 * IT tickets. Uses the same services as the screens, so numbers, audit lines and alerts are real.
 *   php artisan db:seed --class=OperationsDemoSeeder   (after RoomDemoSeeder and AttendanceDemoSeeder)
 */
class OperationsDemoSeeder extends Seeder
{
    public function run(HousekeepingService $hk, MaintenanceService $maint, SecurityService $security, ServiceDesk $desk): void
    {
        $admin = User::role('Super Admin')->first() ?? User::first();
        auth()->login($admin);
        $by = fn (string $role) => User::role($role)->first() ?? $admin;
        $rooms = Room::orderBy('number')->get();
        if ($rooms->isEmpty()) {
            $this->command?->warn('No rooms yet — run RoomDemoSeeder first.');

            return;
        }

        // Each part is added once: running the seeder again leaves parts that already have data alone.
        $doRooms = ! MaintenanceOrder::exists();
        $doSecurity = ! Incident::exists() && ! Visitor::exists();
        $doIt = ! ItTicket::exists();

        // ── Housekeeping: a few rooms to clean, one in progress, one waiting for the supervisor.
        if ($doRooms) {
            $cleaner = $by('Housekeeping');
            $free = $rooms->filter(fn ($r) => $r->status !== RoomStatus::Occupied)->values();
            foreach ($free->take(4) as $i => $room) {
            if (! HousekeepingTask::where('room_id', $room->id)->whereIn('status', ['pending', 'in_progress', 'cleaned'])->exists()) {
                $hk->raise($room, $i === 3 ? 'deep' : 'checkout', null, $i === 0 ? 'Guest left early; check the minibar.' : null, $cleaner->id);
            }
        }
        // One being cleaned now, one cleaned and waiting for the supervisor's inspection.
        $pending = HousekeepingTask::where('status', HousekeepingTask::PENDING)->orderBy('id')->take(2)->get();
        foreach ($pending as $i => $task) {
            $hk->start($task, $cleaner);
            if ($i === 1) {
                $hk->finish($task->fresh(), $cleaner, 'Changed all linen and towels.');
            }
        }

        // ── Repairs at each stage.
            $tech = $by('Maintenance Technician');
            $orders = [
                ['room' => 0, 'category' => 'air_conditioning', 'title' => 'AC blowing warm air', 'priority' => 'high', 'blocks' => true],
                ['room' => 1, 'category' => 'plumbing', 'title' => 'Shower drains slowly', 'priority' => 'normal'],
                ['room' => null, 'location' => 'Lobby', 'category' => 'electrical', 'title' => 'Two ceiling lights out by reception', 'priority' => 'low'],
                ['room' => 2, 'category' => 'wifi', 'title' => 'TV has no signal on DSTV channels', 'priority' => 'normal'],
                ['room' => null, 'location' => 'Kitchen', 'category' => 'appliance', 'title' => 'Cold room door seal torn', 'priority' => 'urgent'],
            ];
            foreach ($orders as $i => $o) {
                $room = $o['room'] !== null ? $free->get($o['room'] + 4) ?? $rooms->get($o['room']) : null;
                $order = $maint->report([
                    'room_id' => $room?->id, 'location' => $o['location'] ?? null, 'category' => $o['category'], 'title' => $o['title'],
                    'description' => 'Reported during the daily walk-round.', 'priority' => $o['priority'], 'blocks_room' => $o['blocks'] ?? false,
                    'assigned_to' => $i < 4 ? $tech->id : null,
                ], [], $by('Housekeeping Supervisor'));
                if ($i === 1) {
                    $order->forceFill(['status' => 'in_progress', 'started_at' => now()->subHour()])->save();
                } elseif ($i === 3) {
                    $order->forceFill(['status' => 'completed', 'started_at' => now()->subHours(5), 'completed_at' => now()->subHours(4),
                        'completed_by' => $tech->id, 'completion_note' => 'Re-terminated the coax cable behind the TV.', 'cost' => 3500])->save();
                }
            }

        }

        // ── Security: visitors on site and gone, lost property, incidents.
        if ($doSecurity) {
            $guard = $by('Security Officer');
            foreach ([
                ['full_name' => 'Chinedu Eze', 'phone' => '08031112222', 'purpose' => 'guest_visit', 'host' => 'Guest in room '.($rooms->first()->number ?? ''), 'id_type' => 'NIN', 'id_number' => '12345678901'],
                ['full_name' => 'Glo Fibre Technician', 'company' => 'Globacom', 'purpose' => 'contractor', 'host' => 'IT Operations', 'vehicle_plate' => 'LSD 482 KJ'],
                ['full_name' => 'Amaka Nwosu', 'purpose' => 'interview', 'host' => 'HR', 'phone' => '08094445555'],
            ] as $i => $v) {
                $visitor = $security->signIn($v, $guard);
                if ($i === 2) {
                    $visitor->forceFill(['signed_in_at' => now()->subHours(3)])->save();
                    $security->signOut($visitor, $guard);
                }
            }
            foreach ([
                ['description' => 'Black iPhone in a blue case', 'category' => 'phone', 'valuable' => true, 'found_location' => 'Pool bar', 'storage_location' => 'Security safe'],
                ['description' => 'Grey suit jacket', 'category' => 'clothing', 'found_location' => 'Room '.($rooms->get(1)->number ?? ''), 'room_id' => $rooms->get(1)?->id, 'storage_location' => 'Housekeeping store'],
                ['description' => 'Car key (Toyota)', 'category' => 'keys', 'found_location' => 'Car park', 'storage_location' => 'Front desk drawer'],
            ] as $item) {
                $security->logFound($item + ['found_at' => now()->subDays(rand(0, 6))], null, $guard);
            }
            $security->report([
                'type' => 'guest_complaint', 'severity' => 'medium', 'title' => 'Noise complaint from the second floor',
                'description' => 'Guest called at 23:40 about loud music next door. Officer asked the room to lower the volume.',
                'location' => 'Second floor corridor', 'occurred_at' => now()->subDay()->setTime(23, 40),
            ], [], $guard);
            $incident = $security->report([
                'type' => 'property_damage', 'severity' => 'low', 'title' => 'Scratched lift door',
                'description' => 'Luggage trolley scraped the lift door on the ground floor.', 'location' => 'Lift, ground floor',
                'occurred_at' => now()->subDays(3),
            ], [], $guard);
            $security->resolve($incident, $admin, 'Door panel polished by the contractor; trolleys now kept by the stairs.');

        }

        // ── IT tickets (ItDemoSeeder adds its own).
        if ($doIt) {
            $it = $by('IT Operations');
            foreach ([
                ['title' => 'Bar till printer not printing receipts', 'category' => 'printer', 'priority' => 'high', 'department' => 'Food & Beverage', 'location' => 'Main bar'],
                ['title' => 'Wi-Fi weak on the third floor', 'category' => 'wifi', 'priority' => 'normal', 'department' => 'Rooms / Front Office', 'location' => 'Third floor'],
                ['title' => 'New staff member needs a login', 'category' => 'account', 'priority' => 'low', 'department' => 'Administration'],
            ] as $t) {
                $desk->open($t + ['description' => 'Raised by the front office.', 'assigned_to' => $it->id], $by('Front Desk'));
            }

        }

        $this->command?->info('Operations demo added: housekeeping, repairs, visitors, lost property, incidents and IT tickets.');
    }
}
