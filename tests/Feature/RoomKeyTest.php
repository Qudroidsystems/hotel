<?php

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\Keys\DoorEvent;
use App\Models\Keys\RoomKey;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Booking\OnlineBookingService;
use App\Services\Keys\RoomKeyService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    config(['hotel.keys.provider' => 'ttlock', 'services.ttlock' => ['api' => 'https://euapi.ttlock.com', 'client_id' => 'cid', 'client_secret' => 'sec', 'username' => 'hotel', 'password' => 'pw']]);
    $this->calls = [];
    $this->down = false;
    Http::fake(function ($req) {
        $this->calls[] = parse_url($req->url(), PHP_URL_PATH);
        if ($this->down && parse_url($req->url(), PHP_URL_PATH) !== '/oauth2/token') {
            return Http::response(['errcode' => -3003, 'errmsg' => 'Gateway is offline']);
        }

        return match (parse_url($req->url(), PHP_URL_PATH)) {
            '/oauth2/token'          => Http::response(['access_token' => 'tok', 'expires_in' => 7776000]),
            '/v3/keyboardPwd/get'    => Http::response(['keyboardPwd' => '48291375', 'keyboardPwdId' => 777]),
            '/pins'                  => Http::response(['pin' => '1234', 'ref' => 'A1']),   // the lock bridge
            '/v3/lockRecord/list'    => Http::response(['list' => [['recordId' => 9001, 'lockDate' => now()->subMinutes(5)->getTimestampMs(), 'recordType' => 4, 'success' => 1, 'username' => 'Guest', 'keyboardPwd' => '48291375']]]),
            default                  => Http::response(['errcode' => 0]),
        };
    });
    $this->r = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('rooms.room', 'guest')->first();
    $this->room = $this->r->rooms->first()->room;
    $this->room->forceFill(['lock_id' => '3141592'])->save();
    $this->token = app(OnlineBookingService::class)->ensureToken($this->r);
    $this->desk = User::create(['name' => 'Desk', 'email' => 'desk@test.local', 'password' => bcrypt('Secret@123')]);
    $this->desk->assignRole('Front Desk');
    // As if the guest is checking in now.
    $this->r->forceFill(['status' => ReservationStatus::Confirmed])->saveQuietly();
    $this->checkIn = fn () => $this->r->forceFill(['status' => ReservationStatus::CheckedIn])->save();
});

test('check-in makes a door code and a phone key; the guest sees the code and opens the door from the app', function () {
    ($this->checkIn)();
    $keys = RoomKey::where('reservation_id', $this->r->id)->get()->keyBy('kind');
    expect($keys['pin']->status)->toBe('active')->and($keys['pin']->secret)->toBe('48291375')->and($keys['pin']->provider_ref)->toBe('777')
        ->and($keys['phone']->status)->toBe('active')
        ->and($keys['pin']->valid_until->gt($this->r->check_out_date))->toBeTrue()
        ->and(\Illuminate\Support\Facades\DB::table('room_keys')->where('id', $keys['pin']->id)->value('secret'))->not->toContain('48291375');

    $stay = $this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.keys');
    expect(collect($stay)->firstWhere('kind', 'pin')['pin'])->toBe('48291375')->and(collect($stay)->firstWhere('kind', 'phone')['can_unlock'])->toBeTrue();

    $this->postJson("/api/v1/guest/stays/{$this->token}/keys/{$keys['phone']->id}/unlock")->assertOk();
    expect($this->calls)->toContain('/v3/lock/unlock')->and(DoorEvent::where('kind', 'phone')->where('ok', true)->count())->toBe(1);

    // A code can't be "unlocked" from the app, only the phone key.
    $this->postJson("/api/v1/guest/stays/{$this->token}/keys/{$keys['pin']->id}/unlock")->assertStatus(422);
    $this->get(route('stay.show', $this->token))->assertOk()->assertSee('4 8 2 9 1 3 7 5');
});

