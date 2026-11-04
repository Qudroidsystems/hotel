<?php

use App\Enums\ReservationStatus;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Wellness\WellnessBooking;
use App\Models\Wellness\WellnessService;
use App\Models\Wellness\WellnessTherapist;
use App\Models\Wellness\WellnessVenue;
use App\Services\Booking\OnlineBookingService;
use App\Services\Mobile\StaffActions;
use App\Services\Wellness\WellnessBookings;
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
    Mail::fake();
    $this->travelTo(today()->setTime(8, 0));   // a morning, so the day's times are ahead
    $this->spa = WellnessVenue::create(['name' => 'Spa', 'kind' => 'spa', 'opens' => '09:00', 'closes' => '18:00', 'capacity' => 2]);
    $this->massage = WellnessService::create(['venue_id' => $this->spa->id, 'name' => 'Swedish massage', 'minutes' => 60, 'price' => 25000, 'visitor_price' => 30000]);
    $this->t1 = WellnessTherapist::create(['venue_id' => $this->spa->id, 'name' => 'Ifeoma']);
    $this->t2 = WellnessTherapist::create(['venue_id' => $this->spa->id, 'name' => 'Grace']);
    $this->gym = WellnessVenue::create(['name' => 'Gym', 'kind' => 'gym', 'opens' => '06:00', 'closes' => '22:00', 'capacity' => 3, 'session_minutes' => 60, 'guest_price' => 0, 'visitor_price' => 5000]);
    $this->r = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('guest', 'rooms.room')->first();
    $this->r->update(['check_out_date' => today()->addDays(3)]);
    $this->token = app(OnlineBookingService::class)->ensureToken($this->r);
    $this->desk = User::create(['name' => 'Desk', 'email' => 'desk@test.local', 'password' => bcrypt('Secret@123')]);
    $this->desk->assignRole('Front Desk');
    $this->at = fn (string $t) => today()->toDateString() . 'T' . $t . ':00';
    $this->charges = fn () => (float) (Folio::where('reservation_id', $this->r->id)->first()?->charges()->whereNull('voided_at')->where('source_type', WellnessBooking::class)->sum('total') ?? 0);
});

