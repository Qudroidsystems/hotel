<?php

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\Chat\CallListen;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatSection;
use App\Models\Chat\GuestCall;
use App\Models\Facility\FacilityDevice;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\OnlineBookingService;
use App\Services\Facility\FacilityService;
use Database\Seeders\CallConsoleDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    Notification::fake();
    $this->withoutDefer();
    $this->r = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('guest')->first();
    $this->token = app(OnlineBookingService::class)->ensureToken($this->r);
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => Str::slug($name) . '@test.local', 'password' => bcrypt('Secret@123'), 'must_change_password' => false]);
        $u->assignRole($role);
        $u->hotels()->sync([\App\Models\Hotel::first()->id]);

        return $u;
    };
    $this->desk = $make('Amaka Desk', 'Front Desk');
    $this->bar = $make('Tobi Bar', 'Bar Manager');
    $this->it = $make('Ifeanyi Ops', 'IT Operations');
    $this->guard = $make('Musa Guard', 'Security Officer');
    $this->api = "/api/v1/guest/stays/{$this->token}";
    app(\App\Services\Chat\ChatService::class)->sections();
    ChatSection::query()->update(['opens' => null, 'closes' => null]);
    $this->call = fn (string $key) => $this->postJson("{$this->api}/calls", ['section_id' => ChatSection::where('key', $key)->value('id')])->assertCreated()->json('data');
});

/** A tiny real WAV file (so the upload is recognised as sound). */
function wav(): UploadedFile
{
    $samples = str_repeat(pack('v', 0), 800);
    $data = 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', strlen($samples)) . $samples;
    $path = tempnam(sys_get_temp_dir(), 'wav');
    file_put_contents($path, $data);

    return new UploadedFile($path, 'voice.wav', 'audio/wav', null, true);
}

test('only console staff open the console; the feed shows every ringing call and alarms when nobody answers', function () {
    $this->actingAs($this->desk)->get('/it/console')->assertForbidden();
    $this->actingAs($this->it)->get('/it/console')->assertOk()->assertSee('Call Console')->assertSee('Master');
    $this->actingAs($this->guard)->get('/it/console')->assertOk();

    auth()->logout();
    $call = ($this->call)('bar');
    $feed = $this->actingAs($this->it)->getJson('/it/console/feed')->assertOk()->json('data');
    expect($feed['calls'])->toHaveCount(1)->and($feed['calls'][0]['escalated'])->toBeFalse()->and($feed['can']['answer'])->toBeTrue();
    $this->travel(25)->seconds();
    expect($this->getJson('/it/console/feed')->json('data.calls.0.escalated'))->toBeTrue();
});

test('an operator picks up a call nobody answered; it is in the audit trail and the guest connects as usual', function () {
    $call = ($this->call)('bar');
    $this->postJson("{$this->api}/calls/{$call['uuid']}/signal", ['kind' => 'offer', 'payload' => ['type' => 'offer', 'sdp' => 'v=0']])->assertOk();
    $this->travel(25)->seconds();

    $this->actingAs($this->desk)->postJson("/it/console/calls/{$call['uuid']}/answer")->assertForbidden();
    $c = $this->actingAs($this->it)->postJson("/it/console/calls/{$call['uuid']}/answer")->assertOk()->json('data');
    expect($c['status'])->toBe('active')->and($c['i_offer'])->toBeFalse();
    expect($this->getJson("/it/console/calls/{$call['uuid']}?after=0")->json('data.signals.0.kind'))->toBe('offer');
    $this->postJson("/it/console/calls/{$call['uuid']}/signal", ['kind' => 'answer', 'payload' => ['type' => 'answer', 'sdp' => 'v=0']])->assertOk();
    expect(AuditLog::where('action', 'calls.pick_up')->where('user_id', $this->it->id)->exists())->toBeTrue();

    auth()->logout();
    $guest = $this->getJson("{$this->api}/calls/{$call['uuid']}?after=0")->json('data');
    expect($guest['answered_by'])->toBe('Ifeanyi')->and($guest['signals'][0]['kind'])->toBe('answer')
        ->and($guest['notice'])->toContain('supervisor');
});

