<?php

use App\Enums\ReservationStatus;
use App\Models\Booking\LuggageItem;
use App\Models\Messaging\GuestMessage;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Booking\LuggageService;
use App\Services\Booking\OnlineBookingService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    $this->withoutDefer();
    Mail::fake();
    $this->r = Reservation::where('status', ReservationStatus::Confirmed->value)->with('guest')->first();
    $this->r->forceFill(['check_in_date' => today()->addDays(3), 'check_out_date' => today()->addDays(5)])->save();
    $this->token = app(OnlineBookingService::class)->ensureToken($this->r);
});

test('lines of text become items with counts and bags', function () {
    $items = app(LuggageService::class)->parse("Laptop and charger\n2 x shirts\n- Socks x3\nBackpack: camera, power bank\n\n3 trousers");
    expect($items)->toHaveCount(6)
        ->and($items[0])->toBe(['name' => 'Laptop and charger', 'quantity' => 1, 'bag' => null])
        ->and($items[1])->toMatchArray(['name' => 'shirts', 'quantity' => 2])
        ->and($items[2])->toMatchArray(['name' => 'Socks', 'quantity' => 3])
        ->and($items[3])->toBe(['name' => 'camera', 'quantity' => 1, 'bag' => 'Backpack'])
        ->and($items[4])->toMatchArray(['name' => 'power bank', 'bag' => 'Backpack'])
        ->and($items[5])->toMatchArray(['name' => 'trousers', 'quantity' => 3]);
});

test('the guest app keeps a luggage list and ticks it off on check-out day', function () {
    $l = $this->getJson("/api/v1/guest/stays/{$this->token}")->assertOk()->json('data.luggage');
    expect($l['available'])->toBeTrue()->and($l['can_edit'])->toBeTrue()->and($l['count'])->toBe(0)->and($l['check_now'])->toBeFalse();

    $this->postJson("/api/v1/guest/stays/{$this->token}/luggage", ['items' => [['name' => 'Passport'], ['name' => 'Shirts', 'quantity' => 3, 'bag' => 'Blue suitcase']]])
        ->assertCreated()->assertJsonPath('data.count', 2)->assertJsonPath('data.items.1.label', '3 × Shirts')->assertJsonPath('data.bags.0', 'Blue suitcase');
    $l = $this->postJson("/api/v1/guest/stays/{$this->token}/luggage", ['text' => "Charger\nWatch"])->assertCreated()->json('data');
    expect($l['count'])->toBe(4);

    $watch = collect($l['items'])->firstWhere('name', 'Watch')['id'];
    $this->deleteJson("/api/v1/guest/stays/{$this->token}/luggage/{$watch}")->assertOk()->assertJsonPath('data.count', 3);
    $charger = collect($l['items'])->firstWhere('name', 'Charger')['id'];
    $this->patchJson("/api/v1/guest/stays/{$this->token}/luggage/{$charger}", ['name' => 'Phone charger', 'quantity' => 2])->assertOk()->assertJsonPath('data.items.2.label', '2 × Phone charger');

    // Staying, leaving today: the list asks to be checked.
    $this->r->forceFill(['status' => ReservationStatus::CheckedIn, 'check_in_date' => today()->subDays(2), 'check_out_date' => today()])->save();
    $l = $this->getJson("/api/v1/guest/stays/{$this->token}/luggage")->assertOk()->json('data');
    expect($l['check_now'])->toBeTrue()->and($l['left'])->toBe(3)->and($l['note'])->toContain('check out today');

    foreach ($l['items'] as $i) {
        $l = $this->patchJson("/api/v1/guest/stays/{$this->token}/luggage/{$i['id']}", ['packed' => true])->assertOk()->json('data');
    }
    expect($l['left'])->toBe(0)->and($l['check_now'])->toBeFalse()->and($l['note'])->toContain('Everything on your list is packed');

    $this->postJson("/api/v1/guest/stays/{$this->token}/luggage/unpack")->assertOk()->assertJsonPath('data.packed', 0);

    // Another booking's item can't be touched with this token.
    $other = Reservation::where('id', '!=', $this->r->id)->first();
    $foreign = LuggageItem::create(['reservation_id' => $other->id, 'name' => 'Not yours']);
    $this->patchJson("/api/v1/guest/stays/{$this->token}/luggage/{$foreign->id}", ['packed' => true])->assertNotFound();
});

