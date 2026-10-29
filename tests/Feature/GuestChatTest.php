<?php

use App\Enums\ReservationStatus;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatSection;
use App\Models\Chat\GuestCall;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\Booking\OnlineBookingService;
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
    $this->cleaner = $make('Ngozi Clean', 'Housekeeping');
    $this->api = "/api/v1/guest/stays/{$this->token}";
});

function section(string $key): ChatSection
{
    app(\App\Services\Chat\ChatService::class)->sections();

    return ChatSection::where('key', $key)->firstOrFail();
}

test('a guest sees the sections, writes to Reception, and Reception answers; read ticks and unread counts follow', function () {
    $home = $this->getJson("{$this->api}/chat")->assertOk()->json('data');
    expect(collect($home['sections'])->pluck('key')->all())->toBe(['reception', 'restaurant', 'bar', 'laundry', 'housekeeping', 'concierge'])
        ->and($home['available'])->toBeTrue();

    $rec = section('reception');
    $id = (string) Str::uuid();
    $this->postJson("{$this->api}/chat/{$rec->id}", ['body' => 'Could I have a late check-out?', 'client_id' => $id])->assertCreated()->assertJsonPath('data.message.mine', true);
    // The same message sent again (a retry) is stored once.
    $this->postJson("{$this->api}/chat/{$rec->id}", ['body' => 'Could I have a late check-out?', 'client_id' => $id])->assertCreated();
    $conv = ChatConversation::first();
    expect($conv->messages()->count())->toBe(1)->and($conv->staff_unread)->toBe(1);
    Notification::assertSentTo($this->desk, StaffAlert::class);
    Notification::assertNotSentTo($this->bar, StaffAlert::class);

    // Reception's inbox has it; the bar's doesn't.
    $this->actingAs($this->bar)->getJson('/chat/api')->assertOk()->assertJsonCount(0, 'data.conversations');
    $this->actingAs($this->desk)->getJson('/chat/api')->assertOk()->assertJsonPath('data.conversations.0.unread', 1);
    $this->getJson("/chat/api/{$conv->id}")->assertOk()->assertJsonPath('data.messages.0.body', 'Could I have a late check-out?');
    expect($conv->fresh()->staff_unread)->toBe(0)->and($conv->messages()->first()->read_at)->not->toBeNull();
    $this->postJson("/chat/api/{$conv->id}", ['body' => 'Yes — until 2 PM at no charge.'])->assertCreated();

    auth()->logout();
    $thread = $this->getJson("{$this->api}/chat/{$rec->id}")->assertOk()->json('data');
    expect(collect($thread['messages'])->pluck('body')->all())->toBe(['Could I have a late check-out?', 'Yes — until 2 PM at no charge.'])
        ->and($thread['messages'][1]['name'])->toBe('Amaka')            // staff by first name only
        ->and($thread['read_up_to'])->toBe($thread['messages'][0]['id']);
    // The bar can't open Reception's chat.
    $this->actingAs($this->bar)->getJson("/chat/api/{$conv->id}")->assertForbidden();
});

test('a closed section says when it opens; photos are kept; managers set up sections', function () {
    Storage::fake('public');
    $laundry = section('laundry');
    $laundry->update(['opens' => now()->addHours(2)->format('H:i'), 'closes' => now()->addHours(5)->format('H:i')]);
    $this->post("{$this->api}/chat/{$laundry->id}", ['photo' => UploadedFile::fake()->image('stain.jpg')], ['Accept' => 'application/json'])->assertCreated();
    $msgs = ChatConversation::first()->messages()->orderBy('id')->get();
    expect($msgs)->toHaveCount(2)->and($msgs[0]->photo)->not->toBeNull()->and($msgs[1]->sender)->toBe('system')->and($msgs[1]->body)->toContain('closed now');
    Storage::disk('public')->assertExists($msgs[0]->photo);

    $gm = User::role('Super Admin')->first();
    $this->actingAs($gm)->get(route('chat.sections'))->assertOk()->assertSee('Laundry');
    $this->post(route('chat.sections.store'), ['name' => 'Spa', 'icon' => 'ri-leaf-line', 'roles' => ['Front Desk'], 'calls' => 1, 'is_active' => 1])->assertRedirect();
    expect(ChatSection::where('name', 'Spa')->first()->staffedBy($this->desk))->toBeTrue();
    $this->actingAs($this->desk)->get(route('chat.index'))->assertOk()->assertSee('Guest Chat');
    $this->actingAs($this->cleaner)->get(route('chat.sections'))->assertForbidden();
});

