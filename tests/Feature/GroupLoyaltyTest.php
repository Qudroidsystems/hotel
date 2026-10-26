<?php

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Loyalty\LoyaltyEntry;
use App\Models\User;
use App\Services\Loyalty\LoyaltyService;
use App\Support\HotelSettings;
use App\Support\Tenancy\CurrentHotel;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    HotelSettings::putGroup('multi_hotel', true);
    HotelSettings::apply();
    $this->hotelA = Hotel::orderBy('id')->first();
    $this->hotelB = Hotel::create(['name' => 'B Luxury Abuja', 'city' => 'Abuja']);
    $this->loyalty = app(LoyaltyService::class);
});

function guestAt(Hotel $hotel, array $data): Guest
{
    return CurrentHotel::get()->run($hotel, fn () => Guest::create($data + ['first_name' => 'Ada', 'last_name' => 'Obi']));
}

test('a member who stays at another hotel of the group keeps their number, points and tier', function () {
    $a = guestAt($this->hotelA, ['email' => 'ada@example.com', 'phone' => '0803 123 4567']);
    $this->loyalty->adjust($a, 800, 'Welcome', $this->admin);
    $a->refresh();

    // Hotel B: same person, matched by email (different case), joins the membership.
    $b = guestAt($this->hotelB, ['email' => 'ADA@example.com']);
    expect($b->fresh()->loyalty_number)->toBe($a->loyalty_number)->and($b->fresh()->loyalty_points)->toBe(800);

    // Spending in B comes off the shared balance; A sees it too.
    CurrentHotel::get()->run($this->hotelB, fn () => $this->loyalty->adjust($b->fresh(), -300, 'Used at Abuja', $this->admin));
    expect($a->fresh()->loyalty_points)->toBe(500)->and($b->fresh()->loyalty_points)->toBe(500);

    // The history on A's profile shows both hotels' lines.
    expect($this->loyalty->history($a->fresh())->pluck('points')->all())->toBe([-300, 800]);
    $this->get(route('guests.show', $a))->assertOk()->assertSee('Used at Abuja')->assertSee('B Luxury Abuja');
});

test('guests are matched by phone number too, and not across hotels when there is one hotel', function () {
    $a = guestAt($this->hotelA, ['phone' => '+234 803 123 4567']);
    $this->loyalty->ensureNumber($a);
    $b = guestAt($this->hotelB, ['phone' => '08031234567']);
    expect($b->fresh()->loyalty_number)->toBe($a->fresh()->loyalty_number);

    HotelSettings::putGroup('group_loyalty', false);
    HotelSettings::apply();
    $c = guestAt($this->hotelB, ['first_name' => 'Ada', 'phone' => '0803 123 4567', 'email' => 'other@example.com']);
    expect($c->fresh()->loyalty_number)->toBeNull();
});

test('nights at every hotel count towards the tier', function () {
    $tiers = fn () => config(['hotel.loyalty.silver_nights' => 3, 'hotel.loyalty.gold_nights' => 50]);   // (switching hotel reloads settings)
    $a = guestAt($this->hotelA, ['email' => 'ada@example.com']);
    $this->loyalty->ensureNumber($a);
    $b = guestAt($this->hotelB, ['email' => 'ada@example.com']);
    $stay = fn (Hotel $h, Guest $g, int $nights) => CurrentHotel::get()->run($h, fn () => \App\Models\Reservation::forceCreate([
        'guest_id' => $g->id, 'reference' => 'T' . uniqid(), 'status' => 'checked_out', 'source' => 'walk_in',
        'check_in_date' => today()->subDays(10), 'check_out_date' => today()->subDays(10 - $nights),
        'adults' => 1, 'children' => 0, 'total_amount' => 0, 'qr_token' => \Illuminate\Support\Str::random(32),
    ]));
    $stay($this->hotelA, $a, 2);
    $tiers();
    expect($this->loyalty->refreshTier($a->fresh()))->toBe('member');
    $stay($this->hotelB, $b->fresh(), 1);
    $tiers();
    expect($this->loyalty->refreshTier($a->fresh()))->toBe('silver')
        ->and($b->fresh()->loyalty_tier)->toBe('silver');
});

test('switching group loyalty on joins members who already had a number at each hotel', function () {
    HotelSettings::putGroup('group_loyalty', false);
    HotelSettings::apply();
    $a = guestAt($this->hotelA, ['email' => 'ada@example.com']);
    $this->loyalty->adjust($a, 400, 'A', $this->admin);
    $b = guestAt($this->hotelB, ['email' => 'ada@example.com']);
    CurrentHotel::get()->run($this->hotelB, fn () => $this->loyalty->adjust($b, 250, 'B', $this->admin));
    expect($b->fresh()->loyalty_number)->not->toBe($a->fresh()->loyalty_number);

    $this->post(route('settings.hotels.mode'), ['multi' => 1, 'group_loyalty' => 1])->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'points are now together'));
    $a->refresh();
    $b->refresh();
    expect($b->loyalty_number)->toBe($a->loyalty_number)
        ->and($a->loyalty_points)->toBe(650)->and($b->loyalty_points)->toBe(650)
        ->and(LoyaltyEntry::withoutGlobalScope('hotel')->where('guest_id', $b->id)->latest('id')->first()->balance_after)->toBe(650);

    // Doing it again changes nothing.
    expect($this->loyalty->linkAll())->toBe(0);
});
