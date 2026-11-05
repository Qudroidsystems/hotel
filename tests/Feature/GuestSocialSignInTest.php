<?php

use App\Enums\ReservationStatus;
use App\Models\Booking\GuestSocialLogin;
use App\Models\Reservation;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    $this->r = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('guest')->first();
    $this->r->guest->forceFill(['email' => 'ada.obi@example.com'])->save();

    // A signing key standing in for Google's and Apple's.
    $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $rsa = openssl_pkey_get_details($this->key)['rsa'];
    $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $jwks = ['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]]];
    Http::fake(['www.googleapis.com/*' => Http::response($jwks), 'appleid.apple.com/*' => Http::response($jwks)]);

    config([
        'hotel.guest_app.google_sign_in' => true, 'hotel.guest_app.google_web_client_id' => 'web-123.apps.googleusercontent.com',
        'hotel.guest_app.apple_sign_in' => true, 'hotel.guest_app.apple_service_id' => 'com.hotel.signin', 'hotel.guest_app.apple_bundle_id' => 'com.hotel.guest',
    ]);

    $this->jwt = function (array $claims, $key = null) use ($b64) {
        $claims += ['iat' => time(), 'exp' => time() + 600];
        $input = $b64(json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT'])) . '.' . $b64(json_encode($claims));
        openssl_sign($input, $sig, $key ?? $this->key, OPENSSL_ALGO_SHA256);

        return $input . '.' . $b64($sig);
    };
});

test('a guest signs in with Google and sees the bookings made with that e-mail', function () {
    $token = ($this->jwt)(['iss' => 'https://accounts.google.com', 'aud' => 'web-123.apps.googleusercontent.com', 'sub' => 'g-1',
        'email' => 'Ada.Obi@example.com', 'email_verified' => true, 'given_name' => 'Ada', 'family_name' => 'Obi']);
    $res = $this->postJson('/api/v1/guest/auth/social', ['provider' => 'google', 'id_token' => $token, 'device' => 'Pixel'])->assertOk();

    expect($res->json('data.token'))->toStartWith('gs_')
        ->and(collect($res->json('data.account.stays'))->pluck('reference'))->toContain($this->r->reference)
        ->and(GuestSocialLogin::where('provider', 'google')->where('subject', 'g-1')->value('email'))->toBe('ada.obi@example.com');
});

test('tokens for another app, unsigned by the provider, expired or unconfirmed are refused', function () {
    $base = ['iss' => 'https://accounts.google.com', 'aud' => 'web-123.apps.googleusercontent.com', 'sub' => 'g-2', 'email' => 'x@example.com', 'email_verified' => true];
    $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    foreach ([
        ($this->jwt)(['aud' => 'someone-else.apps.googleusercontent.com'] + $base),
        ($this->jwt)($base, $other),
        ($this->jwt)(['exp' => time() - 3600] + $base),
        ($this->jwt)(['email_verified' => false] + $base),
        'not.a.token',
    ] as $bad) {
        $this->postJson('/api/v1/guest/auth/social', ['provider' => 'google', 'id_token' => $bad])->assertStatus(422);
    }

    config(['hotel.guest_app.google_sign_in' => false]);
    $this->postJson('/api/v1/guest/auth/social', ['provider' => 'google', 'id_token' => ($this->jwt)($base)])->assertStatus(422);
});

test('Apple keeps the account when it leaves the e-mail out later, and gives the name to a new guest', function () {
    $first = ($this->jwt)(['iss' => 'https://appleid.apple.com', 'aud' => 'com.hotel.guest', 'sub' => 'a-1', 'email' => 'new.guest@privaterelay.appleid.com', 'email_verified' => 'true', 'nonce' => hash('sha256', 'n0nce')]);
    $res = $this->postJson('/api/v1/guest/auth/social', ['provider' => 'apple', 'id_token' => $first, 'nonce' => 'n0nce', 'first_name' => 'Tolu', 'last_name' => 'Ade'])->assertOk();
    expect($res->json('data.account.first_name'))->toBe('Tolu')->and($res->json('data.account.email'))->toBe('new.guest@privaterelay.appleid.com');

    $later = ($this->jwt)(['iss' => 'https://appleid.apple.com', 'aud' => 'com.hotel.guest', 'sub' => 'a-1']);
    $this->postJson('/api/v1/guest/auth/social', ['provider' => 'apple', 'id_token' => $later])->assertOk()
        ->assertJsonPath('data.account.contact', 'new.guest@privaterelay.appleid.com');

    // A wrong nonce is refused.
    $this->postJson('/api/v1/guest/auth/social', ['provider' => 'apple', 'id_token' => $first, 'nonce' => 'other'])->assertStatus(422);
});