test('a guest calls the bar: it rings the bar staff, the first to answer takes it, signals pass both ways, and it is logged', function () {
    $bar = section('bar');
    $bar->update(['opens' => null, 'closes' => null]);
    $call = $this->postJson("{$this->api}/calls", ['section_id' => $bar->id])->assertCreated()->json('data');
    expect($call['status'])->toBe('ringing')->and($call['i_offer'])->toBeTrue()->and($call['ice_servers'])->not->toBeEmpty();
    Notification::assertSentTo($this->bar, StaffAlert::class, fn ($n) => str_contains(json_encode($n->toArray($this->bar)), 'Incoming call'));

    $this->postJson("{$this->api}/calls/{$call['uuid']}/signal", ['kind' => 'offer', 'payload' => ['type' => 'offer', 'sdp' => 'v=0...']])->assertOk();

    $this->actingAs($this->bar)->getJson('/chat/api/calls/ringing')->assertOk()->assertJsonPath('data.0.uuid', $call['uuid']);
    $this->actingAs($this->desk)->postJson("/chat/api/calls/{$call['uuid']}/answer")->assertForbidden();   // not her section
    $answered = $this->actingAs($this->bar)->postJson("/chat/api/calls/{$call['uuid']}/answer")->assertOk()->json('data');
    expect($answered['status'])->toBe('active')->and($answered['i_offer'])->toBeFalse();
    $seen = $this->getJson("/chat/api/calls/{$call['uuid']}?after=0")->json('data.signals');
    expect($seen[0]['kind'])->toBe('offer');
    $this->postJson("/chat/api/calls/{$call['uuid']}/signal", ['kind' => 'answer', 'payload' => ['type' => 'answer', 'sdp' => 'v=0...']])->assertOk();

    auth()->logout();
    expect($this->getJson("{$this->api}/calls/{$call['uuid']}?after=0")->json('data.signals.0.kind'))->toBe('answer');
    $this->travel(75)->seconds();
    $this->postJson("{$this->api}/calls/{$call['uuid']}/end")->assertOk()->assertJsonPath('data.status', 'ended');
    $last = ChatConversation::first()->messages()->latest('id')->first();
    expect($last->sender)->toBe('system')->and($last->body)->toContain('Call with Tobi')->toContain('1 min');
});

test('an unanswered call is missed after 45 seconds; staff call back and the guest answers', function () {
    $rec = section('reception');
    $call = $this->postJson("{$this->api}/calls", ['section_id' => $rec->id])->assertCreated()->json('data');
    $this->travel(50)->seconds();
    expect($this->getJson("{$this->api}/calls/{$call['uuid']}")->json('data.status'))->toBe('missed');
    $conv = ChatConversation::first();
    expect($conv->messages()->latest('id')->value('body'))->toContain('Missed call');

    $back = $this->actingAs($this->desk)->postJson("/chat/api/{$conv->id}/call")->assertCreated()->json('data');
    auth()->logout();
    expect($this->getJson("{$this->api}/calls/ringing")->json('data.uuid'))->toBe($back['uuid']);
    $this->postJson("{$this->api}/calls/{$back['uuid']}/answer")->assertOk()->assertJsonPath('data.status', 'active');
});

test('with a real-time server, messages are pushed and sockets are signed for the right people only', function () {
    config(['services.realtime' => ['enabled' => true, 'app_id' => 'hotel', 'key' => 'k123', 'secret' => 's3cret', 'host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'client_host' => 'hotel.test', 'client_port' => 443, 'client_scheme' => 'https']]);
    Http::fake(['127.0.0.1:8080/*' => Http::response(['ok' => true])]);
    $rec = section('reception');
    $home = $this->getJson("{$this->api}/chat")->json('data.live');
    expect($home['url'])->toStartWith('wss://hotel.test/app/k123')->and($home)->not->toHaveKey('secret');
    $this->postJson("{$this->api}/chat/{$rec->id}", ['body' => 'Hello'])->assertCreated();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/apps/hotel/events') && str_contains($r->body(), 'private-section-' . $rec->id) && str_contains($r->url(), 'auth_signature='));

    $chan = \App\Services\Realtime\Realtime::stay($this->token);
    $sig = $this->postJson('/api/v1/realtime/auth', ['socket_id' => '123.456', 'channel_name' => $chan, 'token' => $this->token])->assertOk()->json('auth');
    expect($sig)->toBe('k123:' . hash_hmac('sha256', "123.456:{$chan}", 's3cret'));
    $other = app(OnlineBookingService::class)->ensureToken(Reservation::where('id', '!=', $this->r->id)->first());
    $this->postJson('/api/v1/realtime/auth', ['socket_id' => '123.456', 'channel_name' => $chan, 'token' => $other])->assertForbidden();
    $this->actingAs($this->bar)->postJson(route('realtime.auth'), ['socket_id' => '1.2', 'channel_name' => 'private-section-' . $rec->id])->assertForbidden();
    $this->actingAs($this->desk)->postJson(route('realtime.auth'), ['socket_id' => '1.2', 'channel_name' => 'private-section-' . $rec->id])->assertOk();
});