test('a guest books a treatment in the app; each therapist takes one at a time', function () {
    $screen = $this->getJson("/api/v1/guest/stays/{$this->token}/wellness")->assertOk()->json('data');
    expect($screen['available'])->toBeTrue()->and(collect($screen['venues'])->pluck('name')->sort()->values()->all())->toBe(['Gym', 'Spa'])
        ->and(collect($screen['venues'])->firstWhere('name', 'Spa')['services'][0]['price'])->toEqual(25000)   // the guest price, not the visitor's
        ->and($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.can_wellness'))->toBeTrue();

    $slots = $this->getJson("/api/v1/guest/stays/{$this->token}/wellness/slots?venue_id={$this->spa->id}&service_id={$this->massage->id}&date=" . today()->toDateString())->json('data.slots');
    expect(collect($slots)->firstWhere('time', '10:00')['left'])->toBe(2);

    $book = fn () => $this->postJson("/api/v1/guest/stays/{$this->token}/wellness", ['venue_id' => $this->spa->id, 'service_id' => $this->massage->id, 'starts_at' => ($this->at)('10:00')]);
    $book()->assertCreated();
    $book()->assertCreated();
    $book()->assertStatus(422);   // both therapists are busy at 10
    expect(WellnessBooking::pluck('therapist_id')->sort()->values()->all())->toBe([$this->t1->id, $this->t2->id])
        ->and(collect($this->getJson("/api/v1/guest/stays/{$this->token}/wellness/slots?venue_id={$this->spa->id}&service_id={$this->massage->id}&date=" . today()->toDateString())->json('data.slots'))->pluck('time'))
        ->not->toContain('10:00')->not->toContain('09:30')->toContain('11:00')
        ->and($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.wellness'))->toHaveCount(2);
});

test('arriving puts an in-house guest’s treatment on the room bill once; done follows', function () {
    $b = app(WellnessBookings::class)->bookForStay($this->r, $this->spa, $this->massage, Carbon::parse(($this->at)('11:00')));
    $before = ($this->charges)();
    $this->actingAs($this->desk)->get(route('wellness.index'))->assertOk()->assertSee($b->number);
    $this->post(route('wellness.act', [$b, 'arrive']))->assertSessionHas('success');
    expect($b->fresh()->status)->toBe('arrived')->and(($this->charges)())->toBe($before + 25000.0);
    $this->post(route('wellness.act', [$b, 'arrive']))->assertSessionHas('error');
    expect(($this->charges)())->toBe($before + 25000.0);
    $this->post(route('wellness.act', [$b, 'complete']))->assertSessionHas('success');
    expect($b->fresh()->status)->toBe('completed');
});

test('gym sessions fill up to the venue’s capacity, and are free for guests', function () {
    $svc = app(WellnessBookings::class);
    $svc->bookForStay($this->r, $this->gym, null, Carbon::parse(($this->at)('10:00')), 2);
    $b = $svc->deskBook($this->desk, $this->gym, null, Carbon::parse(($this->at)('10:00')), 1, null, ['name' => 'Kunle', 'phone' => '0803'], null);
    expect((float) $b->total)->toBe(5000.0)->and($b->payment)->toBe('desk');
    expect(fn () => $svc->deskBook($this->desk, $this->gym, null, Carbon::parse(($this->at)('10:00')), 1, null, ['name' => 'Ada'], null))->toThrow(\App\Exceptions\BookingException::class);
    $free = $svc->bookForStay($this->r, $this->gym, null, Carbon::parse(($this->at)('11:00')), 1);
    expect($free->payment)->toBe('free')->and((float) $free->total)->toBe(0.0);
});

test('a visitor books on the website and pays online; the time is held until it is paid, and let go if not', function () {
    config(['services.paystack.secret_key' => 'sk_test_x', 'services.paystack.active' => true]);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 3000000, 'currency' => 'NGN', 'channel' => 'card', 'paid_at' => now()->toIso8601String()]])]);
    $this->get(route('wellness.public'))->assertOk()->assertSee('Swedish massage')->assertSee('30,000.00');
    $this->post(route('wellness.book'), ['venue_id' => $this->spa->id, 'service_id' => $this->massage->id, 'date' => today()->toDateString(), 'time' => '14:00', 'name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '0803'])
        ->assertRedirect('https://checkout.paystack.com/abc');
    $b = WellnessBooking::latest('id')->first();
    expect($b->status)->toBe('pending')->and((float) $b->total)->toBe(30000.0)->and($b->gateway_reference)->toStartWith('WB');

    $this->get(route('wellness.callback', ['reference' => $b->gateway_reference]))->assertRedirect(route('wellness.mine', $b->token));
    expect($b->fresh()->status)->toBe('booked')->and($b->fresh()->paid_at)->not->toBeNull();
    $this->get(route('wellness.mine', $b->token))->assertOk()->assertSee('paid online');

    // An unpaid one lets its time go.
    $this->post(route('wellness.book'), ['venue_id' => $this->spa->id, 'service_id' => $this->massage->id, 'date' => today()->toDateString(), 'time' => '15:00', 'name' => 'Bola', 'email' => 'bola@example.com', 'phone' => '0803']);
    $this->travel(25)->minutes();
    expect(app(WellnessBookings::class)->expire())->toBe(1)->and(WellnessBooking::latest('id')->first()->status)->toBe('expired');
});

