<?php

use App\Models\HotelSetting;
use App\Models\User;
use App\Support\HotelSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
});

function reapply(): void
{
    HotelSettings::forget();
    HotelSettings::apply();
}

test('the admin enters the SMS account; keys are stored encrypted and never shown again', function () {
    $this->put(route('settings.hotel.update', 'sms_accounts'), [
        'accounts__twilio__sid' => 'AC0123456789', 'accounts__twilio__auth_token' => 'tok-secret-123',
        'accounts__termii__api_key' => 'TL-key-999', 'accounts__termii__sender_id' => 'BLuxury',
        'accounts__termii__base_url' => 'https://v3.api.termii.com',
    ])->assertSessionHas('success');
    reapply();

    expect(config('services.twilio.sid'))->toBe('AC0123456789')
        ->and(config('services.twilio.auth_token'))->toBe('tok-secret-123')
        ->and(config('services.termii.api_key'))->toBe('TL-key-999')
        ->and(config('services.termii.base_url'))->toBe('https://v3.api.termii.com');
    $raw = HotelSetting::where('key', 'accounts.twilio.auth_token')->value('value');
    expect($raw)->not->toContain('tok-secret-123');

    $this->get(route('settings.hotel.index', ['tab' => 'sms_accounts']))->assertOk()
        ->assertDontSee('tok-secret-123')->assertDontSee('TL-key-999')->assertSee('saved');

    // Saving again with the key boxes empty keeps them.
    $this->put(route('settings.hotel.update', 'sms_accounts'), ['accounts__twilio__sid' => 'AC0123456789', 'accounts__termii__sender_id' => 'BLuxury', 'accounts__termii__base_url' => 'https://v3.api.termii.com']);
    reapply();
    expect(config('services.twilio.auth_token'))->toBe('tok-secret-123');

    // Removing one goes back to .env.
    $this->put(route('settings.hotel.update', 'sms_accounts'), ['accounts__twilio__auth_token__remove' => '1', 'accounts__twilio__sid' => 'AC0123456789']);
    expect(HotelSetting::where('key', 'accounts.twilio.auth_token')->exists())->toBeFalse();
});

test('payments, email and push are entered the same way, and checked', function () {
    $this->put(route('settings.hotel.update', 'payments'), ['accounts__paystack__active' => '1', 'accounts__paystack__public_key' => 'pk_live_abc', 'accounts__paystack__secret_key' => 'nope'])
        ->assertSessionHasErrors('accounts__paystack__secret_key');
    $this->put(route('settings.hotel.update', 'payments'), ['accounts__paystack__active' => '1', 'accounts__paystack__public_key' => 'pk_live_abc', 'accounts__paystack__secret_key' => 'sk_live_xyz']);
    $this->put(route('settings.hotel.update', 'email'), [
        'accounts__mail__mailer' => 'smtp', 'accounts__mail__host' => 'mail.bluxury.ng', 'accounts__mail__port' => 465, 'accounts__mail__scheme' => 'smtps',
        'accounts__mail__username' => 'hello@bluxury.ng', 'accounts__mail__password' => 'mailpass', 'accounts__mail__from' => 'hello@bluxury.ng', 'accounts__mail__from_name' => 'B Luxury',
    ])->assertSessionHasNoErrors();
    $this->put(route('settings.hotel.update', 'push'), ['accounts__fcm__credentials_json' => '{"x":1}'])->assertSessionHasErrors('accounts__fcm__credentials_json');
    $this->put(route('settings.hotel.update', 'push'), ['accounts__fcm__credentials_json' => json_encode(['project_id' => 'p', 'client_email' => 'a@p.iam', 'private_key' => 'k'])])->assertSessionHasNoErrors();
    reapply();

    expect(config('services.paystack.secret_key'))->toBe('sk_live_xyz')
        ->and(config('mail.default'))->toBe('smtp')->and(config('mail.mailers.smtp.port'))->toBe(465)
        ->and(config('mail.mailers.smtp.password'))->toBe('mailpass')->and(config('mail.from.name'))->toBe('B Luxury')
        ->and(app(\App\Services\Mobile\PushSender::class)->enabled())->toBeTrue();
});

test('every hotel setting in config/hotel.php can be changed on the settings page', function () {
    $editable = array_keys(HotelSettings::fields());
    $leaves = [];
    $walk = function ($arr, $prefix) use (&$walk, &$leaves) {
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? $k : "$prefix.$k";
            if (is_array($v) && ! array_is_list($v)) {
                $walk($v, $key);
            } else {
                $leaves[] = $key;
            }
        }
    };
    $walk(require config_path('hotel.php'), '');
    // Built from other settings, or Qudroid's own credit.
    $fixed = ['taxes', 'vendor', 'vendor_url', 'multi_hotel', 'group_name', 'commission_percent'];   // multi_hotel: Settings → Hotels
    expect(array_values(array_diff($leaves, $editable, $fixed)))->toBe([]);
});
