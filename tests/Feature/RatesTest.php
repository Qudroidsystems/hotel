<?php

use App\Models\Rates\RateDay;
use App\Models\Rates\RatePlan;
use App\Models\Rates\RateSeason;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rates\RateService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class]);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    $this->withoutDefer();
    $this->exe = RoomType::where('code', 'EXE')->first();   // ₦65,000, no weekend rate
    $this->mon = today()->addDays(40)->next(Carbon::MONDAY);
    $this->engine = app(RateService::class);
});

test('seasons, the rate calendar and plans set each night’s price', function () {
    $m = $this->mon;
    RateSeason::create(['name' => 'Peak', 'starts_on' => $m->copy()->addDay(), 'ends_on' => $m->copy()->addDays(2), 'change_percent' => 20, 'change_amount' => 0]);
    RateDay::create(['room_type_id' => $this->exe->id, 'date' => $m->copy()->addDays(2)->toDateString(), 'price' => 50000]);
    $bb = RatePlan::create(['name' => 'Bed & breakfast', 'code' => 'BB', 'discount_percent' => 10, 'nightly_extra' => 8000]);

    $q = $this->engine->quote($this->exe, $m, $m->copy()->addDays(3));
    expect(array_values($q))->toBe([65000.0, 78000.0, 50000.0]);

    $q = $this->engine->quote($this->exe, $m, $m->copy()->addDays(3), null, $bb);
    expect(array_values($q))->toBe([66500.0, 78200.0, 53000.0]);   // 10% off, + 8,000

    $fixed = RatePlan::create(['name' => 'Long stay', 'code' => 'LS', 'fixed_prices' => [$this->exe->id => 45000]]);
    expect(array_values($this->engine->quote($this->exe, $m, $m->copy()->addDays(2), null, $fixed)))->toBe([45000.0, 45000.0]);
});

test('a plan’s rules decide where and when it can be used', function () {
    $m = $this->mon;
    $plan = RatePlan::create(['name' => 'Stay 3', 'code' => 'S3', 'discount_percent' => 15, 'min_nights' => 3, 'online' => true, 'front_desk' => true]);
    $promo = RatePlan::create(['name' => 'Launch', 'code' => 'LAUNCH', 'discount_percent' => 20, 'online' => true, 'front_desk' => false, 'promo_code' => 'BLUX20']);

    expect($this->engine->problems($plan, $this->exe, $m, $m->copy()->addDays(2), RateService::ONLINE))->not->toBeEmpty()
        ->and($this->engine->problems($plan, $this->exe, $m, $m->copy()->addDays(3), RateService::ONLINE))->toBe([])
        ->and($this->engine->problems($promo, $this->exe, $m, $m->copy()->addDay(), RateService::ONLINE))->not->toBeEmpty()
        ->and($this->engine->problems($promo, $this->exe, $m, $m->copy()->addDay(), RateService::ONLINE, 'blux20'))->toBe([])
        ->and($this->engine->problems($promo, $this->exe, $m, $m->copy()->addDay(), RateService::FRONT_DESK))->not->toBeEmpty();

    // Closed nights and minimum stays only stop online sales.
    RateDay::create(['room_type_id' => $this->exe->id, 'date' => $m->copy()->addDay()->toDateString(), 'closed' => true]);
    expect($this->engine->restriction($this->exe, $m, $m->copy()->addDays(2)))->toContain('aren’t available online');
});

test('the front desk books on a rate plan and the booking keeps it', function () {
    $m = $this->mon;
    $plan = RatePlan::create(['name' => 'Corporate', 'code' => 'CORP', 'discount_percent' => 15]);

    $json = $this->getJson(route('reservations.availability', ['in' => $m->toDateString(), 'out' => $m->copy()->addDays(2)->toDateString(), 'plan' => $plan->id]))->assertOk()->json();
    $exe = collect($json['types'])->firstWhere('id', $this->exe->id);
    expect($exe['amount'])->toEqual(110500)->and($json['plan']['name'])->toBe('Corporate');

    $this->post(route('reservations.store'), [
        'check_in_date' => $m->toDateString(), 'check_out_date' => $m->copy()->addDays(2)->toDateString(), 'source' => 'walk_in', 'status' => 'confirmed',
        'rate_plan_id' => $plan->id, 'rooms' => [['room_type_id' => $this->exe->id, 'adults' => 1]],
        'guest' => ['first_name' => 'Ada', 'last_name' => 'Obi', 'phone' => '08031234567'],
    ])->assertRedirect();
    $r = Reservation::latest('id')->first();
    expect($r->rate_plan_name)->toBe('Corporate')->and((float) $r->room_total)->toEqual(110500.0);

    // Extending the stay prices the new night on the same rate.
    app(\App\Services\Reservations\ReservationService::class)->changeDates($r, $m, $m->copy()->addDays(3));
    expect((float) $r->fresh()->room_total)->toEqual(165750.0);
    $this->get(route('reservations.show', $r))->assertOk()->assertSee('Corporate');
});

