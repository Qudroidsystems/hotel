<?php

use App\Enums\ReservationStatus;
use App\Models\Folio;
use App\Models\Pos\GuestOrder;
use App\Models\Pos\PosItem;
use App\Models\Pos\PosOrder;
use App\Models\Pos\PosOutlet;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\OnlineBookingService;
use App\Services\Mobile\StaffActions;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PosDemoSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class, PosDemoSeeder::class]);
    auth()->logout();
    $this->outlet = PosOutlet::where('code', 'RMSV')->first();
    $this->outlet->update(['guest_hours' => null]);   // always open, whatever time the tests run
    $this->r = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('rooms.room', 'guest')->first();
    $this->token = app(OnlineBookingService::class)->ensureToken($this->r);
    $this->item = fn (string $sku) => PosItem::where('sku', $sku)->first();
    $this->cashier = User::create(['name' => 'Ngozi Cashier', 'email' => 'ngozi@test.local', 'password' => bcrypt('Secret@123')]);
    $this->cashier->assignRole('Cashier');
});

test('a checked-in guest sees the menu and orders from the app at today’s prices', function () {
    $menu = $this->getJson("/api/v1/guest/stays/{$this->token}/menu")->assertOk()->json('data');
    expect($menu['can_order'])->toBeTrue()
        ->and(collect($menu['outlets'])->pluck('name')->all())->toBe(['Room Service'])
        ->and(collect($menu['outlets'][0]['categories'])->pluck('name'))->toContain('Mains');

    $club = ($this->item)('BF-CLUB');
    $res = $this->postJson("/api/v1/guest/stays/{$this->token}/orders", [
        'outlet_id' => $this->outlet->id, 'note' => 'Knock softly',
        'lines' => [['item_id' => $club->id, 'quantity' => 2, 'unit_price' => 1]],   // the phone's price is ignored
    ])->assertCreated();
    $order = GuestOrder::latest('id')->first();
    expect($order->status)->toBe('placed')->and((float) $order->total)->toBe(2 * (float) $club->price)
        ->and($res->json('data.orders.0.status_label'))->toBe('Sent — waiting for the kitchen')
        ->and($res->json('data.can_order'))->toBeTrue();

    // An item from another outlet's menu (cocktails aren't sold by room service) is refused.
    $this->postJson("/api/v1/guest/stays/{$this->token}/orders", ['outlet_id' => $this->outlet->id, 'lines' => [['item_id' => ($this->item)('CK-MOJI')->id, 'quantity' => 1]]])
        ->assertStatus(422);
});

test('accepting a room order puts it on the guest’s bill as a sale; then on its way and delivered', function () {
    $order = app(\App\Services\Pos\GuestOrderService::class)->place($this->r, $this->outlet->id, [['item_id' => ($this->item)('MN-JOLL')->id, 'quantity' => 1]]);
    $before = (float) (Folio::where('reservation_id', $this->r->id)->first()?->chargesTotal() ?? 0);

    $this->actingAs($this->cashier)->get(route('pos.room-orders'))->assertOk()->assertSee($order->number)->assertSee('Jollof rice');
    $this->post(route('pos.room-orders.act', [$order, 'accept']), ['note' => 'Coming right up'])->assertSessionHas('success');
    $order->refresh();
    $sale = PosOrder::find($order->pos_order_id);
    expect($order->status)->toBe('accepted')->and($sale->status)->toBe(PosOrder::CHARGED)->and($sale->reservation_id)->toBe($this->r->id)
        ->and((float) Folio::where('reservation_id', $this->r->id)->first()->chargesTotal())->toBe($before + (float) $sale->total);

    // A second press is refused, not charged twice.
    $this->post(route('pos.room-orders.act', [$order, 'accept']))->assertSessionHas('error');
    expect(PosOrder::where('uuid', $order->uuid)->count())->toBe(1);

    // The staff app does the rest.
    $actions = app(StaffActions::class);
    $item = collect($actions->work($this->cashier))->firstWhere('key', "order:{$order->id}");
    expect(collect($item['actions'])->pluck('id')->all())->toBe(['send', 'deliver']);
    $actions->perform($this->cashier, 'order', $order->id, 'send');
    $guest = $this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.orders.0');
    expect($guest['status_label'])->toBe('On its way to your room');
    $actions->perform($this->cashier, 'order', $order->id, 'deliver');
    expect($order->fresh()->status)->toBe('delivered');
});

