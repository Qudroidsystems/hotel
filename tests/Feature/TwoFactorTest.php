<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Auth\Totp;
use App\Support\Auth\TwoFactor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    auth()->logout();
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'username' => str($name)->slug('.'), 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->gm = $make('Gbenga Manager', 'General Manager');
    $this->desk = $make('Ada Desk', 'Front Desk');
    // Set up two-step sign-in for a user without going through the pages.
    $this->enable = function (User $u): string {
        $secret = Totp::secret();
        $u->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        return $secret;
    };
});

test('the code generator matches the RFC 6238 test values', function () {
    $key = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';   // "12345678901234567890"
    expect(Totp::code($key, Totp::step(59)))->toBe('287082')
        ->and(Totp::code($key, Totp::step(1111111109)))->toBe('081804')
        ->and(Totp::code($key, Totp::step(2000000000)))->toBe('279037')
        ->and(Totp::uri($key, 'a@b.c', 'Smart Hotel'))->toStartWith('otpauth://totp/Smart%20Hotel:a%40b.c?secret=' . $key);
});

test('setting it up needs a right code, then gives recovery codes once', function () {
    $this->actingAs($this->desk)->get(route('two-factor.show'))->assertOk()->assertSee('Set it up')->assertSee('otpauth://totp/', false);
    $secret = session('two_factor.setup');
    expect($secret)->toHaveLength(32);

    $this->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
    expect(TwoFactor::enabled($this->desk->fresh()))->toBeFalse();

    $this->post(route('two-factor.confirm'), ['code' => Totp::code($secret)])->assertRedirect(route('two-factor.show'));
    $user = $this->desk->fresh();
    expect(TwoFactor::enabled($user))->toBeTrue()
        ->and($user->two_factor_secret)->toBe($secret)
        ->and(TwoFactor::recoveryLeft($user))->toBe(8)
        ->and($user->getRawOriginal('two_factor_secret'))->not->toContain($secret);   // stored encrypted

    $codes = session('two_factor.codes');
    expect($codes)->toHaveCount(8);
    $this->get(route('two-factor.show'))->assertSee("It's on")->assertSee('Recovery codes left');
    expect(AuditLog::where('action', 'auth.2fa_enabled')->count())->toBe(1);
});