test('online guests see promo rates only with the code, and closed nights can’t be booked', function () {
    $m = $this->mon;
    RatePlan::create(['name' => 'Bed & breakfast', 'code' => 'BB', 'nightly_extra' => 8000, 'inclusions' => 'Breakfast for two', 'online' => true]);
    $promo = RatePlan::create(['name' => 'Launch offer', 'code' => 'LAUNCH', 'discount_percent' => 20, 'online' => true, 'front_desk' => false, 'promo_code' => 'BLUX20']);
    $q = ['check_in' => $m->toDateString(), 'check_out' => $m->copy()->addDays(2)->toDateString(), 'adults' => 2, 'children' => 0, 'rooms' => 1];

    $this->get(route('book.search', $q))->assertOk()->assertSee('Bed &amp; breakfast', false)->assertSee('Breakfast for two')->assertDontSee('Launch offer');
    $this->get(route('book.search', $q + ['promo' => 'BLUX20']))->assertOk()->assertSee('Launch offer')->assertSee('Promo code BLUX20 applied');
    $this->get(route('book.search', $q + ['promo' => 'NOPE']))->assertOk()->assertSee('isn’t valid');

    $this->get(route('book.details', $q + ['promo' => 'BLUX20', 'type' => $this->exe->id, 'rate' => $promo->id]))->assertOk()->assertSee('Launch offer')->assertSee('104,000.00');
    $this->post(route('book.store'), $q + ['promo' => 'BLUX20', 'room_type_id' => $this->exe->id, 'rate_plan_id' => $promo->id, 'first_name' => 'Tobi', 'last_name' => 'Ade',
        'email' => 'tobi@example.com', 'phone' => '08031112222', 'accept' => 1, 'pay' => 'later'])->assertRedirect();
    $r = Reservation::latest('id')->first();
    expect($r->rate_plan_name)->toBe('Launch offer')->and($r->promo_code)->toBe('BLUX20')->and((float) $r->room_total)->toEqual(104000.0);

    RateDay::create(['room_type_id' => $this->exe->id, 'date' => $m->copy()->addDays(10)->toDateString(), 'closed' => true]);
    $closed = ['check_in' => $m->copy()->addDays(10)->toDateString(), 'check_out' => $m->copy()->addDays(11)->toDateString(), 'adults' => 1, 'children' => 0, 'rooms' => 1];
    $this->post(route('book.store'), $closed + ['room_type_id' => $this->exe->id, 'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com', 'phone' => '08030000000', 'accept' => 1, 'pay' => 'later'])
        ->assertSessionHas('error');
});

test('managers set up plans, seasons and the calendar; others can’t', function () {
    $this->get(route('rates.index'))->assertOk()->assertSee('Standard rate');
    $this->get(route('rates.plans.create'))->assertOk();
    $this->post(route('rates.plans.store'), ['name' => 'Weekend getaway', 'code' => 'wkend', 'discount_percent' => 10, 'arrival_days' => [5, 6], 'min_nights' => 2, 'online' => 1, 'front_desk' => 1, 'is_active' => 1])
        ->assertRedirect(route('rates.index', ['tab' => 'plans']));
    $plan = RatePlan::where('code', 'WKEND')->first();
    expect($plan->arrival_days)->toBe([5, 6])->and($plan->summary)->toContain('10% off');

    $this->post(route('rates.seasons.store'), ['name' => 'Detty December', 'starts_on' => '2026-12-15', 'ends_on' => '2027-01-02', 'change_percent' => 30, 'min_nights' => 3])->assertRedirect();
    $this->get(route('rates.index', ['tab' => 'seasons']))->assertOk()->assertSee('Detty December')->assertSee('+30%');

    $from = $this->mon->toDateString();
    $this->post(route('rates.calendar.update'), ['from' => $from, 'to' => $this->mon->copy()->addDays(6)->toDateString(), 'room_type_ids' => [$this->exe->id], 'weekdays' => [5, 6], 'action' => 'price', 'price' => 99000])
        ->assertSessionHas('success');
    expect(RateDay::where('room_type_id', $this->exe->id)->count())->toBe(2);
    $this->get(route('rates.index', ['tab' => 'calendar', 'month' => $this->mon->format('Y-m')]))->assertOk()->assertSee('99k');
    $this->getJson(route('rates.check', ['type' => $this->exe->id, 'in' => $from, 'out' => $this->mon->copy()->addDays(5)->toDateString(), 'plan' => $plan->id]))
        ->assertOk()->assertJsonPath('problems.0', fn ($p) => str_contains($p, 'arrivals on'));

    $fd = User::create(['name' => 'FD', 'email' => 'fd@test.local', 'password' => bcrypt('Secret@123')]);
    $fd->assignRole('Front Desk');
    $this->actingAs($fd)->get(route('rates.index'))->assertForbidden();
});
