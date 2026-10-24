<?php

namespace Database\Seeders;

use App\Enums\RoomStatus;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

/**
 * Sample property for trying the rooms module:
 *   php artisan db:seed --class=RoomDemoSeeder
 * Then optionally: php artisan db:seed --class=ReservationDemoSeeder
 * Not run by the main DatabaseSeeder. Safe to run twice.
 */
class RoomDemoSeeder extends Seeder
{
    public function run(): void
    {
        $types = collect([
            ['code' => 'STD', 'name' => 'Standard',  'base_rate' => 25000,  'weekend_rate' => 30000,  'max_adults' => 2, 'max_children' => 0, 'bed_type' => 'Double', 'size_sqm' => 20, 'color' => '#0ea5e9',
             'amenities' => ['Air conditioning', 'Wi-Fi', 'Smart TV', 'Walk-in shower', 'Toiletries']],
            ['code' => 'DLX', 'name' => 'Deluxe',    'base_rate' => 40000,  'weekend_rate' => 45000,  'max_adults' => 2, 'max_children' => 1, 'bed_type' => 'Queen',  'size_sqm' => 28, 'color' => '#16a34a',
             'amenities' => ['Air conditioning', 'Wi-Fi', 'Smart TV', 'DSTV / cable', 'Mini fridge', 'Work desk', 'Toiletries']],
            ['code' => 'EXE', 'name' => 'Executive', 'base_rate' => 65000,  'weekend_rate' => null,   'max_adults' => 2, 'max_children' => 2, 'bed_type' => 'King',   'size_sqm' => 36, 'color' => '#d97706',
             'amenities' => ['Air conditioning', 'Wi-Fi', 'Smart TV', 'DSTV / cable', 'Minibar', 'Work desk', 'In-room safe', 'Bathtub', 'Bathrobe & slippers']],
            ['code' => 'STE', 'name' => 'Suite',     'base_rate' => 120000, 'weekend_rate' => 135000, 'max_adults' => 4, 'max_children' => 2, 'bed_type' => 'King',   'size_sqm' => 60, 'color' => '#7c3aed',
             'amenities' => ['Air conditioning', 'Wi-Fi', 'Smart TV', 'DSTV / cable', 'Minibar', 'Sitting area', 'Kitchenette', 'Bathtub', 'Balcony', 'Room service']],
        ])->mapWithKeys(fn ($t) => [$t['code'] => RoomType::firstOrCreate(['code' => $t['code']], $t)]);

        $building = Building::firstOrCreate(['code' => 'MAIN'], ['name' => 'Main Building']);

        // Ground floor: Standard; 1st: Deluxe; 2nd: Executive; 3rd: Suites
        $plan = [0 => ['STD', 1, 8], 1 => ['DLX', 101, 110], 2 => ['EXE', 201, 208], 3 => ['STE', 301, 304]];
        // Occupied / Reserved come from real bookings (see ReservationDemoSeeder), so only housekeeping states here.
        $statuses = [RoomStatus::Available, RoomStatus::Available, RoomStatus::Available, RoomStatus::Dirty,
                     RoomStatus::Available, RoomStatus::Available, RoomStatus::Cleaning, RoomStatus::Available];

        $i = 0;
        foreach ($plan as $level => [$code, $from, $to]) {
            $floor = Floor::firstOrCreate(['building_id' => $building->id, 'level' => $level], ['name' => Floor::defaultName($level)]);
            foreach (range($from, $to) as $n) {
                $number = $level === 0 ? 'G' . str_pad($n, 2, '0', STR_PAD_LEFT) : (string) $n;
                $room = Room::firstOrCreate(['number' => $number], [
                    'building_id' => $building->id, 'floor_id' => $floor->id,
                    'room_type_id' => $types[$code]->id, 'status' => RoomStatus::Available,
                ]);
                if ($room->wasRecentlyCreated) {
                    $room->statusLogs()->create(['to_status' => RoomStatus::Available->value, 'reason' => 'Room created (demo data)']);
                    $status = $statuses[$i++ % count($statuses)];
                    $room->changeStatus($status, 'Demo data');
                }
            }
        }

        Room::where('number', '207')->first()?->changeStatus(RoomStatus::Maintenance, 'Demo: AC not cooling');
    }
}
