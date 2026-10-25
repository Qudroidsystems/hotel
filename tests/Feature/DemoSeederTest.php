<?php

use App\Models\Booking\GuestRequest;
use App\Models\Incident;
use App\Models\MaintenanceOrder;
use App\Models\Messaging\GuestMessage;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the demo seeder builds a complete demo hotel, and running it again adds nothing twice', function () {
    $this->withoutDefer();
    $this->seed(DemoSeeder::class);

    expect(Reservation::count())->toBeGreaterThan(10)
        ->and(User::count())->toBeGreaterThan(10)
        ->and(MaintenanceOrder::count())->toBe(5)
        ->and(Incident::count())->toBe(2)
        ->and(GuestRequest::count())->toBeGreaterThan(0)
        ->and(GuestMessage::where('direction', 'in')->count())->toBe(1);

    $this->seed(DemoSeeder::class);
    expect(MaintenanceOrder::count())->toBe(5)->and(Incident::count())->toBe(2);
});
