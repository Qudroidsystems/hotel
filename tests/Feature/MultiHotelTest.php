<?php

use App\Models\Building;
use App\Models\Floor;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\Mobile\StaffAlerts;
use App\Services\Reservations\ReservationService;
use App\Support\Tenancy\CurrentHotel;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class]);
    $this->withoutDefer();
    $this->admin = User::role('Super Admin')->first();
    $this->hotelA = Hotel::orderBy('id')->first();
    \App\Support\HotelSettings::putGroup('multi_hotel', true);
    \App\Support\HotelSettings::apply();
    $this->hotelB = Hotel::create(['name' => 'B Luxury Abuja', 'city' => 'Abuja']);

    // Hotel B's own set-up, made while working in it.
    CurrentHotel::get()->run($this->hotelB, function () {
        $this->typeB = RoomType::create(['code' => 'DLX', 'name' => 'Deluxe Abuja', 'base_rate' => 50000, 'max_adults' => 2, 'max_children' => 1, 'is_active' => true]);
        $building = Building::create(['code' => 'MAIN', 'name' => 'Main']);
        $floor = Floor::create(['building_id' => $building->id, 'level' => 1, 'name' => 'Floor 1']);
        $this->roomB = Room::create(['number' => '101', 'building_id' => $building->id, 'floor_id' => $floor->id, 'room_type_id' => $this->typeB->id, 'status' => 'available', 'is_active' => true]);
    });

    $this->clerkB = User::factory()->create(['name' => 'Abuja Clerk']);
    $this->clerkB->assignRole('Front Desk');
    $this->clerkB->hotels()->sync([$this->hotelB->id]);
});

test('records belong to the hotel they were made in and stay in it', function () {
    expect($this->roomB->hotel_id)->toBe($this->hotelB->id)
        ->and(Room::where('number', '101')->count())->toBe(1)                     // only hotel A's 101 from here
        ->and(Room::withoutGlobalScope('hotel')->where('number', '101')->count())->toBe(2)   // the same number in each hotel
        ->and(RoomType::where('code', 'DLX')->first()->hotel_id)->toBe($this->hotelA->id);

    CurrentHotel::get()->run($this->hotelB, function () {
        expect(Room::count())->toBe(1)->and(RoomType::pluck('name')->all())->toBe(['Deluxe Abuja']);
    });
    CurrentHotel::get()->across(fn () => expect(RoomType::where('code', 'DLX')->count())->toBe(2));
});

test('staff only see and open their own hotel', function () {
    $this->actingAs($this->clerkB);
    $this->get(route('rooms.index'))->assertOk()->assertSee('Deluxe Abuja')->assertDontSee('Executive');

    // Another hotel's booking is simply not there.
    $a = CurrentHotel::get()->run($this->hotelA, fn () => app(ReservationService::class)->create(Guest::create(['first_name' => 'Ada', 'last_name' => 'A']),
        ['check_in_date' => today()->addDays(3)->toDateString(), 'check_out_date' => today()->addDays(4)->toDateString(), 'status' => 'confirmed'],
        [['room_type_id' => RoomType::where('code', 'EXE')->value('id'), 'adults' => 1]]));
    $this->get(route('reservations.show', $a))->assertNotFound();

    // Nor can a form pick another hotel's room type.
    $this->post(route('reservations.store'), ['first_name' => 'X', 'last_name' => 'Y', 'check_in_date' => today()->addDay()->toDateString(), 'check_out_date' => today()->addDays(2)->toDateString(),
        'rooms' => [['room_type_id' => RoomType::withoutGlobalScope('hotel')->where('hotel_id', $this->hotelA->id)->value('id'), 'adults' => 1]]])
        ->assertSessionHasErrors();

    // And can't switch to a hotel they don't work at.
    $this->post(route('hotels.switch'), ['hotel_id' => $this->hotelA->id])->assertForbidden();
});

