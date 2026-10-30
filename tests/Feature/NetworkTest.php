<?php

use App\Enums\ReservationStatus;
use App\Models\FolioCharge;
use App\Models\It\ItAlert;
use App\Models\Network\NetworkLink;
use App\Models\Network\NetworkPass;
use App\Models\Network\NetworkPlan;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\OnlineBookingService;
use App\Services\Network\Gateways\Gateway;
use App\Services\Network\NetworkService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\NetworkDemoSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/** A router that answers whatever the test tells it to, and remembers what it was sent. */
class FakeGateway implements Gateway
{
    public array $readings = [];
    public array $pushed = [];
    public array $removed = [];

    public function label(): string { return 'Fake router'; }
    public function live(): bool { return true; }
    public function readLink($link): array
    {
        return ($this->readings[$link->id] ?? []) + ['status' => 'online', 'signal' => 90, 'down_mbps' => 150.0, 'up_mbps' => 15.0, 'latency_ms' => 30,
            'packet_loss' => 0.1, 'obstruction_pct' => 0.2, 'used_mb' => 512.0, 'message' => null];
    }
    public function readPasses(iterable $passes): array
    {
        return collect($passes)->mapWithKeys(fn ($p) => [$p->id => ['used_mb' => 100.0, 'devices_online' => 2]])->all();
    }
    public function pushPass($pass): void { $this->pushed[] = $pass->code; }
    public function removePass($pass): void { $this->removed[] = $pass->code; }
}

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    Mail::fake();
    $this->router = new FakeGateway;
    app()->instance(NetworkService::class, $svc = app()->make(NetworkService::class));
    $svc->useGateway($this->router);
    $this->net = $svc;
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->it = $make('Ife IT', 'IT Operations');
    $this->desk = $make('Ada Desk', 'Front Desk');
    $this->cashier = $make('Kunle Cashier', 'Cashier');
    $this->standard = NetworkPlan::create(['name' => 'Standard', 'down_mbps' => 10, 'up_mbps' => 3, 'devices' => 3, 'is_default' => true]);
    $this->premium = NetworkPlan::create(['name' => 'Premium', 'down_mbps' => 30, 'up_mbps' => 8, 'devices' => 5, 'price' => 5000]);
});

test('lines are read, a dropped Starlink opens an alert that closes when it is back, and the console gets a summary', function () {
    $a = NetworkLink::create(['name' => 'Starlink 1', 'provider' => 'starlink', 'role' => 'shared']);
    $b = NetworkLink::create(['name' => 'Starlink 2', 'provider' => 'starlink', 'role' => 'guests']);

    expect($this->net->poll())->toBe(2);
    $a->refresh();
    expect($a->status)->toBe('online')->and($a->down_mbps)->toBe(150.0)->and($a->used_gb_month)->toBe(0.5)->and($a->samples()->count())->toBe(1);

    $this->router->readings[$b->id] = ['status' => 'offline', 'signal' => 0, 'down_mbps' => 0.0, 'message' => 'No signal'];
    $this->net->poll();
    $alert = ItAlert::firstOrFail();
    expect($alert->title)->toBe('Starlink 2 is OFFLINE')->and($alert->severity)->toBe('high')->and($alert->message)->toContain('1 other line(s)');

    $s = $this->net->consoleSummary();
    expect($s['overall'])->toMatchArray(['status' => 'degraded', 'online' => 1, 'total' => 2, 'down_mbps' => 150.0, 'live' => true])
        ->and($s['links'][1])->toMatchArray(['name' => 'Starlink 2', 'status' => 'offline']);

    $this->net->poll();   // still down: no second alert
    expect(ItAlert::count())->toBe(1);
    unset($this->router->readings[$b->id]);
    $this->net->poll();
    expect($alert->fresh()->status)->toBe('resolved')->and($alert->fresh()->auto_resolved)->toBeTrue()->and($b->fresh()->alert_id)->toBeNull();

    $this->actingAs($this->it)->get(route('it.network.index'))->assertOk()->assertSee('Starlink 1')->assertSee('Starlink 2')->assertSee('150');
    $this->getJson(route('it.network.summary'))->assertOk()->assertJsonPath('overall.status', 'online')->assertJsonCount(2, 'links');
});

test('a guest gets a Wi-Fi code at check-in, sees it on their booking page and in the app, and it stops at check-out', function () {
    $r = Reservation::with(['guest', 'rooms.room'])->where('status', ReservationStatus::Confirmed->value)->first();
    $r->forceFill(['check_in_date' => today(), 'check_out_date' => today()->addDays(2)])->save();
    $token = app(OnlineBookingService::class)->ensureToken($r);

    $charges = FolioCharge::count();
    $r->forceFill(['status' => ReservationStatus::CheckedIn])->save();
    $pass = NetworkPass::where('reservation_id', $r->id)->firstOrFail();
    expect($pass->status)->toBe('active')->and($pass->plan_id)->toBe($this->standard->id)->and($pass->devices)->toBe(3)
        ->and($pass->holder)->toBe($r->guest->full_name)->and($pass->expires_at->toDateString())->toBe(today()->addDays(2)->toDateString())
        ->and($this->router->pushed)->toContain($pass->code)->and(strlen($pass->code))->toBe(8);
    expect(FolioCharge::count())->toBe($charges);   // the free plan adds nothing to the bill

    $this->get(route('stay.show', $token))->assertOk()->assertSee($pass->code)->assertSee('Hotel Guest');
    $this->getJson("/api/v1/guest/stays/{$token}")->assertOk()->assertJsonPath('data.wifi.code', $pass->code)->assertJsonPath('data.wifi.devices', 3);

    // Checking in again (e.g. a status flip) doesn't give a second code.
    expect($this->net->issueForReservation($r->fresh())->id)->toBe($pass->id);

    $r->forceFill(['status' => ReservationStatus::CheckedOut])->save();
    expect($pass->fresh()->status)->toBe('expired')->and($this->router->removed)->toContain($pass->code);
    $this->getJson("/api/v1/guest/stays/{$token}")->assertOk()->assertJsonPath('data.wifi', null);
});

