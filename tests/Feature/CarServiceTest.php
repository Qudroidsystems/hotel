<?php

use App\Enums\ReservationStatus;
use App\Models\Folio;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Transport\Driver;
use App\Models\Transport\Ride;
use App\Models\Transport\RidePlace;
use App\Models\Transport\Vehicle;
use App\Models\User;
use App\Services\Booking\OnlineBookingService;
use App\Services\Mobile\StaffActions;
use App\Notifications\StaffAlert;
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
    Hotel::query()->update(['latitude' => 6.4281, 'longitude' => 3.4219]);
    $this->car = Vehicle::create(['name' => 'Toyota Camry', 'plate' => 'LAG-123AB', 'color' => 'Black', 'kind' => 'sedan', 'seats' => 4]);
    $this->driverUser = User::create(['name' => 'Musa Driver', 'email' => 'musa@test.local', 'password' => bcrypt('Secret@123'), 'must_change_password' => false]);
    $this->driverUser->assignRole('Driver');
    $this->driverUser->hotels()->sync([Hotel::first()->id]);
    $this->driver = Driver::create(['name' => 'Musa Bello', 'phone' => '08030000001', 'vehicle_id' => $this->car->id, 'user_id' => $this->driverUser->id, 'on_duty' => true]);
    $this->desk = User::create(['name' => 'Desk', 'email' => 'desk@test.local', 'password' => bcrypt('Secret@123')]);
    $this->desk->assignRole('Front Desk');
    $this->desk->hotels()->sync([Hotel::first()->id]);
    $this->airport = RidePlace::create(['name' => 'Lagos Airport (LOS)', 'kind' => 'airport', 'latitude' => 6.5774, 'longitude' => 3.3212, 'fare' => 25000]);
    $this->r = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('guest')->first();
    $this->token = app(OnlineBookingService::class)->ensureToken($this->r);
});