test('people who see every hotel switch between them', function () {
    $this->actingAs($this->admin);
    $this->get(route('rooms.index'))->assertSee('Executive');
    $this->post(route('hotels.switch'), ['hotel_id' => $this->hotelB->id])->assertRedirect();
    $this->get(route('rooms.index'))->assertSee('Deluxe Abuja')->assertDontSee('Executive');
    $this->get(route('dashboard'))->assertOk()->assertSee('B Luxury Abuja');
});

test('settings are per hotel', function () {
    CurrentHotel::get()->run($this->hotelB, fn () => \App\Models\HotelSetting::create(['key' => 'tax_rates.vat', 'value' => 5]));
    expect(\App\Support\HotelSettings::stored($this->hotelB->id)['tax_rates.vat'] ?? null)->toBe(5)
        ->and(\App\Support\HotelSettings::stored($this->hotelA->id))->not->toHaveKey('tax_rates.vat');

    // Working in hotel B uses its name and its VAT.
    CurrentHotel::get()->run($this->hotelB, function () {
        expect(config('hotel.name'))->toBe('B Luxury Abuja')->and((float) config('hotel.tax_rates.vat'))->toBe(5.0);
    });
    expect(config('hotel.name'))->not->toBe('B Luxury Abuja');
});

test('alerts go to the staff of the hotel it happened in', function () {
    Notification::fake();
    $clerkA = User::factory()->create(['name' => 'Lagos Clerk']);
    $clerkA->assignRole('Front Desk');
    $clerkA->hotels()->sync([$this->hotelA->id]);

    CurrentHotel::get()->run($this->hotelB, fn () => app(StaffAlerts::class)->notify('View reservations', ['title' => 'Test alert', 'body' => 'x']));
    Notification::assertSentTo($this->clerkB, StaffAlert::class);
    Notification::assertNotSentTo($clerkA, StaffAlert::class);
});

test('scheduled jobs run for each hotel', function () {
    $this->artisan('rooms:prepare-arrivals')->expectsOutputToContain('B Luxury Abuja')->assertSuccessful();
});

test('the hotels page sets the mode, adds hotels and their staff', function () {
    $this->actingAs($this->admin);
    $this->get(route('settings.hotels.index'))->assertOk()->assertSee('B Luxury Abuja');
    $this->post(route('settings.hotels.store'), ['name' => 'B Luxury Port Harcourt', 'city' => 'Port Harcourt', 'is_listed' => 1])->assertRedirect();
    $ph = Hotel::where('name', 'B Luxury Port Harcourt')->first();
    expect($ph->slug)->toBe('b-luxury-port-harcourt');
    $this->put(route('settings.hotels.update', $ph), ['name' => $ph->name, 'is_active' => 1, 'staff' => ['', $this->clerkB->id]])->assertRedirect();
    expect($this->clerkB->fresh()->accessibleHotels()->pluck('id')->sort()->values()->all())->toBe(collect([$this->hotelB->id, $ph->id])->sort()->values()->all());

    $this->post(route('settings.hotels.mode'), ['multi' => 0])->assertRedirect();
    expect(\App\Support\HotelSettings::group()['multi_hotel'])->toBeFalse();
});

test('the staff app works in the hotel it sends', function () {
    if (! class_exists(\Laravel\Sanctum\Sanctum::class)) {
        $this->markTestSkipped('Laravel Sanctum is not installed.');
    }
    $this->clerkB->forceFill(['username' => 'abuja.clerk', 'password' => bcrypt('Secret@123')])->save();
    $this->clerkB->hotels()->sync([$this->hotelA->id, $this->hotelB->id]);
    $login = $this->postJson('/api/v1/auth/login', ['login' => 'abuja.clerk', 'password' => 'Secret@123'])->assertOk();
    expect($login->json('data.user.multi_hotel'))->toBeTrue()->and(collect($login->json('data.user.hotels'))->pluck('id')->all())->toContain($this->hotelB->id);

    $token = $login->json('data.token');
    app('auth')->forgetGuards();
    $rooms = fn (?int $hotel) => $this->withToken($token)->withHeaders($hotel ? ['X-Hotel' => (string) $hotel] : [])->getJson('/api/v1/rooms')->assertOk()->json('data');
    expect(collect($rooms($this->hotelB->id))->pluck('type')->unique()->values()->all())->toBe(['Deluxe Abuja']);
    app('auth')->forgetGuards();
    expect(collect($rooms($this->hotelA->id))->pluck('type')->all())->not->toContain('Deluxe Abuja');
});

