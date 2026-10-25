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
        ]);
    }
}