test('guests cancel online up to the set hours before; staff cancel with a reason; the staff app runs the day', function () {
    $svc = app(WellnessBookings::class);
    $soon = $svc->bookForStay($this->r, $this->spa, $this->massage, Carbon::parse(($this->at)('09:00')));
    $later = $svc->bookForStay($this->r, $this->spa, $this->massage, Carbon::parse(($this->at)('16:00')));
    $this->postJson("/api/v1/guest/stays/{$this->token}/wellness/{$soon->id}/cancel")->assertStatus(422);   // within 2 hours
    $this->postJson("/api/v1/guest/stays/{$this->token}/wellness/{$later->id}/cancel")->assertOk();
    expect($later->fresh()->status)->toBe('cancelled');

    $actions = app(StaffActions::class);
    $item = collect($actions->work($this->desk))->firstWhere('key', "wellness:{$soon->id}");
    expect(collect($item['actions'])->pluck('id')->all())->toBe(['arrive', 'cancel']);
    $actions->perform($this->desk, 'wellness', $soon->id, 'arrive');
    $actions->perform($this->desk, 'wellness', $soon->id, 'complete');
    expect($soon->fresh()->status)->toBe('completed')->and(($this->charges)())->toBe(25000.0);

    $third = $svc->bookForStay($this->r, $this->spa, $this->massage, Carbon::parse(($this->at)('17:00')));
    $this->actingAs($this->desk)->post(route('wellness.act', [$third, 'cancel']))->assertSessionHas('error');
    $this->post(route('wellness.act', [$third, 'cancel']), ['note' => 'Therapist ill'])->assertSessionHas('success');
    expect(collect($this->getJson("/api/v1/guest/stays/{$this->token}/wellness")->json('data.bookings'))->firstWhere('id', $third->id)['note'])->toBe('Therapist ill');
});

test('only confirmed or in-house stays book, inside their dates; set-up is for managers', function () {
    $svc = app(WellnessBookings::class);
    expect(fn () => $svc->bookForStay($this->r, $this->spa, $this->massage, Carbon::parse(today()->addDays(10)->toDateString() . ' 10:00')))->toThrow(\App\Exceptions\BookingException::class);
    $gone = Reservation::where('status', ReservationStatus::CheckedIn->value)->where('id', '!=', $this->r->id)->first();
    $gone->update(['status' => ReservationStatus::CheckedOut]);
    expect($svc->blocker($gone->fresh()))->not->toBeNull();

    $this->actingAs($this->desk)->get(route('wellness.setup'))->assertForbidden();
    $gm = User::create(['name' => 'GM', 'email' => 'gm@test.local', 'password' => bcrypt('Secret@123')]);
    $gm->assignRole('General Manager');
    $this->actingAs($gm)->get(route('wellness.setup'))->assertOk()->assertSee('Swedish massage');
    $this->post(route('wellness.venues.store'), ['name' => 'Pool', 'kind' => 'pool', 'opens' => '07:00', 'closes' => '19:00', 'capacity' => 20, 'session_minutes' => 120, 'guest_price' => 0, 'visitor_price' => 7000, 'visitors' => 1])->assertSessionHas('success');
    $this->post(route('wellness.services.store', $this->spa), ['name' => 'Facial', 'minutes' => 45, 'price' => 20000])->assertSessionHas('success');
    $this->post(route('wellness.therapists.store', $this->spa), ['name' => 'Tolu'])->assertSessionHas('success');
    expect(WellnessVenue::where('name', 'Pool')->first()->visitor_price)->toEqual(7000)->and($this->spa->therapists()->count())->toBe(3);

    // The booking page for the stay.
    auth()->logout();
    $this->get(route('stay.wellness', $this->token))->assertOk()->assertSee('Your booking');
    $this->post(route('stay.wellness.book', $this->token), ['venue_id' => $this->spa->id, 'service_id' => $this->massage->id, 'date' => today()->toDateString(), 'time' => '12:00'])->assertRedirect(route('stay.wellness', $this->token));
    expect(WellnessBooking::latest('id')->first()->via)->toBe('web');
});
