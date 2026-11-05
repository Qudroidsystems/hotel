<?php

use App\Enums\ReservationStatus;
use App\Models\Folio;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Transport\Driver;
use App\Models\Transport\Ride;
use App\Models\Transport\RidePlace;
use App\Models\Transport\Vehicle;
use App\Models\Transport\VehicleLog;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\Booking\OnlineBookingService;
use App\Services\Transport\RideService;
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
    $hotel = Hotel::first();
    $hotel->update(['latitude' => 6.4281, 'longitude' => 3.4219]);
    $this->car = Vehicle::create(['name' => 'Toyota Camry', 'plate' => 'LAG-123AB', 'color' => 'Black', 'kind' => 'sedan', 'seats' => 4, 'odometer_km' => 40000,
        'insurance_expires' => today()->addYear(), 'roadworthy_expires' => today()->addYear(), 'service_every_km' => 5000, 'last_service_km' => 35600]);
    $this->van = Vehicle::create(['name' => 'Toyota Hiace', 'plate' => 'LAG-777VN', 'kind' => 'van', 'seats' => 12]);
    $mk = function ($name, $email, $role) use ($hotel) {
        $u = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt('Secret@123'), 'must_change_password' => false]);
        $u->assignRole($role);
        $u->hotels()->sync([$hotel->id]);

        return $u;
    };
    $this->driverUser = $mk('Musa Driver', 'musa@test.local', 'Driver');
    $this->driver = Driver::create(['name' => 'Musa Bello', 'phone' => '08030000001', 'vehicle_id' => $this->car->id, 'user_id' => $this->driverUser->id, 'on_duty' => false]);
    $this->driver2User = $mk('Ola Driver', 'ola@test.local', 'Driver');
    $this->driver2 = Driver::create(['name' => 'Ola Ade', 'phone' => '08030000002', 'vehicle_id' => $this->van->id, 'user_id' => $this->driver2User->id, 'on_duty' => true]);
    $this->desk = $mk('Desk', 'desk@test.local', 'Front Desk');
    $this->airport = RidePlace::create(['name' => 'Lagos Airport (LOS)', 'kind' => 'airport', 'latitude' => 6.5774, 'longitude' => 3.3212, 'fare' => 25000]);
    $this->r = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('guest')->first();
    $this->rides = app(RideService::class);
    // Bookings spread over the stays (a stay may have only a few cars open at once).
    $this->pool = Reservation::whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value, ReservationStatus::CheckedIn->value])
        ->whereDate('check_out_date', '>=', today())->orderByRaw("status = 'checked_in' desc")->orderBy('id')->get()->values();
    $this->n = 0;
    $this->book = function (array $d = []) {
        $r = $this->n === 0 ? $this->r : $this->pool[$this->n % $this->pool->count()];
        $this->n++;

        return $this->rides->book($r, $d + ['direction' => 'from_hotel', 'place_id' => $this->airport->id], 'desk');
    };
});

test('going on duty needs the car check; a failed check alerts the car desk and an unsafe car comes off the road', function () {
    $this->actingAs($this->driverUser, 'sanctum');
    $res = $this->postJson('/api/v1/driver/duty', ['on' => true])->assertStatus(422)->assertJsonPath('code', 'vehicle_check');
    expect(collect($res->json('data.items'))->pluck('key'))->toContain('tyres', 'brakes');

    $all = collect(VehicleLog::CHECKS)->map(fn () => true)->all();
    $this->postJson('/api/v1/driver/check', ['checks' => $all, 'km' => 40120, 'safe' => true, 'on' => true])->assertOk()
        ->assertJsonPath('data.on_duty', true)->assertJsonPath('data.car.checked', true);
    expect($this->car->fresh()->odometer_km)->toBe(40120);

    // The van's driver finds bad brakes and says it isn't safe.
    $this->actingAs($this->driver2User, 'sanctum');
    $this->postJson('/api/v1/driver/check', ['checks' => ['brakes' => false] + $all, 'safe' => false, 'notes' => 'Brakes grinding'], ['X-Offline-Id' => 'chk-00000001'])->assertOk();
    $this->postJson('/api/v1/driver/check', ['checks' => ['brakes' => false] + $all, 'safe' => false, 'notes' => 'Brakes grinding'], ['X-Offline-Id' => 'chk-00000001'])->assertOk();
    expect($this->van->fresh()->status)->toBe('out_of_service')->and(VehicleLog::where('vehicle_id', $this->van->id)->count())->toBe(1);
    Notification::assertSentTo($this->desk, StaffAlert::class, fn ($n) => str_contains($n->toArray($this->desk)['title'] ?? '', 'off the road'));
});

