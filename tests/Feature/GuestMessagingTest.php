<?php

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Messaging\GuestMessage;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Messaging\GuestMessenger;
use App\Services\Reservations\ReservationService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    $this->withoutDefer();
    config([
        'hotel.messaging.enabled' => true, 'hotel.messaging.channel' => 'sms', 'hotel.messaging.country_code' => '234',
        'services.twilio.sid' => 'AC123', 'services.twilio.auth_token' => 'secret', 'services.twilio.phone_number' => '+15550001111',
    ]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM' . uniqid(), 'status' => 'queued'], 201)]);
});

test('numbers are turned into international form', function () {
    $m = app(GuestMessenger::class);
    expect($m->normalize('0803 123 4567'))->toBe('+2348031234567')
        ->and($m->normalize('+44 7700 900123'))->toBe('+447700900123')
        ->and($m->normalize('2348031234567'))->toBe('+2348031234567')
        ->and($m->normalize('12'))->toBeNull();
});

test('confirming a booking texts the guest once, with their booking page link', function () {
    $r = Reservation::where('status', ReservationStatus::Pending->value)->first()
        ?? tap(Reservation::where('status', ReservationStatus::Confirmed->value)->first())->forceFill(['status' => ReservationStatus::Pending])->save();
    $r->guest->forceFill(['phone' => '08031234567', 'messages_opt_out' => false])->save();

    $r->forceFill(['status' => ReservationStatus::Confirmed])->save();
    $r->forceFill(['notes' => 'touched'])->save();   // saving again doesn't send again

    $msgs = GuestMessage::where('reservation_id', $r->id)->where('template', 'booking_confirmed')->get();
    expect($msgs)->toHaveCount(1)
        ->and($msgs->first()->status)->toBe('sent')
        ->and($msgs->first()->to)->toBe('+2348031234567')
        ->and($msgs->first()->body)->toContain($r->reference)->toContain('/stay/');
    Http::assertSent(fn ($req) => $req['To'] === '+2348031234567' && $req['From'] === '+15550001111' && str_contains($req['Body'], $r->reference));
});

test('guests who said stop are skipped, and switched-off messages send nothing', function () {
    $r = Reservation::where('status', ReservationStatus::Confirmed->value)->first();
    $r->guest->forceFill(['phone' => '08031234567', 'messages_opt_out' => true])->save();
    $msg = app(GuestMessenger::class)->send($r->guest, $r, 'Hello', 'manual', $this->admin);
    expect($msg->status)->toBe('skipped')->and($msg->error)->toContain('asked not to be texted');

    config(['hotel.messaging.enabled' => false]);
    expect(app(GuestMessenger::class)->aboutBooking($r, 'booking_confirmed'))->toBeNull();
    Http::assertNothingSent();
});

