<?php

use App\Enums\ReservationStatus;
use App\Models\Folio;
use App\Models\FolioCharge;
use App\Models\Laundry\LaundryItem;
use App\Models\Laundry\LaundryOrder;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\OnlineBookingService;
use App\Services\Laundry\LaundryService;
use App\Services\Mobile\StaffActions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    app(LaundryService::class)->seedDefaults();
    $this->r = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('rooms.room', 'guest')->first();
    $this->token = app(OnlineBookingService::class)->ensureToken($this->r);
    $this->item = fn (string $name) => LaundryItem::where('name', $name)->first();
    $this->staff = User::create(['name' => 'Bisi Housekeeping', 'email' => 'bisi@test.local', 'password' => bcrypt('Secret@123')]);
    $this->staff->assignRole('Housekeeping');
    $this->charges = fn () => (float) (Folio::where('reservation_id', $this->r->id)->first()?->charges()->whereNull('voided_at')->where('type', 'laundry')->sum('total') ?? 0);
});

test('a guest sees the price list and sends a bag from the app; the phone’s prices are ignored', function () {
    $data = $this->getJson("/api/v1/guest/stays/{$this->token}/laundry")->assertOk()->json('data');
    expect($data['available'])->toBeTrue()
        ->and(collect($data['groups'])->pluck('name'))->toContain('Native wear')
        ->and(collect($data['groups'][0]['items'][0]['services'])->pluck('id')->all())->toBe(['wash', 'dry', 'press'])
        ->and($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.can_laundry'))->toBeTrue();

    $shirt = ($this->item)('Shirt');
    $before = ($this->charges)();
    $res = $this->postJson("/api/v1/guest/stays/{$this->token}/laundry", [
        'express' => true, 'note' => 'No starch',
        'lines' => [['item_id' => $shirt->id, 'service' => 'wash', 'quantity' => 3, 'unit_price' => 1], ['item_id' => $shirt->id, 'service' => 'wash', 'quantity' => 1]],
    ])->assertCreated();
    $o = LaundryOrder::latest('id')->first();
    expect($o->status)->toBe('requested')->and($o->lines)->toHaveCount(1)->and($o->pieces)->toBe(4)
        ->and((float) $o->total)->toBe(4 * 1500 * 1.5)
        ->and($res->json('data.orders.0.status_label'))->toBe('Waiting to be collected')
        ->and($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.laundry.0.number'))->toBe($o->number);

    // A service an item can't have is refused (no washing a suit).
    $this->postJson("/api/v1/guest/stays/{$this->token}/laundry", ['lines' => [['item_id' => ($this->item)('Suit (2 pieces)')->id, 'service' => 'wash', 'quantity' => 1]]])->assertStatus(422);
    expect(($this->charges)())->toBe($before);   // nothing charged until it is counted
});

test('collecting and counting a bag charges the count to the room; ready and returned follow', function () {
    $svc = app(LaundryService::class);
    $o = $svc->request($this->r, [['item_id' => ($this->item)('Shirt')->id, 'service' => 'wash', 'quantity' => 2]]);
    $before = ($this->charges)();

    $this->actingAs($this->staff)->get(route('laundry.index'))->assertOk()->assertSee($o->number);
    $this->get(route('laundry.collect.form', $o))->assertOk()->assertSee('Count ' . $o->number);
    // The bag held 3 shirts, not 2, and a suit for dry cleaning.
    $this->post(route('laundry.collect', $o), ['qty' => [($this->item)('Shirt')->id => ['wash' => 3], ($this->item)('Suit (2 pieces)')->id => ['dry' => 1]]])
        ->assertRedirect(route('laundry.index'));
    $o->refresh();
    expect($o->status)->toBe('collected')->and((float) $o->total)->toBe(3 * 1500 + 6000.0)
        ->and($o->staff_note)->toContain('We counted 4 pieces')
        ->and($o->due_at->diffInHours($o->collected_at, true))->toEqual(24.0)
        ->and(($this->charges)())->toBe($before + 10500.0)
        ->and(FolioCharge::find($o->folio_charge_id)->description)->toContain('1× Suit (2 pieces) (dry clean)');

    // Collecting twice is refused, never charged twice.
    $this->post(route('laundry.collect', $o), ['qty' => [($this->item)('Shirt')->id => ['wash' => 1]]])->assertSessionHas('error');
    expect(($this->charges)())->toBe($before + 10500.0);

    $this->post(route('laundry.act', [$o, 'ready']))->assertSessionHas('success');
    $guest = collect($this->getJson("/api/v1/guest/stays/{$this->token}/laundry")->json('data.orders'))->firstWhere('id', $o->id);
    expect($guest['status_label'])->toBe('Clean — on its way back to you')->and($guest['due'])->toStartWith('Back by');
    $this->post(route('laundry.act', [$o, 'issue']), ['note' => 'One shirt button missing'])->assertSessionHas('success');
    $this->post(route('laundry.act', [$o, 'returned']))->assertSessionHas('success');
    expect($o->fresh()->status)->toBe('returned')->and($o->fresh()->issue)->toBe('One shirt button missing');
});

test('the staff app collects a listed bag as listed, and declines with a reason the guest sees', function () {
    $svc = app(LaundryService::class);
    $listed = $svc->request($this->r, [['item_id' => ($this->item)('Trousers')->id, 'service' => 'press', 'quantity' => 2]]);
    $unlisted = $svc->request($this->r, []);
    $actions = app(StaffActions::class);
    $work = collect($actions->work($this->staff));

    expect(collect($work->firstWhere('key', "laundry:{$listed->id}")['actions'])->pluck('id')->all())->toBe(['collect', 'decline'])
        ->and(collect($work->firstWhere('key', "laundry:{$unlisted->id}")['actions'])->pluck('id')->all())->toBe(['decline']);   // counted on the website

    $msg = $actions->perform($this->staff, 'laundry', $listed->id, 'collect');
    expect($msg)->toContain('on room')->and($listed->fresh()->status)->toBe('collected')->and(($this->charges)())->toBeGreaterThanOrEqual(1600.0);
    $actions->perform($this->staff, 'laundry', $listed->id, 'ready');
    $actions->perform($this->staff, 'laundry', $listed->id, 'returned');
    expect($listed->fresh()->status)->toBe('returned');

    $actions->perform($this->staff, 'laundry', $unlisted->id, 'decline', 'Our laundry is closed today');
    $o = collect($this->getJson("/api/v1/guest/stays/{$this->token}/laundry")->json('data.orders'))->firstWhere('id', $unlisted->id);
    expect($o['status'])->toBe('declined')->and($o['note'])->toBe('Our laundry is closed today');
});

test('guests cancel only before collection, only in-house guests send laundry, and not too many bags at once', function () {
    $svc = app(LaundryService::class);
    $a = $svc->request($this->r, []);
    $this->postJson("/api/v1/guest/stays/{$this->token}/laundry/{$a->id}/cancel")->assertOk();
    expect($a->fresh()->status)->toBe('cancelled');

    $svc->request($this->r, []);
    $svc->request($this->r, []);
    $this->postJson("/api/v1/guest/stays/{$this->token}/laundry", [])->assertStatus(422);   // two bags already waiting

    $later = Reservation::where('status', ReservationStatus::Confirmed->value)->first();
    $t2 = app(OnlineBookingService::class)->ensureToken($later);
    expect($this->getJson("/api/v1/guest/stays/{$t2}/laundry")->json('data.blocker'))->toBe('You can send laundry once you’ve checked in.');
    $this->postJson("/api/v1/guest/stays/{$t2}/laundry", [])->assertStatus(422);

    config(['hotel.laundry.enabled' => false]);
    expect($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.can_laundry'))->toBeFalse();
});

test('a bag handed in at the desk is counted and charged at once; the web booking page sends laundry too', function () {
    $desk = User::create(['name' => 'Desk', 'email' => 'desk@test.local', 'password' => bcrypt('Secret@123')]);
    $desk->assignRole('Front Desk');
    $before = ($this->charges)();
    $this->actingAs($desk)->get(route('laundry.create'))->assertOk();
    $this->post(route('laundry.store'), ['reservation_id' => $this->r->id, 'express' => 1, 'qty' => [($this->item)('Dress')->id => ['dry' => 1]]])
        ->assertRedirect(route('laundry.index'));
    $o = LaundryOrder::latest('id')->first();
    expect($o->via)->toBe('desk')->and($o->status)->toBe('collected')->and(($this->charges)())->toBe($before + 6000.0);

    // A cashier can't handle laundry.
    $cashier = User::create(['name' => 'Cash', 'email' => 'cash@test.local', 'password' => bcrypt('Secret@123')]);
    $cashier->assignRole('Cashier');
    $this->actingAs($cashier)->get(route('laundry.index'))->assertForbidden();

    auth()->logout();
    $this->get(route('stay.show', $this->token))->assertOk()->assertSee('Laundry');
    $this->get(route('stay.laundry', $this->token))->assertOk()->assertSee('Collect my laundry');
    $this->post(route('stay.laundry.request', $this->token), ['note' => 'Hang please', 'qty' => [($this->item)('Shirt')->id => ['press' => 2]]])
        ->assertRedirect(route('stay.laundry', $this->token));
    expect(LaundryOrder::latest('id')->first()->via)->toBe('web');
});

test('the price list starts from a typical one and is kept by managers', function () {
    LaundryItem::query()->delete();
    $gm = User::create(['name' => 'GM', 'email' => 'gm@test.local', 'password' => bcrypt('Secret@123')]);
    $gm->assignRole('General Manager');
    $this->actingAs($gm)->get(route('laundry.prices'))->assertOk()->assertSee('Use a typical price list');
    $this->post(route('laundry.prices.defaults'))->assertSessionHas('success');
    $shirt = LaundryItem::where('name', 'Shirt')->first();
    $this->post(route('laundry.prices.save'), ['items' => [
        ['id' => $shirt->id, 'name' => 'Shirt', 'group' => 'Tops', 'wash_price' => 1800, 'dry_price' => '', 'press_price' => 900, 'is_active' => 1],
        ['name' => 'Kaftan', 'group' => 'Native wear', 'wash_price' => 2200],
        ['name' => 'No price', 'group' => 'Other'],
    ]])->assertSessionHas('error');   // every item needs a price
    $this->post(route('laundry.prices.save'), ['items' => [
        ['id' => $shirt->id, 'name' => 'Shirt', 'group' => 'Tops', 'wash_price' => 1800, 'dry_price' => '', 'press_price' => 900, 'is_active' => 1],
        ['name' => 'Kaftan', 'group' => 'Native wear', 'wash_price' => 2200],
    ]])->assertSessionHas('success');
    expect($shirt->fresh()->services())->toBe(['wash' => 1800.0, 'press' => 900.0])->and(LaundryItem::where('name', 'Kaftan')->exists())->toBeTrue();

    $this->actingAs($this->staff)->post(route('laundry.prices.save'), ['items' => []])->assertForbidden();   // housekeeping only handle bags
});