test('owners see the group overview; hotel staff do not', function () {
    $this->actingAs($this->admin)->get(route('group.index'))->assertOk()->assertSee('B Luxury Abuja')->assertSee($this->hotelA->name);
    $this->actingAs($this->clerkB)->get(route('group.index'))->assertForbidden();
});

test('guests search every hotel on the group site and book one', function () {
    \Illuminate\Support\Facades\Mail::fake();
    auth()->logout();
    $in = today()->addDays(20)->toDateString();
    $out = today()->addDays(22)->toDateString();

    $this->get(route('hotels.public'))->assertOk()->assertSee('B Luxury Abuja')->assertSee($this->hotelA->name);
    $this->get(route('hotels.public', ['where' => 'Abuja', 'check_in' => $in, 'check_out' => $out, 'adults' => 2]))
        ->assertOk()->assertSee('B Luxury Abuja')->assertDontSee($this->hotelA->name . '</a>', false)->assertSee('₦100,000');
    $this->get(route('hotels.public.show', ['hotelSlug' => $this->hotelB->slug, 'check_in' => $in, 'check_out' => $out]))
        ->assertRedirect(route('book.search', ['hotel' => $this->hotelB->slug, 'check_in' => $in, 'check_out' => $out]));
    $this->get(route('book.search', ['hotel' => $this->hotelB->slug, 'check_in' => $in, 'check_out' => $out, 'adults' => 2]))->assertOk()->assertSee('Deluxe Abuja')->assertDontSee('Executive');

    // The guest app: the hotels, then a booking at one of them.
    $list = $this->getJson('/api/v1/guest/hotels?' . http_build_query(['check_in' => $in, 'check_out' => $out, 'adults' => 2]))->assertOk();
    $b = collect($list->json('data'))->firstWhere('slug', $this->hotelB->slug);
    expect($b['bookable'])->toBe(1)->and((float) $b['from'])->toBe(100000.0);
    $this->getJson('/api/v1/guest/booking?hotel=' . $this->hotelB->slug)->assertOk()->assertJsonPath('data.hotel.name', 'B Luxury Abuja');

    $res = $this->postJson('/api/v1/guest/bookings', ['hotel' => $this->hotelB->slug, 'room_type_id' => $this->typeB->id, 'check_in' => $in, 'check_out' => $out,
        'adults' => 2, 'rooms' => 1, 'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi@example.com', 'phone' => '08031112222', 'accept' => true])->assertCreated();
    $r = Reservation::withoutGlobalScope('hotel')->where('reference', $res->json('data.reference'))->first();
    expect($r->hotel_id)->toBe($this->hotelB->id)
        ->and(Guest::withoutGlobalScope('hotel')->where('email', 'ngozi@example.com')->value('hotel_id'))->toBe($this->hotelB->id);

    // Their booking opens from its link whichever hotel it is at.
    $this->getJson('/api/v1/guest/stays/' . $res->json('data.token'))->assertOk()->assertJsonPath('data.hotel.name', 'B Luxury Abuja');
});