test('staff text a guest from the booking, and need the permission', function () {
    $r = Reservation::where('status', ReservationStatus::Confirmed->value)->first();
    $r->guest->forceFill(['phone' => '+447700900123', 'messages_opt_out' => false])->save();

    $this->post(route('reservations.messages.store', $r), ['body' => 'Your airport pickup is booked for 3pm.'])->assertSessionHas('success');
    expect(GuestMessage::where('template', 'manual')->first())->sent_by->toBe($this->admin->id)->to->toBe('+447700900123');

    $hk = User::create(['name' => 'HK', 'email' => 'hk@test.local', 'password' => bcrypt('Secret@123')]);
    $hk->assignRole('Housekeeping');
    $this->actingAs($hk)->post(route('reservations.messages.store', $r), ['body' => 'x'])->assertForbidden();
    $this->actingAs($hk)->get(route('messages.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('messages.index'))->assertOk()->assertSee('airport pickup');
});

test('Twilio webhooks need a valid signature; replies reach the front desk and STOP turns texts off', function () {
    $guest = Guest::first();
    $guest->forceFill(['phone' => '0803 765 4321', 'messages_opt_out' => false])->save();
    $sign = function (string $url, array $params) {
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k . $v;
        }

        return base64_encode(hash_hmac('sha1', $data, 'secret', true));
    };

    $reply = ['MessageSid' => 'SMin1', 'From' => '+2348037654321', 'To' => '+15550001111', 'Body' => 'Can I check in early?'];
    $this->post(route('twilio.inbound'), $reply)->assertForbidden();
    $this->withHeader('X-Twilio-Signature', $sign(route('twilio.inbound'), $reply))->post(route('twilio.inbound'), $reply)->assertOk();
    $in = GuestMessage::where('provider_id', 'SMin1')->first();
    expect($in->guest_id)->toBe($guest->id)->and($in->status)->toBe('received');

    $stop = ['MessageSid' => 'SMin2', 'From' => '+2348037654321', 'To' => '+15550001111', 'Body' => 'STOP'];
    $this->withHeader('X-Twilio-Signature', $sign(route('twilio.inbound'), $stop))->post(route('twilio.inbound'), $stop)->assertOk();
    expect($guest->fresh()->messages_opt_out)->toBeTrue();

    $out = app(GuestMessenger::class)->send(Guest::where('id', '!=', $guest->id)->whereNotNull('phone')->first(), null, 'Hi', 'manual');
    $status = ['MessageSid' => $out->provider_id, 'MessageStatus' => 'delivered'];
    $this->withHeader('X-Twilio-Signature', $sign(route('twilio.status'), $status))->post(route('twilio.status'), $status)->assertNoContent();
    expect($out->fresh()->status)->toBe('delivered');
});

test('arrival reminders go to tomorrow\'s guests once', function () {
    $r = Reservation::whereIn('status', ['confirmed'])->first();
    app(ReservationService::class);
    $r->forceFill(['check_in_date' => today()->addDay(), 'check_out_date' => today()->addDays(3)])->save();
    $r->guest->forceFill(['phone' => '08031112222', 'messages_opt_out' => false])->save();

    $this->artisan('messages:arrival-reminders')->assertSuccessful();
    $this->artisan('messages:arrival-reminders')->assertSuccessful();
    expect(GuestMessage::where('reservation_id', $r->id)->where('template', 'arrival_reminder')->count())->toBe(1);
});

test('texts go through Termii when chosen, and its signed events update and reply', function () {
    config([
        'hotel.messaging.provider' => 'termii', 'hotel.messaging.termii_route' => 'dnd',
        'services.termii.api_key' => 'TLkey', 'services.termii.secret_key' => 'tsecret', 'services.termii.sender_id' => 'BLuxury',
        'services.termii.base_url' => 'https://api.ng.termii.com',
    ]);
    Http::fake(['api.ng.termii.com/*' => Http::response(['message_id' => '9122821270554876574', 'message' => 'Successfully Sent', 'balance' => 9, 'user' => 'B Luxury'], 200)]);
    $r = Reservation::where('status', ReservationStatus::Confirmed->value)->first();
    $r->guest->forceFill(['phone' => '0803 123 4567', 'messages_opt_out' => false])->save();

    $msg = app(GuestMessenger::class)->send($r->guest, $r, 'Your room is ready.', 'manual', $this->admin);
    expect($msg->status)->toBe('sent')->and($msg->provider_id)->toBe('9122821270554876574')->and($msg->from)->toBe('BLuxury');
    Http::assertSent(fn ($req) => str_ends_with($req->url(), '/api/sms/send') && $req['to'] === '2348031234567'
        && $req['from'] === 'BLuxury' && $req['channel'] === 'dnd' && $req['api_key'] === 'TLkey' && $req['sms'] === 'Your room is ready.');

    // Delivery report: signed with the secret key.
    $event = json_encode(['type' => 'outbound', 'message_id' => '9122821270554876574', 'receiver' => '2348031234567', 'status' => 'Delivered', 'channel' => 'dnd']);
    $this->call('POST', '/termii/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TERMII_SIGNATURE' => 'bad'], $event)->assertForbidden();
    $this->call('POST', '/termii/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TERMII_SIGNATURE' => hash_hmac('sha512', $event, 'tsecret')], $event)->assertNoContent();
    expect($msg->fresh()->status)->toBe('delivered');

    // A reply from the guest.
    $reply = json_encode(['type' => 'inbound', 'message_id' => 'in-1', 'sender' => '2348031234567', 'receiver' => 'BLuxury', 'message' => 'Can I check in early?', 'status' => 'Received']);
    $this->call('POST', '/termii/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TERMII_SIGNATURE' => hash_hmac('sha512', $reply, 'tsecret')], $reply)->assertNoContent();
    expect(GuestMessage::where('direction', 'in')->where('body', 'Can I check in early?')->first())->guest_id->toBe($r->guest->id);
});

test('the messages page names what is missing for the chosen provider', function () {
    config(['hotel.messaging.provider' => 'termii', 'services.termii.api_key' => null, 'services.termii.sender_id' => null, 'hotel.messaging.sender_name' => null]);
    $this->get(route('messages.index'))->assertOk()->assertSee('Termii account')->assertSee('TERMII_API_KEY')->assertSee('TERMII_SENDER_ID');
});
