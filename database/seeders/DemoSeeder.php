<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * A complete demo hotel in one go, for training and show-and-tell:
 *   php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder
 * Staff accounts get the password Staff@123 (they must change it at first sign-in).
 * Don't run it on the live hotel: it adds sample guests, bookings and money.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // The demo hotel's place on the map (Victoria Island, Lagos), for guest directions and arrival sharing.
        \App\Models\Hotel::whereNull('latitude')->orderBy('id')->limit(1)->update(['latitude' => 6.4281, 'longitude' => 3.4219]);
        $this->call([
            DatabaseSeeder::class,          // permissions, roles, the admin account, expense categories
            RoomDemoSeeder::class,          // buildings, floors, room types, rooms
            AttendanceDemoSeeder::class,    // staff for every role, departments, shifts, five weeks of clock-ins
            ReservationDemoSeeder::class,   // guests and bookings around today, bills and payments
            InventoryDemoSeeder::class,     // stores, suppliers, stock
            PosDemoSeeder::class,           // bar and restaurant menus and sales
            ExpenseDemoSeeder::class,       // running costs
            ItDemoSeeder::class,            // IT assets and monitors
            OperationsDemoSeeder::class,    // housekeeping, repairs, security, IT tickets
            GuestServicesDemoSeeder::class, // loyalty, guest requests, messages
            CommercialDemoSeeder::class,    // rates & seasons, company accounts, halls & events
            TransportDemoSeeder::class,     // the hotel's cars, drivers and rides
            LaundryDemoSeeder::class,       // the laundry price list and a few guests' bags
            WellnessDemoSeeder::class,      // the spa, gym and pool, and today's bookings
            PayrollDemoSeeder::class,       // everyone's pay, and last month's payroll (paid)
            EnergyDemoSeeder::class,        // generators, places, and the control room's first list
            WebsiteDemoSeeder::class,       // the public website: slides, dining, offers, gallery, journal
            NetworkDemoSeeder::class,       // Starlink dishes, guest Wi-Fi plans and codes
            FeedbackPlansDemoSeeder::class, // guest feedback after check-out, servicing plans
        ]);
    }
}