test('group-site bookings carry commission, owed once the guest has stayed', function () {
    \Illuminate\Support\Facades\Mail::fake();
    \App\Support\HotelSettings::putGroup('commission_percent', 10);
    \App\Support\HotelSettings::apply();
    $this->hotelB->update(['commission_percent' => 12.5]);
    $in = today()->addDays(20)->toDateString();
    $out = today()->addDays(22)->toDateString();
    $guest = ['room_type_id' => $this->typeB->id, 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'rooms' => 1,
        'first_name' => 'Ngozi', 'last_name' => 'Eze', 'email' => 'ngozi@example.com', 'phone' => '08031112222', 'accept' => true];

    // From the hotel list in the guest app: commission. Straight to the hotel: none.
    $via = $this->postJson('/api/v1/guest/bookings', $guest + ['hotel' => $this->hotelB->slug, 'via' => 'group'])->assertCreated();
    $direct = $this->postJson('/api/v1/guest/bookings', array_merge($guest, ['hotel' => $this->hotelB->slug, 'email' => 'direct@example.com', 'check_in' => today()->addDays(30)->toDateString(), 'check_out' => today()->addDays(31)->toDateString()]))->assertCreated();
    $r = Reservation::withoutGlobalScope('hotel')->where('reference', $via->json('data.reference'))->first();
    expect($r->via_group)->toBeTrue()->and((float) $r->commission_percent)->toBe(12.5)->and((float) $r->commission_amount)->toBe(12500.0)
        ->and(Reservation::withoutGlobalScope('hotel')->where('reference', $direct->json('data.reference'))->value('via_group'))->toBeFalse();

    // The web group site does the same through its hotel link.
    $this->get(route('hotels.public.show', ['hotelSlug' => $this->hotelB->slug]))->assertRedirect();
    expect(session('book_via_group'))->toBe($this->hotelB->slug);

    // Once checked out it shows on the month's commission; cancelled stays carry none.
    $r->forceFill(['status' => \App\Enums\ReservationStatus::CheckedOut, 'check_out_date' => today()])->save();
    $this->actingAs($this->admin)->get(route('group.commission'))->assertOk()->assertSee('₦12,500.00');
    $this->post(route('group.commission.pay'), ['hotel_id' => $this->hotelB->id, 'period' => today()->format('Y-m'), 'amount' => 12500, 'received_on' => today()->toDateString()])->assertRedirect();
    $this->get(route('group.commission'))->assertSee('Settled');
    $this->get(route('group.statement', ['hotel' => $this->hotelB, 'month' => today()->format('Y-m')]))->assertOk()->assertSee($r->reference);

    $r->forceFill(['status' => \App\Enums\ReservationStatus::Cancelled])->save();
    expect((float) $r->fresh()->commission_amount)->toBe(0.0);
});

test('group reports put the hotels side by side, with a CSV', function () {
    $this->actingAs($this->admin);
    CurrentHotel::get()->run($this->hotelB, fn () => \App\Models\NightAudit::create(['business_date' => today()->subDay(), 'closed_at' => now(),
        'report' => ['rooms' => ['sold' => 1, 'sellable' => 1, 'revenue' => 50000], 'revenue' => ['total' => 61000], 'collections' => ['total' => 40000], 'guests' => ['no_shows' => 0]]]));
    $this->get(route('group.reports', ['from' => today()->subDays(3)->toDateString(), 'to' => today()->toDateString()]))
        ->assertOk()->assertSee('B Luxury Abuja')->assertSee('₦61,000')->assertSee('100%');
    $csv = $this->get(route('group.reports', ['from' => today()->subDays(3)->toDateString(), 'to' => today()->toDateString(), 'export' => 'csv']))->assertOk()->streamedContent();
    expect($csv)->toContain('Occupancy %')->toContain('B Luxury Abuja');
    $this->actingAs($this->clerkB)->get(route('group.reports'))->assertForbidden();
});

test('owners see every hotel in the staff app', function () {
    if (! class_exists(\Laravel\Sanctum\Sanctum::class)) {
        $this->markTestSkipped('Laravel Sanctum is not installed.');
    }
    $this->admin->forceFill(['username' => 'owner.test', 'password' => bcrypt('Secret@123'), 'must_change_password' => false])->save();
    $login = $this->postJson('/api/v1/auth/login', ['login' => 'owner.test', 'password' => 'Secret@123'])->assertOk();
    expect($login->json('data.user.modules'))->toContain('group');
    app('auth')->forgetGuards();
    $rows = $this->withToken($login->json('data.token'))->getJson('/api/v1/group')->assertOk()->json('data');
    expect(collect($rows)->pluck('name')->all())->toContain('B Luxury Abuja', $this->hotelA->name);
});