test('a car or driver is never given two rides at once, papers that ran out or too few seats', function () {
    $this->driver->update(['on_duty' => true]);
    $a = ($this->book)(['pickup_at' => now()->addHours(3)->toIso8601String()]);
    $b = ($this->book)(['pickup_at' => now()->addHours(3)->addMinutes(30)->toIso8601String()]);
    $c = ($this->book)(['pickup_at' => now()->addHours(6)->toIso8601String()]);
    $this->rides->assign($a, $this->driver, null, $this->desk);
    expect(fn () => $this->rides->assign($b, $this->driver, null, $this->desk))->toThrow(\App\Exceptions\BookingException::class, 'around then');
    $this->rides->assign($c, $this->driver, null, $this->desk);   // hours later: fine

    $big = ($this->book)(['passengers' => 7, 'pickup_at' => now()->addHours(10)->toIso8601String()]);
    expect(fn () => $this->rides->assign($big, $this->driver, null, $this->desk))->toThrow(\App\Exceptions\BookingException::class, 'seats');
    $this->rides->assign($big, $this->driver2, $this->van, $this->desk);

    $this->car->update(['insurance_expires' => today()->subDay()]);
    $d = ($this->book)(['pickup_at' => now()->addHours(20)->toIso8601String()]);
    expect(fn () => $this->rides->assign($d, $this->driver->fresh(), null, $this->desk))->toThrow(\App\Exceptions\BookingException::class, 'Insurance');
    expect($this->rides->autoAssign($d, $this->desk)?->id)->not->toBe($this->driver->id);

    $this->driver2->update(['licence_expires' => today()->subDay()]);
    $e = ($this->book)(['pickup_at' => now()->addHours(30)->toIso8601String()]);
    expect(fn () => $this->rides->assign($e, $this->driver2, $this->van, $this->desk))->toThrow(\App\Exceptions\BookingException::class, 'licence');
});

test('a cash ride: kilometres, cash collected, handed in at the desk onto the bill and into the till; a no-show needs the wait', function () {
    $this->driver->update(['on_duty' => true]);
    $ride = ($this->book)(['payment' => 'cash']);
    $this->rides->assign($ride, $this->driver, null, $this->desk);
    $this->actingAs($this->driverUser, 'sanctum');
    $this->postJson("/api/v1/driver/rides/{$ride->id}/start", ['km' => 40200])->assertOk();
    $this->postJson("/api/v1/driver/rides/{$ride->id}/arrived")->assertOk();
    $this->postJson("/api/v1/driver/rides/{$ride->id}/picked_up")->assertOk();
    $this->postJson("/api/v1/driver/rides/{$ride->id}/complete", ['km' => 40150])->assertStatus(422);   // less than at the start
    $done = $this->postJson("/api/v1/driver/rides/{$ride->id}/complete", ['km' => 40242, 'cash' => 25000])->assertOk();
    expect($done->json('message'))->toContain('Hand the')->and($done->json('data.cash_held'))->toEqual(25000);
    $ride->refresh();
    expect($ride->end_km)->toBe(40242)->and((float) $ride->cash_collected)->toBe(25000.0)->and($this->car->fresh()->odometer_km)->toBe(40242);
    $folio = Folio::where('reservation_id', $this->r->id)->first();
    $before = $folio?->balance() ?? 0;

    // Service due soon: the car desk heard (35,600 + 5,000, warned from 500 km before).
    Notification::assertSentTo($this->desk, StaffAlert::class, fn ($n) => str_contains($n->toArray($this->desk)['title'] ?? '', 'Service'));

    // The desk takes the cash in from the phone: on the bill as charged and paid, nothing more owed.
    $this->actingAs($this->desk, 'sanctum');
    $item = collect($this->getJson('/api/v1/modules/dispatch?tab=cash')->assertOk()->json('data.items'))->firstWhere('id', $ride->id);
    expect(collect($item['actions'])->pluck('id'))->toContain('cash_in');
    $this->postJson("/api/v1/modules/dispatch/items/{$ride->id}/actions/cash_in")->assertOk();
    $this->postJson("/api/v1/modules/dispatch/items/{$ride->id}/actions/cash_in")->assertStatus(422);
    $folio = Folio::where('reservation_id', $this->r->id)->first();
    expect(round($folio->balance(), 2))->toBe(round($before, 2))
        ->and(\App\Models\Payment::where('reference', $ride->number)->value('received_by'))->toBe($this->desk->id);

    // No-show: only after waiting.
    $ride2 = ($this->book)();
    $this->rides->assign($ride2, $this->driver, null, $this->desk);
    $this->actingAs($this->driverUser, 'sanctum');
    $this->postJson("/api/v1/driver/rides/{$ride2->id}/arrived")->assertOk();
    $this->postJson("/api/v1/driver/rides/{$ride2->id}/no_show")->assertStatus(422)->assertJsonPath('code', 'not_allowed_now');
    $ride2->forceFill(['arrived_at' => now()->subMinutes(20)])->save();
    $this->postJson("/api/v1/driver/rides/{$ride2->id}/no_show", ['note' => 'Phone off'])->assertOk();
    expect($ride2->fresh()->status)->toBe('cancelled')->and($ride2->fresh()->no_show)->toBeTrue();
});

