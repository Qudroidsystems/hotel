<?php

use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Support\Tenancy\CurrentHotel;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MultiHotelDemoSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the multi-hotel demo builds a second hotel with its own rooms and bookings', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class, MultiHotelDemoSeeder::class]);
    $abuja = Hotel::where('slug', 'b-luxury-abuja')->first();
    expect(CurrentHotel::multi())->toBeTrue()->and($abuja)->not->toBeNull()
        ->and(Room::withoutGlobalScope('hotel')->where('hotel_id', $abuja->id)->count())->toBeGreaterThan(0)
        ->and(Reservation::withoutGlobalScope('hotel')->where('hotel_id', $abuja->id)->count())->toBeGreaterThan(0)
        ->and(\App\Models\User::where('username', 'zainab.abuja')->first()->accessibleHotels()->pluck('id')->all())->toBe([$abuja->id]);
});
