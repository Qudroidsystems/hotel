<?php

use App\Models\Booking\GuestLoginCode;
use App\Models\Events\Event;
use App\Models\Events\EventPackage;
use App\Models\Events\Hall;
use App\Services\Events\EventService;
use App\Services\Events\TicketService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class]);
    $this->withoutDefer();
    $this->travelTo(today()->setTime(9, 0));
    $this->hall = Hall::create(['name' => 'Grand Ballroom', 'capacity_theatre' => 200, 'capacity_banquet' => 120, 'hourly_rate' => 50000, 'half_day_rate' => 150000, 'full_day_rate' => 400000]);
    $this->package = EventPackage::create(['name' => 'Day delegate', 'price_per_person' => 8000]);
    // The ballroom is taken next Saturday afternoon.
    $this->sat = today()->next('Saturday');
    $this->actingAs(\App\Models\User::role('Super Admin')->first());
    app(EventService::class)->save([
        'title' => 'Wedding', 'event_type' => 'wedding', 'status' => 'confirmed', 'hall_id' => $this->hall->id,
        'starts_at' => $this->sat->copy()->setTime(12, 0), 'ends_at' => $this->sat->copy()->setTime(18, 0), 'guests_expected' => 100,
        'organiser_name' => 'Ada', 'hall_rate_type' => 'full_day',
    ]);
    auth()->logout();
});

test('anyone can see the halls, when one is taken and a price for a time', function () {
    $this->getJson('/api/v1/guest/venues')->assertOk()
        ->assertJsonPath('data.halls.0.name', 'Grand Ballroom')
        ->assertJsonPath('data.halls.0.max_guests', 200)
        ->assertJsonPath('data.packages.0.name', 'Day delegate')
        ->assertJsonFragment(['key' => 'wedding']);

    $this->getJson("/api/v1/guest/venues/{$this->hall->id}/availability")->assertOk()->assertJsonCount(1, 'data.taken');

    $busy = $this->getJson("/api/v1/guest/venues/{$this->hall->id}/availability?" . http_build_query([
        'starts_at' => $this->sat->copy()->setTime(16, 0)->toIso8601String(), 'ends_at' => $this->sat->copy()->setTime(20, 0)->toIso8601String(),
    ]))->assertOk();
    expect($busy->json('data.free'))->toBeFalse();

    // Two hours on Sunday morning for 50 guests with the package: hourly hire + catering.
    $sun = $this->sat->copy()->addDay();
    $free = $this->getJson("/api/v1/guest/venues/{$this->hall->id}/availability?" . http_build_query([
        'starts_at' => $sun->copy()->setTime(9, 0)->toIso8601String(), 'ends_at' => $sun->copy()->setTime(11, 0)->toIso8601String(),
        'guests' => 50, 'setup' => 'banquet', 'package_id' => $this->package->id,
    ]))->assertOk();
    expect($free->json('data.free'))->toBeTrue()
        ->and($free->json('data.rate'))->toBe('hourly')
        ->and((float) $free->json('data.estimate'))->toBe(100000.0 + 400000.0);
});

test('a guest asks to hire a hall from the app: an enquiry the events team sees', function () {
    Mail::fake();
    $sun = $this->sat->copy()->addDay();
    $body = [
        'hall_id' => $this->hall->id, 'title' => 'Team offsite', 'event_type' => 'meeting', 'setup' => 'banquet', 'guests' => 40,
        'starts_at' => $sun->copy()->setTime(9, 0)->toIso8601String(), 'ends_at' => $sun->copy()->setTime(13, 0)->toIso8601String(),
        'package_id' => $this->package->id, 'name' => 'Bola Ade', 'email' => 'Bola@Example.com', 'phone' => '08031234567', 'notes' => 'Projector please',
    ];
    $this->postJson('/api/v1/guest/venues/requests', $body)->assertCreated()
        ->assertJsonPath('data.status', 'enquiry')
        ->assertJsonPath('data.hall', 'Grand Ballroom');

    $e = Event::where('title', 'Team offsite')->firstOrFail();
    expect($e->status)->toBe('enquiry')
        ->and($e->organiser_email)->toBe('bola@example.com')
        ->and($e->package_id)->toBe($this->package->id)
        ->and($e->hall_rate_type)->toBe('half_day');
    $this->assertDatabaseHas('website_messages', ['kind' => 'event', 'email' => 'Bola@Example.com']);

    // A taken time is refused; too many guests for the layout too.
    $this->postJson('/api/v1/guest/venues/requests', ['starts_at' => $this->sat->copy()->setTime(13, 0)->toIso8601String(), 'ends_at' => $this->sat->copy()->setTime(15, 0)->toIso8601String()] + $body)
        ->assertStatus(422)->assertJsonPath('code', 'hall_taken');
    $this->postJson('/api/v1/guest/venues/requests', ['guests' => 500] + $body)->assertStatus(422);
});