test('with sign-up closed, only guests who have booked can use Google', function () {
    config(['hotel.guest_app.open_sign_up' => false]);
    $claims = ['iss' => 'accounts.google.com', 'aud' => 'web-123.apps.googleusercontent.com', 'email_verified' => true];
    $this->postJson('/api/v1/guest/auth/social', ['provider' => 'google', 'id_token' => ($this->jwt)(['sub' => 'g-9', 'email' => 'stranger@example.com'] + $claims)])->assertStatus(422);
    $this->postJson('/api/v1/guest/auth/social', ['provider' => 'google', 'id_token' => ($this->jwt)(['sub' => 'g-8', 'email' => 'ada.obi@example.com'] + $claims)])->assertOk();
});

test('the apps get the public client IDs only for switched-on providers', function () {
    config(['hotel.guest_app.apple_sign_in' => false]);
    $this->getJson('/api/v1/guest/auth/providers')->assertOk()
        ->assertJsonPath('data.google.web_client_id', 'web-123.apps.googleusercontent.com')
        ->assertJsonPath('data.apple', null);
});

test('on a local install with e-mail going to the log, the code is shown instead', function () {
    config(['mail.default' => 'log', 'app.debug' => true]);
    app()->detectEnvironment(fn () => 'local');
    $res = $this->postJson('/api/v1/guest/auth/code', ['contact' => 'ada.obi@example.com'])->assertOk();
    $code = $res->json('data.dev_code');
    expect($code)->toMatch('/^\d{6}$/');
    $this->postJson('/api/v1/guest/auth/verify', ['contact' => 'ada.obi@example.com', 'code' => $code])->assertOk();

    // Never on a live site.
    app()->detectEnvironment(fn () => 'production');
    expect($this->postJson('/api/v1/guest/auth/code', ['contact' => 'ada.obi@example.com'])->json('data.dev_code'))->toBeNull();
});

test('the website account: code sign-in, Google sign-in, bookings, sign out', function () {
    config(['mail.default' => 'log', 'app.debug' => true]);
    app()->detectEnvironment(fn () => 'local');
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);   // only skipped by itself while the environment is "testing"
    $this->get('/account')->assertOk()->assertSee('Send me a code')->assertSee('g-btn', false)->assertSee('Continue with Apple');

    $this->post('/account/code', ['contact' => 'ada.obi@example.com'])->assertRedirect('/account?step=code')->assertSessionHas('dev_code');
    $code = session('dev_code');
    $this->get('/account?step=code')->assertSee($code);
    $this->post('/account/verify', ['code' => $code])->assertRedirect('/account');
    $this->get('/account')->assertOk()->assertSee($this->r->reference)->assertSee('Sign out');
    $this->post('/account/logout')->assertRedirect('/account');
    $this->get('/account')->assertSee('Send me a code');

    $nonce = session('guest_account.nonce');
    $token = ($this->jwt)(['iss' => 'accounts.google.com', 'aud' => 'web-123.apps.googleusercontent.com', 'sub' => 'g-5', 'email' => 'ada.obi@example.com', 'email_verified' => true, 'nonce' => $nonce]);
    $this->postJson('/account/social', ['provider' => 'google', 'id_token' => $token])->assertOk()->assertJsonPath('redirect', url('/account'));
    $this->get('/account')->assertSee($this->r->reference);
});

test('Apple hands its answer back to the Android app', function () {
    config(['hotel.guest_app.android_package' => 'com.hotel.guest']);
    $this->post('/account/apple/callback', ['state' => 'android-xyz', 'code' => 'c1', 'id_token' => 't1'])
        ->assertRedirect('intent://callback?code=c1&id_token=t1&state=android-xyz#Intent;package=com.hotel.guest;scheme=signinwithapple;end');
});

test('settings: the e-mail tab warns about the log and sends a test e-mail', function () {
    $admin = \App\Models\User::role('Super Admin')->first();
    $this->actingAs($admin);
    config(['mail.default' => 'log']);
    $this->get('/settings/hotel?tab=email')->assertOk()->assertSee('written to');
    $this->post('/settings/hotel/test-email', ['to' => 'me@example.com'])->assertSessionHas('error');
    config(['mail.default' => 'array']);
    $this->post('/settings/hotel/test-email', ['to' => 'me@example.com'])->assertSessionHas('error');

    config(['mail.default' => 'smtp']);
    Mail::shouldReceive('raw')->once();
    $this->post('/settings/hotel/test-email', ['to' => 'me@example.com'])->assertSessionHas('success');
    $this->get('/settings/hotel?tab=guest_sign_in')->assertOk()->assertSee('Sign in with Google')->assertSee('/account/apple/callback');
});
