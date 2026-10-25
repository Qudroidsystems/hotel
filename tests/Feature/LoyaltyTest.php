<?php

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Loyalty\LoyaltyEntry;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Reservations\ReservationService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    config(['hotel.loyalty.points_per_1000' => 10, 'hotel.loyalty.point_value' => 1, 'hotel.loyalty.min_redeem' => 100]);
});

/** Check out an in-house stay and pay its bill in full. */
function settleStay(Reservation $r): Reservation
{
    $folios = app(FolioService::class);
    $folio = $folios->forReservation($r);
    $folios->postRoomNights($r);
    $folios->receivePayment($folio, PaymentKind::Payment, PaymentMethod::Cash, max(1, $folio->balance() + 1000));
    app(ReservationService::class)->checkOut($r->fresh());
    $folio->refresh();
    // The 1,000 overpaid comes back to the guest, which settles the bill.
    $folios->receivePayment($folio, PaymentKind::Refund, PaymentMethod::Cash, 1000);

    return $r->fresh(['folio', 'guest']);
}

test('a settled stay earns points on the bill before tax, once, and gives a member number', function () {
    $r = Reservation::where('status', ReservationStatus::CheckedIn->value)->first();
    $r = settleStay($r);
    expect($r->folio->status)->toBe('closed');

    $spend = (float) $r->folio->charges()->whereNull('voided_at')->sum('amount');
    $guest = $r->guest->fresh();
    expect($guest->loyalty_points)->toBe((int) floor($spend / 1000 * 10))
        ->and($guest->loyalty_number)->toStartWith('SH')
        ->and(LoyaltyEntry::where('reservation_id', $r->id)->count())->toBe(1);

    // Running it again (scheduler, a second save) changes nothing.
    app(LoyaltyService::class)->syncEarn($r->folio);
    expect($guest->fresh()->loyalty_points)->toBe($guest->loyalty_points);
});

test('reopening the bill holds the points until it is settled again', function () {
    $r = settleStay(Reservation::where('status', ReservationStatus::CheckedIn->value)->first());
    $earned = $r->guest->fresh()->loyalty_points;
    expect($earned)->toBeGreaterThan(0);

    app(FolioService::class)->postCharge($r->folio, \App\Enums\ChargeType::Minibar, 'Found after check-out', 1, 5000);
    expect($r->folio->fresh()->status)->toBe('open')
        ->and($r->guest->fresh()->loyalty_points)->toBe(0);

    app(FolioService::class)->receivePayment($r->folio->fresh(), PaymentKind::Payment, PaymentMethod::Cash, $r->folio->fresh()->balance());
    expect($r->guest->fresh()->loyalty_points)->toBeGreaterThan($earned);
});

test('points pay part of a bill, cannot overpay it, and come back when the line is voided', function () {
    $r = Reservation::where('status', ReservationStatus::CheckedIn->value)->first();
    $folios = app(FolioService::class);
    $folio = $folios->forReservation($r);
    $folios->postCharge($folio, \App\Enums\ChargeType::Service, 'Airport pickup', 1, 10000);
    app(LoyaltyService::class)->adjust($r->guest, 3000, 'Welcome gift', $this->admin);

    $before = $folio->balance();
    $this->post(route('folios.loyalty', $folio), ['points' => 99999])->assertSessionHas('error');
    $this->post(route('folios.loyalty', $folio), ['points' => 50])->assertSessionHas('error');   // under the minimum
    $this->post(route('folios.loyalty', $folio), ['points' => 2000])->assertSessionHas('success');

    expect($folio->fresh()->balance())->toBe(round($before - 2000, 2))
        ->and($r->guest->fresh()->loyalty_points)->toBe(1000);

    $line = $folio->charges()->where('source_type', LoyaltyEntry::class)->first();
    $folios->voidCharge($line, 'Guest changed their mind');
    expect($r->guest->fresh()->loyalty_points)->toBe(3000);
    // The points come back once only.
    app(LoyaltyService::class)->chargeVoided($line->fresh());
    expect($r->guest->fresh()->loyalty_points)->toBe(3000);
});

test('tiers follow nights in the last 12 months and give the online member rate', function () {
    config(['hotel.loyalty.silver_nights' => 1, 'hotel.loyalty.gold_nights' => 50, 'hotel.loyalty.silver_discount' => 10]);
    $r = settleStay(Reservation::where('status', ReservationStatus::CheckedIn->value)->first());
    $guest = $r->guest->fresh();
    expect($guest->loyalty_tier)->toBe('silver');

    $type = \App\Models\RoomType::where('is_active', true)->first();
    $booking = app(\App\Services\Booking\OnlineBookingService::class)->book([
        'check_in' => today()->addDays(40)->toDateString(), 'check_out' => today()->addDays(42)->toDateString(),
        'adults' => 1, 'children' => 0, 'rooms' => 1, 'room_type_id' => $type->id,
        'first_name' => $guest->first_name, 'last_name' => $guest->last_name, 'email' => strtoupper((string) $guest->email), 'phone' => $guest->phone ?: '08030000000',
    ]);
    expect($booking->guest_id)->toBe($guest->id)
        ->and((float) $booking->discount_amount)->toBe(round((float) $booking->room_total * 0.10, 2))
        ->and($booking->discount_reason)->toContain('Silver member rate');
});

test('staff without the permission cannot adjust points, and the profile shows the card', function () {
    $guest = Guest::first();
    $desk = User::create(['name' => 'Desk', 'email' => 'desk@test.local', 'password' => bcrypt('Secret@123')]);
    $desk->assignRole('Housekeeping');
    $this->actingAs($desk)->post(route('guests.points', $guest), ['direction' => 'add', 'points' => 10, 'reason' => 'x'])->assertForbidden();

    $this->actingAs($this->admin)->post(route('guests.points', $guest), ['direction' => 'add', 'points' => 250, 'reason' => 'Goodwill'])->assertSessionHas('success');
    $this->get(route('guests.show', $guest))->assertOk()->assertSee('250')->assertSee('Points history')->assertSee($guest->fresh()->loyalty_number);
    $this->get(route('guests.index', ['q' => $guest->fresh()->loyalty_number]))->assertOk()->assertSee($guest->first_name);
});