test('check-out cancels every key on the lock; a lock that can’t be reached never stops check-in', function () {
    ($this->checkIn)();
    $this->r->forceFill(['status' => ReservationStatus::CheckedOut])->save();
    expect(RoomKey::where('reservation_id', $this->r->id)->pluck('status')->unique()->all())->toBe(['revoked'])
        ->and($this->calls)->toContain('/v3/keyboardPwd/delete')
        ->and($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.keys'))->toBe([]);

    // The lock cloud is down: the stay still checks in; the key is marked and the desk is told.
    RoomKey::query()->delete();
    $this->down = true;
    $this->r->forceFill(['status' => ReservationStatus::Confirmed])->saveQuietly();
    ($this->checkIn)();
    expect($this->r->fresh()->status)->toBe(ReservationStatus::CheckedIn)
        ->and(RoomKey::where('kind', 'pin')->first()->status)->toBe('failed')
        ->and(RoomKey::where('kind', 'pin')->first()->note)->toContain('Gateway is offline')
        ->and(AuditLog::where('action', 'keys.failed')->where('flagged', true)->exists())->toBeTrue();
});

test('a room move moves the keys; a longer stay makes them last longer', function () {
    ($this->checkIn)();
    $other = Room::whereNotIn('id', $this->r->rooms->pluck('room_id'))->where('status', 'available')->first();
    $other->forceFill(['lock_id' => '2718281'])->save();
    $line = $this->r->rooms->first();
    $line->update(['room_id' => $other->id]);
    expect(RoomKey::where('room_id', $this->room->id)->pluck('status')->unique()->all())->toBe(['revoked'])
        ->and(RoomKey::where('room_id', $other->id)->active()->count())->toBe(2);

    $this->r->refresh();
    $this->r->forceFill(['check_out_date' => $this->r->check_out_date->copy()->addDays(2)])->save();
    expect($this->calls)->toContain('/v3/keyboardPwd/change')
        ->and(RoomKey::where('room_id', $other->id)->active()->first()->valid_until->gt($this->r->check_out_date))->toBeTrue();
});

test('the desk gives a new code, records key cards, reports one lost, and opens a door with a reason', function () {
    ($this->checkIn)();
    $this->actingAs($this->desk)->get(route('keys.index'))->assertOk()->assertSee($this->room->number)->assertSee('48291375');
    $this->post(route('keys.act', [$this->r, $this->room, 'pin']))->assertSessionHas('success');
    expect(RoomKey::where('kind', 'pin')->where('status', 'revoked')->count())->toBe(1)->and(RoomKey::where('kind', 'pin')->active()->count())->toBe(1);

    $this->post(route('keys.act', [$this->r, $this->room, 'card']), ['card_number' => 'C-0042'])->assertSessionHas('success');
    $card = RoomKey::where('kind', 'card')->first();
    $this->post(route('keys.revoke', $card), ['lost' => 1])->assertSessionHas('success');
    expect($card->fresh()->status)->toBe('lost');

    $this->post(route('keys.act', [$this->r, $this->room, 'unlock']))->assertSessionHas('error');   // needs a reason
    $this->post(route('keys.act', [$this->r, $this->room, 'unlock']), ['reason' => 'Guest locked out, ID checked'])->assertSessionHas('success');
    expect(DoorEvent::where('kind', 'remote')->first()->note)->toBe('Guest locked out, ID checked')
        ->and(AuditLog::where('action', 'keys.remote_unlock')->where('flagged', true)->exists())->toBeTrue();

    $cashier = User::create(['name' => 'Cash', 'email' => 'cash@test.local', 'password' => bcrypt('Secret@123')]);
    $cashier->assignRole('Cashier');
    $this->actingAs($cashier)->post(route('keys.act', [$this->r, $this->room, 'pin']))->assertForbidden();
});

test('locks are linked to rooms; the door log reads the locks’ records once; keys end on time', function () {
    $gm = User::create(['name' => 'GM', 'email' => 'gm@test.local', 'password' => bcrypt('Secret@123')]);
    $gm->assignRole('General Manager');
    $this->actingAs($gm)->get(route('keys.locks'))->assertOk();
    $this->post(route('keys.locks.save'), ['lock' => [$this->room->id => '999']])->assertSessionHas('success');
    expect($this->room->fresh()->lock_id)->toBe('999');

    $svc = app(RoomKeyService::class);
    expect($svc->syncRecords())->toBe(1)->and($svc->syncRecords())->toBe(0);
    $this->get(route('keys.log'))->assertOk()->assertSee('Code ending 75');

    ($this->checkIn)();
    $this->travelTo($this->r->check_out_date->copy()->addDay());
    expect($svc->expire())->toBe(2)->and(RoomKey::active()->count())->toBe(0);
});

test('key cards only (no connected locks): nothing is sent anywhere; cards are recorded', function () {
    config(['hotel.keys.provider' => 'manual']);
    ($this->checkIn)();
    expect(RoomKey::count())->toBe(0)->and($this->calls)->toBe([]);
    $k = app(RoomKeyService::class)->recordCard($this->r, $this->room, '17', $this->desk);
    expect($k->kind)->toBe('card')->and($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.keys'))->toBe([]);   // cards aren't shown in the app
});

test('other lock systems work through a bridge: requests are signed with the shared secret', function () {
    config(['hotel.keys.provider' => 'bridge', 'services.lock_bridge' => ['url' => 'https://bridge.hotel.local', 'secret' => 's3cret']]);
    $p = app(\App\Services\Keys\Providers\BridgeProvider::class);
    $made = $p->issuePin('L1', now(), now()->addDay(), 'Okafor');
    expect($made)->toBe(['pin' => '1234', 'ref' => 'A1']);
    Http::assertSent(fn ($req) => str_ends_with($req->url(), '/pins') && $req->header('X-Signature')[0] === hash_hmac('sha256', $req->body(), 's3cret'));
});