test('a call is put through to the right person: it rings them, the guest reconnects on a new leg, old signals are refused', function () {
    $call = ($this->call)('bar');
    $this->actingAs($this->bar)->postJson("/chat/api/calls/{$call['uuid']}/answer")->assertOk();
    $targets = $this->getJson("/chat/api/calls/{$call['uuid']}/targets")->assertOk()->json('data');
    expect(collect($targets['people'])->pluck('label'))->toContain('Amaka Desk')->not->toContain('Tobi Bar');

    $this->postJson("/chat/api/calls/{$call['uuid']}/transfer", ['user_id' => $this->desk->id, 'note' => 'Late check-out question'])->assertOk()
        ->assertJsonPath('data.status', 'ringing')->assertJsonPath('data.leg', 2);
    expect(AuditLog::where('action', 'calls.transfer')->exists())->toBeTrue();
    // The bar's call screen sees it has moved on.
    expect($this->getJson("/chat/api/calls/{$call['uuid']}")->assertOk()->json('data.leg'))->toBe(2);
    $this->postJson("/chat/api/calls/{$call['uuid']}/answer")->assertForbidden();

    auth()->logout();
    $g = $this->getJson("{$this->api}/calls/{$call['uuid']}")->json('data');
    expect($g['leg'])->toBe(2)->and($g['i_offer'])->toBeTrue()->and($g['transfer']['to'])->toBe('Amaka');
    expect(ChatConversation::first()->messages()->latest('id')->value('body'))->toContain('put through to Amaka');
    $this->postJson("{$this->api}/calls/{$call['uuid']}/signal", ['kind' => 'offer', 'payload' => ['sdp' => 'old'], 'leg' => 1])->assertStatus(422);
    $this->postJson("{$this->api}/calls/{$call['uuid']}/signal", ['kind' => 'offer', 'payload' => ['sdp' => 'new'], 'leg' => 2])->assertOk();

    $this->actingAs($this->desk)->getJson('/chat/api/calls/ringing')->assertOk()->assertJsonPath('data.0.uuid', $call['uuid']);
    $this->postJson("/chat/api/calls/{$call['uuid']}/answer")->assertOk()->assertJsonPath('data.status', 'active');
    $sig = $this->getJson("/chat/api/calls/{$call['uuid']}?after=0")->json('data.signals');
    expect($sig)->toHaveCount(1)->and($sig[0]['payload']['sdp'])->toBe('new');
    // A transferred call rings for 45 s again, then is missed.
    expect(GuestCall::first()->answered_by)->toBe($this->desk->id);
});

test('a supervisor listens in with a reason: audited, the person on the call sees it, the sound comes from their browser', function () {
    $call = ($this->call)('bar');
    $this->actingAs($this->bar)->postJson("/chat/api/calls/{$call['uuid']}/answer")->assertOk();

    $this->actingAs($this->desk)->postJson("/it/console/calls/{$call['uuid']}/listen", ['reason' => 'Quality check'])->assertForbidden();
    $this->actingAs($this->guard)->postJson("/it/console/calls/{$call['uuid']}/listen", [])->assertStatus(422);
    $l = $this->postJson("/it/console/calls/{$call['uuid']}/listen", ['reason' => 'Security concern'])->assertCreated()->json('data');
    expect($l['status'])->toBe('waiting');
    $log = AuditLog::where('action', 'calls.listen')->first();
    expect($log->flagged)->toBeTrue()->and($log->reason)->toBe('Security concern')->and($log->user_id)->toBe($this->guard->id);

    // The bar's browser is asked for the sound and sends an offer to the listener.
    $view = $this->actingAs($this->bar)->getJson("/chat/api/calls/{$call['uuid']}")->json('data');
    expect($view['taps'])->toBe([$l['id']]);
    $this->postJson("/chat/api/calls/{$call['uuid']}/tap/{$l['id']}", ['kind' => 'offer', 'payload' => ['type' => 'offer', 'sdp' => 'tap']])->assertOk();
    // Someone else can't feed the listener.
    $this->actingAs($this->desk)->postJson("/chat/api/calls/{$call['uuid']}/tap/{$l['id']}", ['kind' => 'offer', 'payload' => []])->assertForbidden();

    $got = $this->actingAs($this->guard)->getJson("/it/console/listens/{$l['id']}?after=0")->json('data');
    expect($got['signals'][0]['payload']['sdp'])->toBe('tap');
    $this->postJson("/it/console/listens/{$l['id']}/signal", ['kind' => 'answer', 'payload' => ['type' => 'answer', 'sdp' => 'mon']])->assertOk();
    expect(CallListen::find($l['id'])->status)->toBe('live');
    $tap = $this->actingAs($this->bar)->getJson("/chat/api/calls/{$call['uuid']}")->json('data');
    expect($tap['tap_signals'][0]['kind'])->toBe('answer')->and($tap['listeners'])->toBe(['Musa Guard']);
    // Only the listener reads their listen.
    $this->getJson("/it/console/listens/{$l['id']}")->assertForbidden();

    // The call ends: listening stops and is logged with its length.
    auth()->logout();
    $this->travel(30)->seconds();
    $this->postJson("{$this->api}/calls/{$call['uuid']}/end")->assertOk();
    expect(CallListen::find($l['id'])->status)->toBe('ended')
        ->and(AuditLog::where('action', 'calls.listen_end')->value('description'))->toContain('30 s');
});