test('new guests make an account with their e-mail and see their hall requests', function () {
    Mail::fake();
    $this->postJson('/api/v1/guest/auth/code', ['contact' => 'newbie@example.com'])->assertOk()->assertJsonPath('data.via', 'email');
    $row = GuestLoginCode::where('contact', 'newbie@example.com')->firstOrFail();
    // The code is only kept hashed: find it by trying them all (6 digits, the test knows the hash recipe).
    $code = collect(range(100000, 999999))->first(fn ($c) => hash_equals($row->code_hash, hash('sha256', 'newbie@example.com|' . $c)));
    $token = $this->postJson('/api/v1/guest/auth/verify', ['contact' => 'newbie@example.com', 'code' => (string) $code])->assertOk()->json('data.token');
    $this->getJson('/api/v1/guest/me', ['X-Guest-Token' => $token])->assertOk()->assertJsonPath('data.contact', 'newbie@example.com')->assertJsonPath('data.stays', []);

    $sun = $this->sat->copy()->addDay();
    $this->postJson('/api/v1/guest/venues/requests', [
        'hall_id' => $this->hall->id, 'title' => 'Birthday', 'event_type' => 'birthday', 'guests' => 30,
        'starts_at' => $sun->copy()->setTime(14, 0)->toIso8601String(), 'ends_at' => $sun->copy()->setTime(18, 0)->toIso8601String(),
        'name' => 'New Bie', 'email' => 'newbie@example.com', 'phone' => '08031234567',
    ])->assertCreated();
    $this->getJson('/api/v1/guest/venues/requests', ['X-Guest-Token' => $token])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Birthday');
    $this->getJson('/api/v1/guest/venues/requests')->assertUnauthorized();

    // An unknown phone number still gets nothing.
    config(['hotel.guest_app.open_sign_up' => false]);
    $this->postJson('/api/v1/guest/auth/code', ['contact' => 'other@example.com'])->assertOk();
    expect(GuestLoginCode::where('contact', 'other@example.com')->exists())->toBeFalse();
});

test('the events with tickets on sale are listed for everyone', function () {
    $this->actingAs(\App\Models\User::role('Super Admin')->first());
    $sun = $this->sat->copy()->addDay();
    $e = app(EventService::class)->save([
        'title' => 'Jazz Night', 'event_type' => 'other', 'status' => 'confirmed', 'hall_id' => $this->hall->id,
        'starts_at' => $sun->copy()->setTime(18, 0), 'ends_at' => $sun->copy()->setTime(22, 0), 'guests_expected' => 100,
        'organiser_name' => 'Hotel', 'hall_rate_type' => 'custom', 'hall_charge' => 0,
    ]);
    $tickets = app(TicketService::class);
    $tickets->configure($e, true, 'Live band from 7pm');
    $tickets->saveType($e, ['name' => 'Regular', 'price' => 5000, 'max_per_order' => 6, 'admits' => 1, 'is_active' => true]);
    $tickets->setSalesOpen($e->fresh(), true);
    auth()->logout();

    $this->getJson('/api/v1/guest/events')->assertOk()
        ->assertJsonPath('data.0.title', 'Jazz Night')
        ->assertJsonPath('data.0.ticket_types.0.name', 'Regular')
        ->assertJsonPath('data.0.info', 'Live band from 7pm');
});
