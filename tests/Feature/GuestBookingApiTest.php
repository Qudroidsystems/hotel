<?php

use App\Models\Rates\RatePlan;
use App\Models\Reservation;
use App\Models\RoomType;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class]);
    $this->withoutDefer();
    Mail::fake();
    $this->exe = RoomType::where('code', 'EXE')->first();   // ₦65,000
    $this->in = today()->addDays(40)->next(Carbon::MONDAY);
    $this->out = $this->in->copy()->addDays(2);
    $this->q = ['check_in' => $this->in->toDateString(), 'check_out' => $this->out->toDateString(), 'adults' => 2, 'rooms' => 1];
});

test('the guest app sees room types and booking rules', function () {
    $this->getJson('/api/v1/guest/booking')->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonStructure(['data' => ['max_rooms', 'deposit_percent', 'room_types' => [['id', 'name', 'capacity', 'from_label', 'photos']]]]);
});

test('searching shows online rate plans, and a promo code unlocks its plan', function () {
    RatePlan::create(['name' => 'Bed & breakfast', 'code' => 'BB', 'discount_percent' => 0, 'nightly_extra' => 8000, 'online' => true]);
    RatePlan::create(['name' => 'Desk only', 'code' => 'DO', 'discount_percent' => 50, 'online' => false, 'front_desk' => true]);
    RatePlan::create(['name' => 'Launch', 'code' => 'LAUNCH', 'discount_percent' => 20, 'online' => true, 'front_desk' => false, 'promo_code' => 'BLUX20']);

    $exe = fn ($res) => collect($res->json('data'))->firstWhere('id', $this->exe->id);
    $plain = $this->getJson('/api/v1/guest/search?' . http_build_query($this->q))->assertOk();
    expect(collect($exe($plain)['rates'])->pluck('name')->all())->toContain('Room only', 'Bed & breakfast')->not->toContain('Desk only', 'Launch');
    expect($plain->json('meta.nights'))->toBe(2);

    $promo = $this->getJson('/api/v1/guest/search?' . http_build_query($this->q + ['promo' => ' blux20 ']))->assertOk()
        ->assertJsonPath('meta.promo', 'BLUX20')->assertJsonPath('meta.promo_applied', true)->assertJsonPath('meta.promo_problem', null);
    $launch = collect($exe($promo)['rates'])->firstWhere('name', 'Launch');
    expect($launch['promo'])->toBeTrue()
        ->and((float) $launch['total'])->toBe(round(65000 * 2 * 0.8, 2));

    $this->getJson('/api/v1/guest/search?' . http_build_query($this->q + ['promo' => 'NOPE']))->assertOk()
        ->assertJsonPath('meta.promo_applied', false);
    expect($this->getJson('/api/v1/guest/search?' . http_build_query($this->q + ['promo' => 'NOPE']))->json('meta.promo_problem'))->toContain('isn’t valid');

    $this->getJson('/api/v1/guest/search?' . http_build_query(['check_in' => today()->subDay()->toDateString(), 'check_out' => today()->addDay()->toDateString()]))
        ->assertStatus(422)->assertJsonPath('success', false);
});

test('booking from the app keeps the rate plan and promo code', function () {
    $plan = RatePlan::create(['name' => 'Launch', 'code' => 'LAUNCH', 'discount_percent' => 20, 'online' => true, 'front_desk' => false, 'promo_code' => 'BLUX20']);
    $guest = ['first_name' => 'Ada', 'last_name' => 'Obi', 'email' => 'ada@example.com', 'phone' => '08031234567', 'accept' => true, 'room_type_id' => $this->exe->id];

    $res = $this->postJson('/api/v1/guest/bookings', $this->q + $guest + ['rate_plan_id' => $plan->id, 'promo' => 'blux20'])->assertCreated();
    $r = Reservation::where('reference', $res->json('data.reference'))->first();
    expect($r->rate_plan_id)->toBe($plan->id)
        ->and($r->promo_code)->toBe('BLUX20')
        ->and((float) $r->total_amount)->toBeGreaterThan(0)->toBeLessThan(65000 * 2);
    $this->getJson('/api/v1/guest/stays/' . $res->json('data.token'))->assertOk()->assertJsonPath('data.reference', $r->reference);

    // A promo plan without its code can't be booked.
    $this->postJson('/api/v1/guest/bookings', $this->q + $guest + ['rate_plan_id' => $plan->id])->assertStatus(422)->assertJsonPath('code', 'rate_gone');
    // Policies must be accepted.
    $this->postJson('/api/v1/guest/bookings', ['accept' => false] + $this->q + $guest)->assertStatus(422);
});