test('front desk gives a paid plan charged to the room, changes it and cuts it off; cashiers cannot', function () {
    $r = Reservation::with(['guest', 'rooms.room'])->where('status', ReservationStatus::Confirmed->value)->first();
    $r->forceFill(['check_in_date' => today(), 'check_out_date' => today()->addDays(2)])->save();
    config(['hotel.network.auto_issue' => false]);
    $r->forceFill(['status' => ReservationStatus::CheckedIn])->save();
    expect(NetworkPass::count())->toBe(0);

    $this->actingAs($this->cashier)->get(route('it.network.index'))->assertForbidden();
    $this->actingAs($this->desk)->get(route('it.network.index'))->assertOk()->assertSee('have no Wi-Fi code');

    $this->post(route('it.network.passes.store'), ['reservation_id' => $r->id, 'plan_id' => $this->premium->id, 'charge' => 1])->assertSessionHas('success');
    $pass = NetworkPass::firstOrFail();
    $charge = FolioCharge::findOrFail($pass->folio_charge_id);
    expect($pass->down_mbps)->toBe(30.0)->and($pass->devices)->toBe(5)->and($charge->description)->toContain('Wi-Fi: Premium')->and((float) $charge->unit_price)->toBe(5000.0);
    $this->get(route('it.network.passes.print', $pass))->assertOk()->assertSee($pass->code);

    $this->put(route('it.network.passes.update', $pass), ['plan_id' => $this->standard->id, 'devices' => 2])->assertSessionHas('success');
    expect($pass->fresh()->down_mbps)->toBe(10.0)->and($pass->fresh()->devices)->toBe(2);

    // A visitor code lasts a day by default.
    $this->post(route('it.network.passes.store'), ['holder' => 'Restaurant table 4', 'kind' => 'visitor', 'plan_id' => $this->standard->id])->assertSessionHas('success');
    $visitor = NetworkPass::where('holder', 'Restaurant table 4')->firstOrFail();
    expect($visitor->expires_at->diffInHours(now(), true))->toBeGreaterThan(23);

    $this->post(route('it.network.passes.revoke', $pass), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('it.network.passes.revoke', $pass), ['reason' => 'Shared the code'])->assertSessionHas('success');
    expect($pass->fresh()->status)->toBe('revoked')->and($pass->fresh()->revoked_by)->toBe($this->desk->id);

    // Lines and plans are for IT.
    $this->post(route('it.network.links.store'), ['name' => 'X', 'provider' => 'starlink', 'role' => 'shared'])->assertForbidden();
    $this->actingAs($this->it)->post(route('it.network.links.store'), ['name' => 'Starlink 3', 'provider' => 'starlink', 'role' => 'staff', 'data_cap_gb' => 1000])->assertSessionHas('success');
    $this->post(route('it.network.plans.store'), ['name' => 'Business', 'down_mbps' => 50, 'up_mbps' => 10, 'devices' => 6, 'is_default' => 1])->assertSessionHas('success');
    expect($this->standard->fresh()->is_default)->toBeFalse();
    $this->delete(route('it.network.plans.destroy', $this->standard))->assertSessionHas('success');
    expect($this->standard->fresh()->is_active)->toBeFalse();   // used, so switched off rather than deleted
    $unused = NetworkPlan::create(['name' => 'Spare', 'down_mbps' => 1, 'up_mbps' => 1, 'devices' => 1]);
    $this->delete(route('it.network.plans.destroy', $unused))->assertSessionHas('success');
    expect(NetworkPlan::find($unused->id))->toBeNull();
});

test('expired codes are closed by the poll and the demo seeder fills the page', function () {
    $p = $this->net->issue(['holder' => 'Visitor', 'plan_id' => $this->standard->id, 'expires_at' => now()->addMinutes(5)]);
    $this->travel(10)->minutes();
    $this->net->poll();
    expect($p->fresh()->status)->toBe('expired');

    config(['hotel.network.driver' => 'demo']);
    NetworkPlan::query()->delete();
    $this->seed(NetworkDemoSeeder::class);
    expect(NetworkLink::count())->toBe(4)->and(NetworkLink::first()->samples()->count())->toBeGreaterThan(100);
    $this->actingAs($this->it)->get(route('it.network.index'))->assertOk()->assertSee('Starlink 1 – Main roof')->assertSee('Demo data')->assertSee('Lagos Tech Week');
});