test('a guest books a car to the airport at its set fare; the desk assigns; the driver runs it; the fare goes on the bill', function () {
    $menu = $this->getJson("/api/v1/guest/stays/{$this->token}/rides")->assertOk()->json('data');
    expect($menu['available'])->toBeTrue()->and($menu['places'][0]['fare_label'])->toContain('25,000');

    $this->postJson("/api/v1/guest/stays/{$this->token}/rides", ['direction' => 'from_hotel', 'place_id' => $this->airport->id, 'passengers' => 2, 'bags' => 3, 'flight' => 'p47130'])
        ->assertCreated()->assertJsonPath('data.cars.rides.0.status', 'requested')->assertJsonPath('data.cars.rides.0.flight', 'P47130');
    $ride = Ride::first();
    expect((float) $ride->fare)->toBe(25000.0);
    Notification::assertSentTo($this->desk, StaffAlert::class);

    // The front desk gives it to the nearest free driver from the staff app.
    $item = collect(app(StaffActions::class)->work($this->desk))->firstWhere('key', "ride:{$ride->id}");
    expect(collect($item['actions'])->pluck('id'))->toContain('auto');
    app(StaffActions::class)->perform($this->desk, 'ride', $ride->id, 'auto');
    expect($ride->fresh()->driver_id)->toBe($this->driver->id)->and($ride->fresh()->status)->toBe('assigned');
    Notification::assertSentTo($this->driverUser, StaffAlert::class);

    // The driver: on my way (sharing where they are), here, picked up, dropped off.
    $this->actingAs($this->driverUser, 'sanctum');
    $this->getJson('/api/v1/driver')->assertOk()->assertJsonPath('data.ride.number', $ride->number);
    $actions = app(StaffActions::class);
    $actions->perform($this->driverUser, 'ride', $ride->id, 'start');
    $this->postJson('/api/v1/driver/location', ['lat' => 6.45, 'lng' => 3.43])->assertOk();
    expect($ride->fresh()->eta_minutes)->not->toBeNull();
    $guestView = $this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.cars.rides.0');
    expect($guestView['status_label'])->toBe('Your driver is on the way')->and($guestView['driver']['name'])->toBe('Musa Bello')
        ->and($guestView['car']['plate'])->toBe('LAG-123AB')->and($guestView['driver']['lat'])->toEqual(6.45);
    $before = Folio::where('reservation_id', $this->r->id)->first()?->chargesTotal() ?? 0;
    foreach (['arrived', 'picked_up', 'complete'] as $a) {
        $actions->perform($this->driverUser, 'ride', $ride->id, $a);
    }
    expect($ride->fresh()->status)->toBe('completed')
        ->and(Folio::where('reservation_id', $this->r->id)->first()->chargesTotal())->toBeGreaterThan($before + 24999);

    // Another driver can't touch it.
    $other = User::create(['name' => 'Other', 'email' => 'o@test.local', 'password' => bcrypt('x')]);
    $other->assignRole('Driver');
    expect(fn () => app(\App\Services\Transport\RideService::class)->driverAction($ride, $other, 'start'))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

test('pick-ups from an address are priced by distance; guests cancel before the driver arrives; dispatch board works', function () {
    // A pick-up from Ikeja (about 17 km): distance pricing.
    $this->postJson("/api/v1/guest/stays/{$this->token}/rides/quote", ['lat' => 6.6018, 'lng' => 3.3515])->assertOk()->assertJsonPath('data.fare_label', fn ($v) => str_starts_with($v, '₦'));
    $res = $this->postJson("/api/v1/guest/stays/{$this->token}/rides", ['direction' => 'to_hotel', 'label' => 'Ikeja City Mall', 'lat' => 6.6018, 'lng' => 3.3515])->assertCreated();
    $ride = Ride::first();
    expect($ride->distance_km)->toBeGreaterThan(15)->and((float) $ride->fare)->toBeGreaterThan(3000);

    // A typed address with no position: the hotel confirms the price.
    $this->postJson("/api/v1/guest/stays/{$this->token}/rides", ['direction' => 'to_hotel', 'label' => 'My office in Lekki'])->assertCreated()
        ->assertJsonPath('data.cars.rides.0.fare_label', 'Price confirmed by the hotel');

    $this->actingAs($this->desk)->get(route('transport.index'))->assertOk()->assertSee('Ikeja City Mall')->assertSee('Musa Bello');
    $this->get(route('transport.index', ['partial' => 1]))->assertOk()->assertDontSee('<html', false);
    $this->post(route('transport.rides.assign', $ride), ['driver_id' => $this->driver->id, 'fare' => 9000])->assertSessionHas('success');
    expect((float) $ride->fresh()->fare)->toBe(9000.0);

    auth()->logout();
    $this->postJson("/api/v1/guest/stays/{$this->token}/rides/{$ride->id}/cancel")->assertOk();
    expect($ride->fresh()->status)->toBe('cancelled');
    Notification::assertSentTo($this->driverUser, StaffAlert::class, fn ($n) => str_contains($n->data['title'], 'cancelled'));
});

test('no drivers, no booking; the desk sets up fleet; web booking page books', function () {
    Driver::query()->update(['is_active' => false]);
    $this->postJson("/api/v1/guest/stays/{$this->token}/rides", ['direction' => 'to_hotel', 'label' => 'x'])->assertStatus(422);
    Driver::query()->update(['is_active' => true]);

    $this->actingAs($this->desk);
    $this->post(route('transport.cars.store'), ['name' => 'Toyota Hiace', 'plate' => 'abc-999', 'kind' => 'van', 'seats' => 12, 'status' => 'available'])->assertRedirect();
    expect(Vehicle::where('plate', 'ABC-999')->exists())->toBeTrue();
    $this->post(route('transport.places.store'), ['name' => 'Ojota Park', 'kind' => 'station', 'fare' => 8000])->assertRedirect();
    $this->post(route('transport.drivers.store'), ['name' => 'Ade', 'phone' => '0802', 'user_id' => $this->driverUser->id])->assertSessionHasErrors('user_id');

    auth()->logout();
    $this->get(route('stay.show', $this->token))->assertOk()->assertSee('Book a car');
    $this->post(route('stay.rides.book', $this->token), ['direction' => 'from_hotel', 'place_id' => $this->airport->id, 'passengers' => 1])->assertRedirect();
    expect(Ride::latest('id')->first()->via)->toBe('web');
});
