<?php

use App\Enums\ReservationStatus;
use App\Models\Rates\RateDay;
use App\Models\Rates\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Rates\RateService;
use App\Services\Rates\RevenueService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class]);
    $this->actingAs(User::role('Super Admin')->first());
    $this->type = RoomType::where('is_active', true)->whereHas('rooms')->orderBy('id')->first();
    $this->rooms = Room::active()->where('room_type_id', $this->type->id)->count();
    config(['hotel.revenue' => ['high_occupancy' => 80, 'high_change' => 20, 'mid_occupancy' => 50, 'mid_change' => 10, 'low_days' => 7, 'low_occupancy' => 30, 'low_change' => 10, 'max_change' => 25, 'round_to' => 100, 'auto' => false]]);
});

/** Book $n rooms of the type for the night $days from today. */
function bookNight(RoomType $type, int $days, int $n): void
{
    $guest = \App\Models\Guest::create(['first_name' => 'Pace', 'last_name' => 'Test']);
    foreach (range(1, $n) as $i) {
        $r = Reservation::forceCreate([
            'guest_id' => $guest->id, 'reference' => 'RV' . uniqid(), 'status' => ReservationStatus::Confirmed->value, 'source' => 'walk_in',
            'check_in_date' => today()->addDays($days), 'check_out_date' => today()->addDays($days + 1), 'adults' => 1, 'children' => 0,
            'total_amount' => 0, 'qr_token' => \Illuminate\Support\Str::random(32),
        ]);
        ReservationRoom::create(['reservation_id' => $r->id, 'room_type_id' => $type->id, 'nightly_rates' => [], 'amount' => 0]);
    }
}

test('busy nights are suggested dearer, empty close-in nights cheaper, and applying sets the calendar', function () {
    bookNight($this->type, 20, $this->rooms);                    // full, far out
    $grid = app(RevenueService::class)->grid(30);
    $full = $grid['nights'][today()->addDays(20)->toDateString()][$this->type->id];
    $empty = $grid['nights'][today()->addDays(2)->toDateString()][$this->type->id];
    $plain = $grid['nights'][today()->addDays(15)->toDateString()][$this->type->id];
    expect($full['occupancy'])->toBe(100.0)->and($full['change'])->toEqual(20)->and($full['suggested'])->toBeGreaterThan($full['price'])
        ->and($full['pickup'])->toBe($this->rooms)
        ->and($empty['change'])->toEqual(-10)->and($empty['suggested'])->toBeLessThan($empty['price'])
        ->and($plain['differs'])->toBeFalse();

    $this->get(route('rates.revenue'))->assertOk()->assertSee('Suggested prices')->assertSee($this->type->name);
    $key = $this->type->id . '|' . today()->addDays(20)->toDateString();
    $this->post(route('rates.revenue.apply'), ['cells' => [$key]])->assertSessionHas('success');
    $day = RateDay::where('room_type_id', $this->type->id)->whereDate('date', today()->addDays(20))->first();
    expect((float) $day->price)->toBe($full['suggested'])->and($day->source)->toBe('suggested');

    // Running it again doesn't compound: the suggestion builds on the plain price.
    expect(app(RevenueService::class)->grid(30)['nights'][today()->addDays(20)->toDateString()][$this->type->id]['differs'])->toBeFalse();
});

test('nights priced by hand are left alone; automatic pricing runs only when switched on', function () {
    RateDay::create(['room_type_id' => $this->type->id, 'date' => today()->addDays(2)->toDateString(), 'price' => 99999]);
    $n = app(RevenueService::class)->apply();
    expect((float) RateDay::where('room_type_id', $this->type->id)->whereDate('date', today()->addDays(2))->first()->price)->toBe(99999.0)
        ->and($n)->toBeGreaterThan(0);

    RateDay::where('source', 'suggested')->delete();
    $this->artisan('rates:auto-price')->assertSuccessful();
    expect(RateDay::where('source', 'suggested')->count())->toBe(0);
    config(['hotel.revenue.auto' => true]);
    $this->artisan('rates:auto-price')->assertSuccessful();
    expect(RateDay::where('source', 'suggested')->count())->toBeGreaterThan(0);
});

test('a last-minute rate plan only sells close to arrival', function () {
    $plan = RatePlan::create(['name' => 'Last minute', 'code' => 'LAST', 'discount_percent' => 20, 'max_advance_days' => 3, 'online' => true, 'front_desk' => true, 'is_active' => true]);
    $rates = app(RateService::class);
    expect($rates->problems($plan, $this->type, today()->addDays(2), today()->addDays(3), RateService::ONLINE))->toBe([])
        ->and($rates->problems($plan, $this->type, today()->addDays(10), today()->addDays(11), RateService::ONLINE)[0])->toContain('within 3 days');
});
