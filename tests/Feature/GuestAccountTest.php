<?php

use App\Enums\ReservationStatus;
use App\Models\Booking\GuestLoginCode;
use App\Models\Reservation;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    $this->r = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('guest')->first();
    $this->r->guest->forceFill(['email' => 'Ada.Obi@Example.com'])->save();
    // A second, earlier booking under another guest record with the same e-mail.
    $this->old = Reservation::where('status', ReservationStatus::Confirmed->value)->with('guest')->first();
    $this->old->guest->forceFill(['email' => 'ada.obi@example.com'])->save();
});

/** Ask for a code and read it from the e-mail sent. */
function signInCode($test, string $contact): string
{
    $sent = null;
    Mail::shouldReceive('raw')->once()->andReturnUsing(function ($text) use (&$sent) { $sent = $text; });
    $test->postJson('/api/v1/guest/auth/code', ['contact' => $contact])->assertOk();
    preg_match('/^(\d{6})/', (string) $sent, $m);

    return $m[1];
}

test('a guest signs in with a code to their e-mail and sees every booking made with it', function () {
    $code = signInCode($this, 'ADA.OBI@example.com');
    $this->postJson('/api/v1/guest/auth/verify', ['contact' => 'ada.obi@example.com', 'code' => '000000'])->assertStatus(422);
    $res = $this->postJson('/api/v1/guest/auth/verify', ['contact' => 'ada.obi@example.com', 'code' => $code])->assertOk();
    $token = $res->json('data.token');
    expect($token)->toStartWith('gs_')
        ->and(collect($res->json('data.account.stays'))->pluck('reference')->all())->toContain($this->r->reference, $this->old->reference);

    // The code works once.
    $this->postJson('/api/v1/guest/auth/verify', ['contact' => 'ada.obi@example.com', 'code' => $code])->assertStatus(422);

    $me = $this->getJson('/api/v1/guest/me', ['X-Guest-Token' => $token])->assertOk()->json('data');
    expect($me['email'])->toBe('Ada.Obi@Example.com')->and($me['stays'][0])->toHaveKeys(['token', 'reference', 'status', 'check_in_label', 'hotel']);

    // Opening a stay from the account works like the booking link.
    $this->getJson('/api/v1/guest/stays/' . $me['stays'][0]['token'])->assertOk();

    $this->patchJson('/api/v1/guest/me', ['first_name' => 'Adaeze', 'marketing_opt_in' => true], ['X-Guest-Token' => $token])->assertOk()->assertJsonPath('data.first_name', 'Adaeze');
    expect($this->r->guest->fresh()->first_name)->toBe('Adaeze')->and($this->old->guest->fresh()->first_name)->toBe('Adaeze');

    $this->postJson('/api/v1/guest/auth/logout', [], ['X-Guest-Token' => $token])->assertOk();
    $this->getJson('/api/v1/guest/me', ['X-Guest-Token' => $token])->assertUnauthorized();
});

test('an unknown contact gets the same answer and no code; wrong codes run out', function () {
    // With sign-up open, a new e-mail gets a code to join (see GuestExploreApiTest); closed, unknown contacts get nothing.
    config(['hotel.guest_app.open_sign_up' => false]);
    Mail::shouldReceive('raw')->never();
    $this->postJson('/api/v1/guest/auth/code', ['contact' => 'nobody@example.com'])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'If you’ve booked with us'));
    expect(GuestLoginCode::count())->toBe(0);
    $this->postJson('/api/v1/guest/auth/code', ['contact' => 'not a contact'])->assertStatus(422);
});

test('five wrong tries use the code up', function () {
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);   // the code's own limit, not the page's
    $code = signInCode($this, 'ada.obi@example.com');
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/guest/auth/verify', ['contact' => 'ada.obi@example.com', 'code' => '111111'])->assertStatus(422);
    }
    $this->postJson('/api/v1/guest/auth/verify', ['contact' => 'ada.obi@example.com', 'code' => $code])->assertStatus(422)->assertJsonPath('message', 'That code has expired. Ask for a new one.');
});
