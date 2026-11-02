<?php

use App\Models\Events\Event;
use App\Models\Events\EventTicket;
use App\Models\Events\EventTicketOrder;
use App\Models\Events\Hall;
use App\Models\User;
use App\Services\Events\EventService;
use App\Services\Events\TicketService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    $this->withoutDefer();
    Mail::fake();
    config(['hotel.prices_include_tax' => true, 'services.paystack.secret_key' => 'sk_test_x', 'services.paystack.active' => true, 'hotel.currency' => 'NGN']);
    $this->travelTo(today()->setTime(9, 0));
    $hall = Hall::create(['name' => 'Grand Ballroom', 'capacity_theatre' => 10, 'capacity_banquet' => 8, 'hourly_rate' => 50000, 'full_day_rate' => 400000]);
    $this->e = app(EventService::class)->save([
        'title' => 'Jazz Night', 'event_type' => 'other', 'status' => 'tentative', 'hall_id' => $hall->id,
        'starts_at' => today()->addDays(10)->setTime(18, 0), 'ends_at' => today()->addDays(10)->setTime(22, 0), 'setup' => 'theatre', 'guests_expected' => 10,
        'organiser_name' => 'Hotel', 'hall_rate_type' => 'custom', 'hall_charge' => 0,
    ]);
    $e = $this->e;
    $this->post(route('events.status', $e), ['status' => 'confirmed']);
    $this->post(route('events.tickets.configure', $e), ['is_ticketed' => 1]);
    $this->post(route('events.tickets.types.store', $e), ['name' => 'Regular', 'price' => 10000, 'early_price' => 8000, 'early_until' => today()->addDays(3)->format('Y-m-d H:i'), 'max_per_order' => 6])->assertSessionHas('success');
    $this->post(route('events.tickets.types.store', $e), ['name' => 'VIP', 'price' => 25000, 'quantity' => 3])->assertSessionHas('success');
    $this->post(route('events.tickets.sales', $e), ['open' => 1])->assertSessionHas('success');
    $this->post(route('events.tickets.promos.store', $e), ['code' => 'jazz10', 'kind' => 'percent', 'value' => 10, 'max_uses' => 1])->assertSessionHas('success');
    $this->regular = $e->ticketTypes()->where('name', 'Regular')->first();
    $this->vip = $e->ticketTypes()->where('name', 'VIP')->first();
    auth()->logout();
});

function fakePaystack(float $amount, string $status = 'success'): void
{
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => $status, 'amount' => (int) round($amount * 100), 'currency' => 'NGN', 'channel' => 'card', 'id' => 99, 'paid_at' => now()->toIso8601String()]]),
    ]);
}

test('anyone buys online: early-bird price and a promo code, held while paying, tickets issued and e-mailed when Paystack confirms', function () {
    $e = $this->e;
    $this->get(route('tickets.shop', $e->id))->assertOk()->assertSee('Early bird until')->assertSee('Promo code');

    // 2 Regular at the early-bird ₦8,000 + 1 VIP ₦25,000 = ₦41,000, 10% off = ₦36,900.
    $q = $this->postJson(route('tickets.quote', $e->id), ['lines' => [$this->regular->id => 2, $this->vip->id => 1], 'promo' => 'Jazz10'])->assertOk()->json('data');
    expect($q['subtotal'])->toEqual(41000.0)->and($q['discount'])->toEqual(4100.0)->and($q['total'])->toEqual(36900.0);
    $this->postJson(route('tickets.quote', $e->id), ['lines' => [$this->regular->id => 1], 'promo' => 'NOPE'])->assertStatus(422);

    fakePaystack(36900);
    $res = $this->post(route('tickets.buy', $e->id), ['lines' => [$this->regular->id => 2, $this->vip->id => 1], 'promo' => 'jazz10', 'name' => 'Ada Obi', 'email' => 'ada@example.com']);
    $res->assertRedirect('https://checkout.paystack.com/abc');
    $order = EventTicketOrder::firstOrFail();
    expect($order->status)->toBe('pending')->and($order->tickets()->count())->toBe(0)->and((float) $order->total)->toEqual(36900.0);

    // Held: only 2 VIP left for anyone else while Ada pays.
    expect($this->vip->fresh()->left())->toBe(2);
    $this->get(route('tickets.mine', $order->token))->assertOk()->assertSee('Waiting for your payment');

    // Paystack sends her back: confirmed with Paystack, tickets issued at the prices she was shown, e-mailed.
    $this->get(route('tickets.callback', ['reference' => $order->gateway_reference]))->assertRedirect(route('tickets.mine', $order->token));
    $order->refresh();
    expect($order->status)->toBe('paid')->and($order->tickets()->count())->toBe(3)->and($order->emailed_at)->not->toBeNull()
        ->and($order->tickets()->where('ticket_type_id', $this->regular->id)->first()->price)->toEqual('8000.00')
        ->and($order->promo->used)->toBe(1);
    $this->get(route('tickets.mine', $order->token))->assertOk()->assertSee($order->tickets()->first()->code);

    // The webhook arriving too changes nothing; the promo is used up.
    app(TicketService::class)->settle($order->gateway_reference);
    expect(EventTicket::count())->toBe(3);
    $this->postJson(route('tickets.quote', $e->id), ['lines' => [$this->regular->id => 1], 'promo' => 'JAZZ10'])->assertStatus(422)->assertJsonPath('message', 'This code has been used up.');

    // A ticket from it works at the door (once the doors open).
    $this->travelTo($e->starts_at->copy()->subHour());
    $this->actingAs($this->admin);
    expect(app(TicketService::class)->admit($order->tickets()->first()->code)['state'])->toBe('admitted');
});

