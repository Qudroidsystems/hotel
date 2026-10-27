<?php

use App\Enums\ReservationStatus;
use App\Models\Booking\GuestArrival;
use App\Models\Hotel;
use App\Models\HousekeepingTask;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\Booking\OnlineBookingService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    Notification::fake();
    $this->withoutDefer();
    Hotel::query()->update(['latitude' => 6.4281, 'longitude' => 3.4219]);   // Victoria Island, Lagos
    $this->r = Reservation::where('status', ReservationStatus::Confirmed->value)->with('rooms.room', 'guest')->first();
    $this->r->forceFill(['check_in_date' => today(), 'check_out_date' => today()->addDays(2)])->save();
    $this->token = app(OnlineBookingService::class)->ensureToken($this->r);
    $this->desk = User::create(['name' => 'Desk', 'email' => 'desk@test.local', 'password' => bcrypt('Secret@123')]);
    $this->desk->assignRole('Front Desk');
    $this->desk->hotels()->sync([Hotel::first()->id]);
});

test('a guest on the way shares their arrival; reception hears, then hears again when they are close and here', function () {
    $stay = $this->getJson("/api/v1/guest/stays/{$this->token}")->assertOk()->json('data.arrival');
    expect($stay['available'])->toBeTrue()->and($stay['directions']['google'])->toContain('6.4281,3.4219');

    // From Ikeja, ~17 km away.
    $a = $this->postJson("/api/v1/guest/stays/{$this->token}/arrival", ['lat' => 6.6018, 'lng' => 3.3515, 'speed' => 0])->assertOk()->json('data.arrival');
    expect($a['sharing'])->toBeTrue()->and($a['eta_minutes'])->toBeGreaterThan(30)->and($a['distance_label'])->toContain('km');
    Notification::assertSentTo($this->desk, StaffAlert::class, fn ($n) => str_contains(json_encode($n->toArray($this->desk)), 'is on the way'));

    // Close by: one "almost here".
    $this->postJson("/api/v1/guest/stays/{$this->token}/arrival", ['lat' => 6.4350, 'lng' => 3.4250])->assertOk();
    $this->postJson("/api/v1/guest/stays/{$this->token}/arrival", ['lat' => 6.4340, 'lng' => 3.4245])->assertOk();
    Notification::assertSentToTimes($this->desk, StaffAlert::class, 2);

    // At the door: sharing stops and the position is forgotten.
    $a = $this->postJson("/api/v1/guest/stays/{$this->token}/arrival", ['lat' => 6.4282, 'lng' => 3.4220])->assertOk()->json('data.arrival');
    expect($a['sharing'])->toBeFalse()->and($a['arrived'])->toBeTrue();
    $row = GuestArrival::first();
    expect($row->latitude)->toBeNull()->and($row->status)->toBe('arrived');
    Notification::assertSentToTimes($this->desk, StaffAlert::class, 3);

    // Staff see it on the booking.
    $this->actingAs(User::role('Super Admin')->first())->get(route('reservations.show', $this->r))->assertSee('The guest is at the hotel');
});

test('the guest can stop; check-in stops it; stale shares end on their own', function () {
    $this->postJson("/api/v1/guest/stays/{$this->token}/arrival", ['lat' => 6.6018, 'lng' => 3.3515])->assertOk();
    $this->postJson("/api/v1/guest/stays/{$this->token}/arrival/stop")->assertOk()->assertJsonPath('data.arrival.sharing', false);
    expect(GuestArrival::first()->latitude)->toBeNull();

    $this->postJson("/api/v1/guest/stays/{$this->token}/arrival", ['lat' => 6.6018, 'lng' => 3.3515])->assertOk();
    $this->r->forceFill(['status' => ReservationStatus::CheckedIn])->save();
    expect(GuestArrival::latest('id')->first()->stop_reason)->toBe('checked_in');

    $other = Reservation::where('status', ReservationStatus::Confirmed->value)->where('id', '!=', $this->r->id)->first();
    $other->forceFill(['check_in_date' => today(), 'check_out_date' => today()->addDay()])->save();
    $t2 = app(OnlineBookingService::class)->ensureToken($other);
    $this->postJson("/api/v1/guest/stays/{$t2}/arrival", ['lat' => 6.6018, 'lng' => 3.3515])->assertOk();
    GuestArrival::where('reservation_id', $other->id)->update(['last_seen_at' => now()->subHour()]);
    $this->artisan('arrivals:expire')->assertSuccessful();
    expect(GuestArrival::where('reservation_id', $other->id)->first()->stop_reason)->toBe('timed_out');
});

test('not available far ahead of the stay or before the hotel is on the map; the room clean is hurried', function () {
    $this->r->forceFill(['check_in_date' => today()->addDays(5), 'check_out_date' => today()->addDays(7)])->save();
    expect($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.arrival.available'))->toBeFalse();
    $this->postJson("/api/v1/guest/stays/{$this->token}/arrival", ['lat' => 6.6, 'lng' => 3.35])->assertStatus(422);

    $this->r->forceFill(['check_in_date' => today(), 'check_out_date' => today()->addDays(2)])->save();
    $room = $this->r->rooms->first()->room;
    $task = app(\App\Services\Operations\HousekeepingService::class)->raise($room, 'checkout');
    $this->postJson("/api/v1/guest/stays/{$this->token}/arrival", ['lat' => 6.6018, 'lng' => 3.3515])->assertOk();
    expect($task->fresh()->urgent)->toBeTrue()->and($task->fresh()->notes)->toContain('Guest arriving about');

    Hotel::query()->update(['latitude' => null, 'longitude' => null]);
    expect($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.arrival.available'))->toBeFalse();
});
