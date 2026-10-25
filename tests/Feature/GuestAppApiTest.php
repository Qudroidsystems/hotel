<?php

use App\Enums\ReservationStatus;
use App\Models\Booking\GuestRequest;
use App\Models\Reservation;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
});

test('a guest opens their booking in the app with the reference and e-mail, but not with a wrong one', function () {
    $r = Reservation::where('status', ReservationStatus::Confirmed->value)->with('guest')->first();
    $r->guest->forceFill(['email' => 'ada@example.com'])->save();

    $this->postJson('/api/v1/guest/find', ['reference' => $r->reference, 'contact' => 'wrong@example.com'])->assertNotFound()->assertJsonPath('code', 'not_found');
    $res = $this->postJson('/api/v1/guest/find', ['reference' => strtolower($r->reference), 'contact' => 'ADA@example.com'])->assertOk();

    $token = $res->json('data.token');
    expect(strlen($token))->toBe(48)
        ->and($res->json('data.reference'))->toBe($r->reference)
        ->and($res->json('data.state'))->toBe('ok')
        ->and($res->json('data.qr'))->toContain('/r/')
        ->and($res->json('data.can_request'))->toBeFalse();

    $this->getJson("/api/v1/guest/stays/{$token}")->assertOk()->assertJsonPath('data.guest.first_name', $r->guest->first_name);
    $this->getJson('/api/v1/guest/stays/' . str_repeat('x', 48))->assertNotFound();
});

test('an in-house guest sends a request from the app; it reaches the right team', function () {
    $r = Reservation::where('status', ReservationStatus::CheckedIn->value)->first();
    $token = app(\App\Services\Booking\OnlineBookingService::class)->ensureToken($r);

    $stay = $this->getJson("/api/v1/guest/stays/{$token}")->assertOk()->json('data');
    expect($stay['can_request'])->toBeTrue()->and(collect($stay['request_types'])->pluck('key'))->toContain('towels');

    $this->postJson("/api/v1/guest/stays/{$token}/requests", ['type' => 'nope'])->assertStatus(422);
    $res = $this->postJson("/api/v1/guest/stays/{$token}/requests", ['type' => 'towels', 'details' => 'Two more please'])->assertCreated();
    expect($res->json('data.requests.0.label'))->toBe('Fresh towels')
        ->and(GuestRequest::where('reservation_id', $r->id)->first()->housekeeping_task_id)->not->toBeNull();
});

test('cancelling from the app follows the hotel policy', function () {
    config(['hotel.booking.cancel_hours' => 48]);
    $r = Reservation::where('status', ReservationStatus::Confirmed->value)->first();
    $r->forceFill(['check_in_date' => today()->addDays(10), 'check_out_date' => today()->addDays(12)])->save();
    $token = app(\App\Services\Booking\OnlineBookingService::class)->ensureToken($r);
    $this->postJson("/api/v1/guest/stays/{$token}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.can_cancel', false);
    $this->postJson("/api/v1/guest/stays/{$token}/cancel")->assertStatus(422);
});