test('an order never paid lets its tickets go; free tickets are issued at once', function () {
    $e = $this->e;
    fakePaystack(25000, 'abandoned');
    $this->post(route('tickets.buy', $e->id), ['lines' => [$this->vip->id => 3], 'name' => 'Tunde', 'email' => 't@example.com'])->assertRedirect();
    expect($this->vip->fresh()->left())->toBe(0);
    $this->post(route('tickets.buy', $e->id), ['lines' => [$this->vip->id => 1], 'name' => 'Kemi', 'email' => 'k@example.com'])->assertSessionHas('error', 'VIP: all taken while other buyers pay — some may come back in a few minutes.');

    $o = EventTicketOrder::first();
    $this->get(route('tickets.callback', ['reference' => $o->gateway_reference]))->assertRedirect();
    expect($o->fresh()->status)->toBe('pending');

    $this->travelTo(now()->addMinutes(TicketService::HOLD_MINUTES + 1));
    expect(app(TicketService::class)->expire())->toBe(1)->and($this->vip->fresh()->left())->toBe(3);

    // Free: no payment page.
    $this->actingAs($this->admin);
    $this->post(route('events.tickets.types.store', $e), ['name' => 'Student', 'price' => 0])->assertSessionHas('success');
    auth()->logout();
    $student = $e->ticketTypes()->where('name', 'Student')->first();
    $this->post(route('tickets.buy', $e->id), ['lines' => [$student->id => 2], 'name' => 'Bisi', 'email' => 'b@example.com'])->assertRedirect();
    $free = EventTicketOrder::latest('id')->first();
    expect($free->status)->toBe('paid')->and($free->tickets()->count())->toBe(2)->and($free->emailed_at)->not->toBeNull();
});

test('the guest app buys online without a booking, and the staff app scans at the door', function () {
    $e = $this->e;
    $list = $this->getJson('/api/v1/guest/events')->assertOk()->json();
    $ev = collect($list['data'])->firstWhere('id', $e->id);
    expect($ev['has_promos'])->toBeTrue()->and($list['meta']['pay_online'])->toBeTrue()
        ->and(collect($ev['ticket_types'])->firstWhere('name', 'Regular'))->toMatchArray(['price' => 8000.0, 'early_until' => today()->addDays(3)->format('D j M')]);

    fakePaystack(8000);
    $r = $this->postJson("/api/v1/guest/events/{$e->id}/orders", ['lines' => [['ticket_type_id' => $this->regular->id, 'quantity' => 1]], 'name' => 'Ngozi', 'email' => 'n@example.com'])
        ->assertCreated()->json('data');
    expect($r['pay_url'])->toBe('https://checkout.paystack.com/abc')->and($r['status'])->toBe('pending')->and($r['held_until'])->not->toBeNull();
    app(TicketService::class)->settle(EventTicketOrder::where('token', $r['token'])->value('gateway_reference'));
    $o = $this->getJson("/api/v1/guest/ticket-orders/{$r['token']}")->assertOk()->json('data');
    expect($o['status'])->toBe('paid')->and($o['tickets'])->toHaveCount(1);

    // The staff app at the door.
    $door = User::create(['name' => 'Door', 'email' => 'door@test.local', 'password' => bcrypt('Secret@123'), 'must_change_password' => false]);
    $door->assignRole('Security Officer');
    $door->givePermissionTo('Check event tickets');
    \Laravel\Sanctum\Sanctum::actingAs($door, ['*']);
    $this->travelTo($e->starts_at->copy()->subHour());
    expect($this->getJson('/api/v1/tickets/door')->assertOk()->json('data.0.title'))->toBe('Jazz Night');
    $code = $o['tickets'][0]['code'];
    $this->postJson('/api/v1/tickets/scan', ['code' => url('/tickets/' . $code)])->assertOk()->assertJsonPath('data.state', 'admitted')->assertJsonPath('data.ticket.name', 'Ngozi');
    $this->postJson('/api/v1/tickets/scan', ['code' => $code])->assertOk()->assertJsonPath('data.state', 'used')->assertJsonPath('data.ok', false);
});