test('a declined order is not charged and the guest sees why; a guest can cancel only while it waits', function () {
    $svc = app(\App\Services\Pos\GuestOrderService::class);
    $a = $svc->place($this->r, $this->outlet->id, [['item_id' => ($this->item)('GR-SUYA')->id, 'quantity' => 1]]);
    $b = $svc->place($this->r, $this->outlet->id, [['item_id' => ($this->item)('SD-COKE')->id, 'quantity' => 1]]);

    $this->actingAs($this->cashier)->post(route('pos.room-orders.act', [$a, 'decline']))->assertSessionHas('error');   // needs a reason
    $this->post(route('pos.room-orders.act', [$a, 'decline']), ['note' => 'The grill is closed tonight'])->assertSessionHas('success');
    expect($a->fresh()->status)->toBe('declined')->and($a->fresh()->pos_order_id)->toBeNull();

    auth()->logout();
    $this->postJson("/api/v1/guest/stays/{$this->token}/orders/{$b->id}/cancel")->assertOk();
    expect($b->fresh()->status)->toBe('cancelled');
    $orders = collect($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.orders'));
    expect($orders->firstWhere('id', $a->id)['note'])->toBe('The grill is closed tonight');
    $this->postJson("/api/v1/guest/stays/{$this->token}/orders/{$a->id}/cancel")->assertStatus(422);
});

test('only checked-in guests order, only when the outlet is open, and not too many at once', function () {
    $later = Reservation::where('status', ReservationStatus::Confirmed->value)->first();
    $t2 = app(OnlineBookingService::class)->ensureToken($later);
    expect($this->getJson("/api/v1/guest/stays/{$t2}/menu")->json('data.blocker'))->toBe('You can order once you’ve checked in.');

    $this->outlet->update(['guest_hours' => now()->addHours(2)->format('H:i') . '-' . now()->addHours(3)->format('H:i')]);
    expect($this->getJson("/api/v1/guest/stays/{$this->token}")->json('data.can_order'))->toBeFalse();
    $this->outlet->update(['guest_hours' => null]);

    $line = [['item_id' => ($this->item)('SD-WATR')->id, 'quantity' => 1]];
    foreach (range(1, 3) as $i) {
        $this->postJson("/api/v1/guest/stays/{$this->token}/orders", ['outlet_id' => $this->outlet->id, 'lines' => $line])->assertCreated();
    }
    $this->postJson("/api/v1/guest/stays/{$this->token}/orders", ['outlet_id' => $this->outlet->id, 'lines' => $line])->assertStatus(422);
});

test('the room QR card asks for the surname, then opens ordering; the web order page works', function () {
    $room = $this->r->rooms->first()->room;
    $this->get(route('room.card', $room->guest_code))->assertOk()->assertSee('Welcome to room ' . $room->number);
    $this->post(route('room.card.verify', $room->guest_code), ['name' => 'Nobody'])->assertSessionHas('error');
    $this->post(route('room.card.verify', $room->guest_code), ['name' => strtoupper($this->r->guest->last_name)])
        ->assertRedirect(route('stay.order', $this->token));
    $this->get(route('room.card', $room->guest_code))->assertRedirect(route('stay.order', $this->token));   // remembered

    $this->get(route('stay.order', $this->token))->assertOk()->assertSee('Order to your room')->assertSee('Jollof rice');
    $this->post(route('stay.order.place', $this->token), ['outlet_id' => $this->outlet->id, 'qty' => [($this->item)('MN-FISH')->id => 1, ($this->item)('SD-COKE')->id => 0]])
        ->assertRedirect(route('stay.order', $this->token))->assertSessionHas('success');
    expect(GuestOrder::latest('id')->first()->via)->toBe('room');

    // An empty room's card says so.
    $empty = \App\Models\Room::whereDoesntHave('reservationRooms')->first() ?? \App\Models\Room::where('status', 'available')->first();
    $this->get(route('room.card', $empty->guest_code))->assertOk()->assertSee('opens once you’ve checked in', false);

    $this->actingAs(User::role('Super Admin')->first())->get(route('rooms.qr-cards'))->assertOk()->assertSee('Scan to order to your room');
});