test('a step sent later from a phone that was offline keeps the time it really happened, once', function () {
    $this->driver->update(['on_duty' => true]);
    $ride = ($this->book)();
    $this->rides->assign($ride, $this->driver, null, $this->desk);
    $this->actingAs($this->driverUser, 'sanctum');
    $at = now()->subMinutes(12)->startOfSecond();
    $h = ['X-Offline-Id' => 'ride-step-000001', 'X-Offline-At' => $at->toIso8601String()];
    $this->postJson("/api/v1/driver/rides/{$ride->id}/start", [], $h)->assertOk();
    $this->postJson("/api/v1/driver/rides/{$ride->id}/start", [], $h)->assertOk()->assertHeader('X-Offline-Replayed', '1');
    expect($ride->fresh()->started_at->equalTo($at))->toBeTrue();
});

test('reminders: a booked car with no driver near pick-up alerts the desk once; the driver is told when it is time to go', function () {
    $ride = ($this->book)(['pickup_at' => now()->addMinutes(45)->toIso8601String()]);
    Notification::fake();
    expect($this->rides->reminders())->toBe(1)->and($this->rides->reminders())->toBe(0);
    Notification::assertSentTo($this->desk, StaffAlert::class);

    $this->driver->update(['on_duty' => true]);
    $this->rides->assign($ride->fresh(), $this->driver, null, $this->desk);
    $ride->forceFill(['pickup_at' => now()->addMinutes(20)])->save();
    expect($this->rides->reminders())->toBe(1);
    Notification::assertSentTo($this->driverUser, StaffAlert::class, fn ($n) => str_contains($n->toArray($this->driverUser)['title'] ?? '', 'Time to go'));

    $this->car->update(['roadworthy_expires' => today()->addDays(5)]);
    expect(implode(' ', $this->rides->fleetWarnings()))->toContain('Roadworthiness ends');
    $this->artisan('transport:remind')->assertSuccessful();
    $this->artisan('transport:fleet')->assertSuccessful();
});

test('fleet from the phone: log fuel and a service, take a car off the road and back', function () {
    $this->actingAs($this->desk, 'sanctum');
    $item = collect($this->getJson('/api/v1/modules/fleet')->assertOk()->json('data.items'))->firstWhere('id', $this->car->id);
    expect(collect($item['actions'])->pluck('id'))->toContain('fuel', 'service', 'off', 'papers');
    $this->postJson("/api/v1/modules/fleet/items/{$this->car->id}/actions/fuel", ['litres' => 40, 'cost' => 38000, 'km' => 40500])->assertOk();
    $this->postJson("/api/v1/modules/fleet/items/{$this->car->id}/actions/off", ['notes' => 'Flat tyre'])->assertOk();
    expect($this->car->fresh()->status)->toBe('out_of_service');
    $this->postJson("/api/v1/modules/fleet/items/{$this->car->id}/actions/service", ['notes' => 'New tyre, oil change', 'km' => 40510, 'back_in_service' => true])->assertOk();
    $car = $this->car->fresh();
    expect($car->status)->toBe('available')->and($car->last_service_km)->toBe(40510)->and($car->serviceDue())->toBeNull()
        ->and(VehicleLog::where('vehicle_id', $car->id)->pluck('kind')->all())->toContain('fuel', 'service');

    // The website's car page shows it, and takes a log too.
    $this->actingAs($this->desk)->get(route('transport.index', ['tab' => 'cars', 'edit' => $car->id]))->assertOk()->assertSee('New tyre, oil change');
    $this->post(route('transport.cars.log', $car), ['kind' => 'issue', 'notes' => 'Aircon weak'])->assertRedirect();
    expect(VehicleLog::where('kind', 'issue')->count())->toBe(1);
});