test('the list is read-only after check-out, apart from ticking off on the day', function () {
    LuggageItem::create(['reservation_id' => $this->r->id, 'name' => 'Passport']);
    $this->r->forceFill(['status' => ReservationStatus::CheckedOut, 'checked_out_at' => now()])->save();
    $item = LuggageItem::first();

    $this->postJson("/api/v1/guest/stays/{$this->token}/luggage", ['text' => 'Hat'])->assertStatus(422);
    $this->deleteJson("/api/v1/guest/stays/{$this->token}/luggage/{$item->id}")->assertStatus(422);
    $this->patchJson("/api/v1/guest/stays/{$this->token}/luggage/{$item->id}", ['packed' => true])->assertOk()->assertJsonPath('data.packed', 1);

    $this->r->forceFill(['checked_out_at' => now()->subDays(2)])->save();
    $this->patchJson("/api/v1/guest/stays/{$this->token}/luggage/{$item->id}", ['packed' => false])->assertStatus(422);

    config(['hotel.booking.luggage_list' => false]);
    expect($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.luggage.available'))->toBeFalse();
});

test('a luggage list can be written when booking, on the web and in the app', function () {
    $exe = RoomType::where('code', 'EXE')->first();
    $in = today()->addDays(40)->next(Carbon::MONDAY);
    $q = ['check_in' => $in->toDateString(), 'check_out' => $in->copy()->addDays(2)->toDateString(), 'adults' => 2, 'rooms' => 1, 'room_type_id' => $exe->id];
    $guest = ['first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com', 'phone' => '08031234567', 'accept' => 1];

    $this->get(route('book.details', ['check_in' => $q['check_in'], 'check_out' => $q['check_out'], 'adults' => 2, 'rooms' => 1, 'type' => $exe->id]))->assertOk()->assertSee('Make a luggage list');
    $this->post(route('book.store'), $q + $guest + ['pay' => 'later', 'luggage' => "Laptop\n2 x shirts"])->assertRedirect();
    $r = Reservation::latest('id')->first();
    expect($r->luggageItems->pluck('label')->all())->toBe(['Laptop', '2 × shirts']);

    $res = $this->postJson('/api/v1/guest/bookings', $q + array_merge($guest, ['email' => 'bola@example.com']) + ['luggage' => "Camera\nTripod"])->assertCreated();
    $this->getJson('/api/v1/guest/stays/' . $res->json('data.token'))->assertJsonPath('data.luggage.count', 2);
});

test('the booking page shows, adds to and ticks off the list', function () {
    $page = route('stay.show', $this->token);
    $this->get($page)->assertOk()->assertSee('Your luggage list')->assertSee('Start my list');
    $this->post(route('stay.luggage.store', $this->token), ['text' => "Passport\nShoes", 'bag' => 'Black suitcase'])->assertRedirect($page . '#luggage');
    $item = LuggageItem::where('name', 'Passport')->first();
    expect($item->bag)->toBe('Black suitcase');

    $this->postJson(route('stay.luggage.update', [$this->token, $item->id]), ['packed' => true])->assertOk()->assertJsonPath('luggage.packed', 1);
    $this->get($page)->assertSee('1 of 2 packed');
    $this->post(route('stay.luggage.remove', [$this->token, $item->id]))->assertRedirect();
    expect(LuggageItem::count())->toBe(1);
});

test('guests leaving today are reminded once, and front desk sees what is not ticked off', function () {
    config([
        'hotel.messaging.enabled' => true, 'hotel.messaging.channel' => 'sms', 'hotel.messaging.country_code' => '234',
        'services.twilio.sid' => 'AC123', 'services.twilio.auth_token' => 'secret', 'services.twilio.phone_number' => '+15550001111',
        'hotel.booking.luggage_reminder_time' => '00:00',
    ]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
    $this->r->guest->forceFill(['phone' => '08031234567', 'email' => 'guest@example.com'])->save();
    $this->r->forceFill(['status' => ReservationStatus::CheckedIn, 'check_in_date' => today()->subDays(2), 'check_out_date' => today()])->save();
    app(LuggageService::class)->add($this->r, [['name' => 'Passport'], ['name' => 'Laptop charger']]);

    // Someone leaving tomorrow, or with nothing left to pack, isn't reminded.
    $later = Reservation::where('id', '!=', $this->r->id)->where('status', ReservationStatus::CheckedIn->value)->first()
        ?? Reservation::where('id', '!=', $this->r->id)->first();
    $later->forceFill(['status' => ReservationStatus::CheckedIn, 'check_out_date' => today()->addDay()])->save();
    LuggageItem::create(['reservation_id' => $later->id, 'name' => 'Hat']);

    $this->artisan('luggage:checkout-reminders')->expectsOutputToContain('1 luggage reminder(s) sent')->assertSuccessful();
    $msg = GuestMessage::where('template', 'luggage_reminder')->sole();
    expect($msg->reservation_id)->toBe($this->r->id)->and($msg->body)->toContain('2 item(s)')->and($msg->body)->toContain(route('stay.show', $this->token));
    $html = view('booking.emails.luggage', ['r' => $this->r, 'left' => $this->r->luggageItems()->get(), 'url' => 'https://x.test', 'time' => '12:00 PM'])->render();
    expect($html)->toContain('Laptop charger')->toContain('These 2 items are');
    expect($this->r->fresh()->luggage_reminded_at)->not->toBeNull();

    $this->artisan('luggage:checkout-reminders')->assertSuccessful();
    expect(GuestMessage::where('template', 'luggage_reminder')->count())->toBe(1);

    $admin = User::role('Super Admin')->first();
    $this->actingAs($admin)->get(route('reservations.show', $this->r))->assertOk()
        ->assertSee('2 of 2 items not ticked off')->assertSee('Laptop charger');
});
