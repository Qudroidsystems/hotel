<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Transport\Driver;
use App\Models\Transport\Ride;
use App\Models\Transport\RidePlace;
use App\Models\Transport\Vehicle;
use App\Models\User;
use App\Services\Transport\RideService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo car service: three cars, three drivers (one signs in to the staff app as
 * driver.musa@smarthotel.com / Staff@123), the airport and two places at set
 * fares, an airport pick-up waiting for a driver and a ride done today.
 */
class TransportDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Vehicle::exists()) {
            return;
        }
        $admin = User::role('Super Admin')->first();
        auth()->login($admin);

        $cars = collect([
            ['Toyota Camry', 'LND-482KJ', 'Black', 'sedan', 4],
            ['Toyota Highlander', 'KJA-119XY', 'Silver', 'suv', 6],
            ['Toyota Hiace', 'EKY-730AA', 'White', 'van', 12],
        ])->map(fn ($c) => Vehicle::create(['name' => $c[0], 'plate' => $c[1], 'color' => $c[2], 'kind' => $c[3], 'seats' => $c[4]]));

        $musa = User::firstOrCreate(['email' => 'driver.musa@smarthotel.com'], [
            'name' => 'Musa Bello', 'username' => 'musa.bello', 'password' => Hash::make('Staff@123'), 'must_change_password' => true,
        ]);
        $musa->assignRole('Driver');
        if (method_exists($musa, 'hotels')) {
            $musa->hotels()->syncWithoutDetaching([\App\Support\Tenancy\CurrentHotel::get()->id()]);
        }
        $drivers = collect([
            ['Musa Bello', '08031110001', $cars[0], $musa->id, true, [6.4400, 3.4300]],
            ['Chika Obi', '08031110002', $cars[1], null, true, [6.4550, 3.3900]],
            ['Tunde Ade', '08031110003', $cars[2], null, false, null],
        ])->map(function ($d) {
            $driver = Driver::create(['name' => $d[0], 'phone' => $d[1], 'vehicle_id' => $d[2]->id, 'user_id' => $d[3], 'on_duty' => $d[4],
                'licence_number' => 'LAG' . random_int(100000, 999999), 'licence_expires' => today()->addYears(2)]);
            if ($d[5]) {
                $driver->forceFill(['latitude' => $d[5][0], 'longitude' => $d[5][1], 'seen_at' => now()->subMinutes(2)])->save();
            }

            return $driver;
        });

        $airport = RidePlace::create(['name' => 'Murtala Muhammed Airport (LOS)', 'kind' => 'airport', 'latitude' => 6.5774, 'longitude' => 3.3212, 'fare' => 25000, 'sort_order' => 0]);
        RidePlace::create(['name' => 'Lekki Conservation Centre', 'kind' => 'other', 'latitude' => 6.4419, 'longitude' => 3.5354, 'fare' => 12000, 'sort_order' => 1]);
        RidePlace::create(['name' => 'Ojota Motor Park', 'kind' => 'station', 'latitude' => 6.5833, 'longitude' => 3.3833, 'fare' => 18000, 'sort_order' => 2]);

        $svc = app(RideService::class);
        // A guest flying in tomorrow wants collecting from the airport.
        if ($arriving = Reservation::where('status', ReservationStatus::Confirmed->value)->orderBy('check_in_date')->first()) {
            try {
                $svc->book($arriving, ['direction' => 'to_hotel', 'place_id' => $airport->id, 'pickup_at' => $arriving->check_in_date->copy()->setTime(14, 30), 'passengers' => 2, 'bags' => 3, 'flight' => 'P47130', 'notes' => 'Meet at arrivals with a name card'], 'app');
            } catch (\Throwable) {
            }
        }
        // An in-house guest went to the Lekki Conservation Centre this morning.
        if ($inHouse = Reservation::where('status', ReservationStatus::CheckedIn->value)->first()) {
            try {
                $ride = $svc->book($inHouse, ['direction' => 'from_hotel', 'place_id' => RidePlace::where('name', 'like', 'Lekki%')->value('id'), 'passengers' => 1], 'app');
                $svc->assign($ride, $drivers[1], null, $admin);
                foreach (['start', 'arrived', 'picked_up', 'complete'] as $a) {
                    $svc->driverAction($ride, $admin, $a);
                }
            } catch (\Throwable) {
            }
        }

        $this->command?->info('Car service: ' . $cars->count() . ' cars, ' . $drivers->count() . ' drivers, ' . Ride::count() . ' rides. Driver app sign-in: driver.musa@smarthotel.com / Staff@123');
    }
}