test('signing in asks for the code after the password, and a code works only once', function () {
    $secret = ($this->enable)($this->desk);

    $this->post(route('login'), ['email' => 'ada-desk@test.local', 'password' => 'wrong'])->assertSessionHasErrors();
    $this->assertGuest();

    $this->post(route('login'), ['email' => 'ada-desk@test.local', 'password' => 'Secret@123'])->assertRedirect(route('two-factor.challenge'));
    $this->assertGuest();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('two-factor.challenge'))->assertOk()->assertSee('authenticator app');

    $this->post(route('two-factor.verify'), ['code' => '123456'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $code = Totp::code($secret);
    $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($this->desk);
    expect(AuditLog::where('action', 'auth.2fa_failed')->count())->toBe(1)
        ->and(AuditLog::where('action', 'auth.login')->count())->toBe(1);   // signed in once, after the code

    // The same code can't be replayed.
    auth()->logout();
    $this->post(route('login'), ['email' => 'ada-desk@test.local', 'password' => 'Secret@123']);
    $this->post(route('two-factor.verify'), ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a recovery code signs in once, and too many wrong codes lock the step', function () {
    ($this->enable)($this->desk);
    $codes = TwoFactor::newRecoveryCodes($this->desk);

    $this->post(route('login'), ['email' => 'ada.desk', 'password' => 'Secret@123'])->assertRedirect(route('two-factor.challenge'));
    $this->post(route('two-factor.verify'), ['code' => strtolower($codes[0])])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($this->desk);
    expect(TwoFactor::recoveryLeft($this->desk->fresh()))->toBe(7);

    auth()->logout();
    $this->post(route('login'), ['email' => 'ada.desk', 'password' => 'Secret@123']);
    $this->post(route('two-factor.verify'), ['code' => $codes[0]])->assertSessionHasErrors('code');   // used already
    $this->freezeTime();   // the lock message counts seconds: a tick between requests would read 59
    foreach (range(1, 4) as $i) {
        $this->post(route('two-factor.verify'), ['code' => '111111']);
    }
    $this->post(route('two-factor.verify'), ['code' => $codes[1]])->assertSessionHasErrors(['code' => 'Too many wrong codes. Try again in 60 seconds.']);
    $this->assertGuest();
    expect(AuditLog::where('action', 'auth.2fa_failed')->where('flagged', true)->exists())->toBeTrue();
});

test('the code step expires', function () {
    ($this->enable)($this->desk);
    $this->post(route('login'), ['email' => 'ada.desk', 'password' => 'Secret@123']);
    $this->travel(6)->minutes();
    $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
});

test('roles that require it are sent to set it up, and can not turn it off', function () {
    config(['two_factor.required_roles' => ['General Manager']]);

    $this->actingAs($this->gm)->get(route('dashboard'))->assertRedirect(route('two-factor.show'));
    $this->get(route('rooms.index'))->assertRedirect(route('two-factor.show'));
    $this->getJson(route('dashboard'))->assertForbidden();
    $this->get(route('two-factor.show'))->assertOk()->assertSee('Your role needs two-step sign-in');
    $this->actingAs($this->desk)->get(route('dashboard'))->assertOk();   // optional for front desk

    ($this->enable)($this->gm);
    $this->actingAs($this->gm->fresh())->get(route('dashboard'))->assertOk();
    $this->post(route('two-factor.disable'), ['password' => 'Secret@123'])->assertSessionHas('error');
    expect(TwoFactor::enabled($this->gm->fresh()))->toBeTrue();

    // Front desk may turn it off, with their password.
    ($this->enable)($this->desk);
    $this->actingAs($this->desk->fresh())->post(route('two-factor.disable'), ['password' => 'nope'])->assertSessionHasErrors('password');
    $this->post(route('two-factor.disable'), ['password' => 'Secret@123'])->assertSessionHas('success');
    expect(TwoFactor::enabled($this->desk->fresh()))->toBeFalse();
});

test('the staff app asks for the code too', function () {
    $secret = ($this->enable)($this->desk);
    $this->postJson('/api/v1/auth/login', ['login' => 'ada.desk', 'password' => 'Secret@123'])->assertStatus(422)->assertJsonPath('code', 'two_factor_required');
    $this->postJson('/api/v1/auth/login', ['login' => 'ada.desk', 'password' => 'Secret@123', 'code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'two_factor_invalid');
    $this->postJson('/api/v1/auth/login', ['login' => 'ada.desk', 'password' => 'Secret@123', 'code' => Totp::code($secret)])->assertOk()->assertJsonStructure(['data' => ['token']]);

    config(['two_factor.required_roles' => ['General Manager']]);
    $this->postJson('/api/v1/auth/login', ['login' => 'gbenga.manager', 'password' => 'Secret@123'])->assertStatus(403)->assertJsonPath('code', 'two_factor_setup_required');
});

test('an administrator can see who uses it and reset someone, with a reason; so can the server command', function () {
    ($this->enable)($this->desk);
    $this->actingAs($this->gm)->get(route('security.two-factor'))->assertOk()->assertSee('Ada Desk')->assertSee('On since');
    $this->get(route('security.two-factor', ['show' => 'on']))->assertOk()->assertSee('Ada Desk')->assertDontSee('Gbenga Manager</td>', false);

    $this->post(route('security.two-factor.reset', $this->desk))->assertSessionHasErrors('reason');
    $this->post(route('security.two-factor.reset', $this->desk), ['reason' => 'Lost phone, checked ID'])->assertSessionHas('success');
    expect(TwoFactor::enabled($this->desk->fresh()))->toBeFalse()
        ->and(AuditLog::where('action', 'auth.2fa_reset')->where('flagged', true)->value('reason'))->toBe('Lost phone, checked ID');

    $this->actingAs($this->desk)->get(route('security.two-factor'))->assertForbidden();

    ($this->enable)($this->gm);
    expect(Artisan::call('hotel:2fa-reset', ['login' => 'gbenga-manager@test.local']))->toBe(0)
        ->and(TwoFactor::enabled($this->gm->fresh()))->toBeFalse();
});
