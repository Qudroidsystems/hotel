<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\User;
use App\Support\HotelSettings;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Database\Seeder;

/**
 * Turns the demo into a hotel group: switches the system to several hotels, names the group, fills in the
 * first hotel's details and adds "B Luxury Abuja" with its own rooms, bookings, rates and events, and two
 * members of staff who work only there (password Staff@123).
 *   php artisan db:seed --class=MultiHotelDemoSeeder   (after DemoSeeder; not for a live system)
 */
class MultiHotelDemoSeeder extends Seeder
{
    public function run(): void
    {
        HotelSettings::putGroup('multi_hotel', true);
        HotelSettings::putGroup('group_name', 'B Luxury Hotels');
        HotelSettings::putGroup('commission_percent', 10);
        HotelSettings::apply();

        $lagos = Hotel::orderBy('id')->first();
        $lagos->update(['city' => $lagos->city ?: 'Lagos', 'state' => $lagos->state ?: 'Lagos', 'country' => 'Nigeria', 'stars' => $lagos->stars ?: 5,
            'description' => $lagos->description ?: 'Our flagship on Victoria Island: rooftop pool, three restaurants and the Grand Ballroom.']);

        $abuja = Hotel::firstOrCreate(['slug' => 'b-luxury-abuja'], [
            'name' => 'B Luxury Abuja', 'city' => 'Abuja', 'state' => 'FCT', 'country' => 'Nigeria', 'stars' => 4,
            'address' => 'Plot 12, Central Business District, Abuja', 'phone' => '+234 809 000 1122', 'email' => 'abuja@bluxury.test',
            'description' => 'Minutes from the Central Business District, with meeting rooms and an airport shuttle.',
        ]);

        CurrentHotel::get()->run($abuja, function () {
            $this->call([RoomDemoSeeder::class, ReservationDemoSeeder::class, CommercialDemoSeeder::class]);
        });

        // Staff who work only in Abuja; owners and the Super Admin see both hotels anyway.
        foreach ([['Zainab Abuja', 'zainab.abuja', 'Front Desk'], ['Yusuf Abuja', 'yusuf.abuja', 'Housekeeping']] as [$name, $username, $role]) {
            $u = User::firstOrCreate(['email' => $username . '@smarthotel.test'], ['name' => $name, 'username' => $username, 'password' => bcrypt('Staff@123')]);
            $u->syncRoles([$role]);
            $u->hotels()->sync([$abuja->id]);
        }

        $this->command?->info('Two hotels: ' . $lagos->name . ' and ' . $abuja->name . '. Group booking site: ' . url('/hotels') . '. Abuja staff: zainab.abuja / yusuf.abuja (Staff@123).');
    }
}