test('listening in can be switched off for the hotel', function () {
    config(['hotel.call_console.monitoring' => false]);
    $call = ($this->call)('bar');
    expect($call['notice'])->toBeNull();
    $this->actingAs($this->bar)->postJson("/chat/api/calls/{$call['uuid']}/answer")->assertOk();
    $this->actingAs($this->guard)->postJson("/it/console/calls/{$call['uuid']}/listen", ['reason' => 'Check'])->assertStatus(422);
});

test('guests and staff send voice messages; they play through a signed link, and the console plays them with an audit line', function () {
    Storage::fake('local');
    $rec = ChatSection::where('key', 'reception')->first();
    $m = $this->post("{$this->api}/chat/{$rec->id}", ['audio' => wav(), 'audio_seconds' => 7], ['Accept' => 'application/json'])->assertCreated()->json('data.message');
    expect($m['audio'])->toContain('/chat/audio/')->and($m['audio_seconds'])->toBe(7)->and($m['audio_label'])->toContain('0:07');
    expect(ChatConversation::first()->last_preview)->toContain('Voice message');
    $this->get($m['audio'])->assertOk()->assertHeader('Content-Type', 'audio/x-wav');
    $this->get(preg_replace('/signature=\w+/', 'signature=nope', $m['audio']))->assertForbidden();

    // Not sound: refused.
    $this->post("{$this->api}/chat/{$rec->id}", ['audio' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);

    $feed = $this->actingAs($this->guard)->getJson('/it/console/feed')->json('data.voice');
    expect($feed)->toHaveCount(1);
    $this->get($feed[0]['url'])->assertOk();
    expect(AuditLog::where('action', 'calls.voice_played')->where('user_id', $this->guard->id)->exists())->toBeTrue();
    $this->actingAs($this->desk)->get($feed[0]['url'])->assertForbidden();

    $conv = ChatConversation::first();
    $this->actingAs($this->desk)->post("/chat/api/{$conv->id}", ['audio' => wav(), 'audio_seconds' => 3], ['Accept' => 'application/json'])->assertCreated();
});

test('hardware reports equipment states with its hub key; silence becomes "no signal"; the board shows alarms', function () {
    $this->seed(CallConsoleDemoSeeder::class);
    $svc = app(FacilityService::class);
    [$hub, $key] = $svc->newHub('BMS', $this->it);

    $this->postJson('/api/v1/facility/report', ['devices' => [['code' => 'R101-AC', 'state' => 'on']]])->assertUnauthorized();
    $gen = FacilityDevice::where('name', 'Lift A')->first();
    $ac = FacilityDevice::whereNotNull('room_id')->where('kind', 'ac')->first();
    $res = $this->withToken($key)->postJson('/api/v1/facility/report', ['devices' => [
        ['code' => $ac->code, 'state' => 'running', 'reading' => '21°C'], ['code' => $gen->code, 'state' => 'alarm'], ['code' => 'NOPE-1', 'state' => 1],
    ]])->assertOk()->json('data');
    expect($res['updated'])->toBe(2)->and($res['unknown'])->toBe(['NOPE-1']);
    expect($ac->fresh()->state)->toBe('on')->and($ac->fresh()->reading)->toBe('21°C')->and($gen->fresh()->state)->toBe('fault');
    expect($gen->events()->latest('id')->value('from_state'))->toBe('on');
    $this->withToken($key)->getJson('/api/v1/facility/devices')->assertOk()->assertJsonFragment(['code' => $gen->code]);

    $this->travel(20)->minutes();
    expect($ac->fresh()->live_state)->toBe('offline');

    $board = $this->actingAs($this->it)->getJson('/it/console/board')->assertOk()->json('data.board');
    expect(collect($board['alarms'])->pluck('name'))->toContain('Lift A')
        ->and($board['floors'])->not->toBeEmpty()->and(collect($board['areas'])->pluck('name'))->toContain('Kitchen');

    // Switched by hand on the board (the guard can't).
    $this->actingAs($this->guard)->postJson("/it/equipment-board/{$gen->id}/state", ['state' => 'on'])->assertForbidden();
    $this->actingAs($this->it)->postJson("/it/equipment-board/{$gen->id}/state", ['state' => 'on'])->assertOk()->assertJsonPath('data.state', 'on');
    expect(AuditLog::where('action', 'facility.switch')->exists())->toBeTrue();
    $this->get('/it/equipment-board?place=areas')->assertOk()->assertSee('Lift A');

    // A revoked key stops working.
    $hub->update(['is_active' => false]);
    $this->withToken($key)->postJson('/api/v1/facility/report', ['devices' => [['code' => $gen->code, 'state' => 'off']]])->assertUnauthorized();
});

test('the usual equipment can be added to every room, and the console shows the weather', function () {
    $this->actingAs($this->it)->post('/it/equipment-board/standard')->assertRedirect();
    $rooms = \App\Models\Room::where('is_active', true)->count();
    expect(FacilityDevice::count())->toBe($rooms * 6);

    \App\Models\Hotel::query()->update(['latitude' => 6.45, 'longitude' => 3.39]);
    Http::fake(['api.open-meteo.com/*' => Http::response([
        'timezone' => 'Africa/Lagos',
        'current' => ['time' => now()->format('Y-m-d\TH:00'), 'temperature_2m' => 29.4, 'apparent_temperature' => 33, 'relative_humidity_2m' => 78, 'precipitation' => 0, 'weather_code' => 2, 'wind_speed_10m' => 12, 'is_day' => 1],
        'hourly' => ['time' => [now()->format('Y-m-d\TH:00'), now()->addHour()->format('Y-m-d\TH:00')], 'temperature_2m' => [29, 28], 'precipitation_probability' => [10, 40], 'weather_code' => [2, 61], 'is_day' => [1, 1]],
        'daily' => ['time' => [today()->toDateString()], 'weather_code' => [61], 'temperature_2m_max' => [31], 'temperature_2m_min' => [24], 'precipitation_probability_max' => [60], 'precipitation_sum' => [3], 'uv_index_max' => [8], 'wind_speed_10m_max' => [20], 'sunrise' => [today()->format('Y-m-d') . 'T06:40'], 'sunset' => [today()->format('Y-m-d') . 'T18:50']],
    ])]);
    $w = $this->getJson('/it/console/weather')->assertOk()->json('data');
    expect($w['available'])->toBeTrue()->and($w['now']['temp'])->toBe(29)->and($w['now']['summary'])->toBe('Partly cloudy')->and($w['days'][0]['max'])->toBe(31);
    $this->getJson('/it/console/board')->assertOk()->assertJsonStructure(['data' => ['network' => ['links', 'bars']]]);
});
