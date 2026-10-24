<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the first Super Admin account. Set ADMIN_NAME, ADMIN_EMAIL and
 * ADMIN_PASSWORD in .env before seeding. The account is asked to change its
 * password at first sign-in. An existing account's password is never reset.
 */
class UserTableSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@smarthotel.com');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'                 => env('ADMIN_NAME', 'System Administrator'),
                'username'             => 'admin',
                'password'             => Hash::make(env('ADMIN_PASSWORD', 'ChangeMe@123')),
                'must_change_password' => true,
            ]
        );

        if (! $user->hasRole('Super Admin')) {
            $user->assignRole('Super Admin');
        }

        $this->command?->info("Super Admin: {$email}");
    }
}
