<?php

use App\Enums\ReservationStatus;
use App\Models\Events\Event;
use App\Models\Events\EventItem;
use App\Models\Events\EventTicket;
use App\Models\Events\EventTicketOrder;
use App\Models\Events\Hall;
use App\Models\FolioCharge;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\OnlineBookingService;
use App\Services\Events\EventService;
use App\Services\NightAudit\NightAuditService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    $this->withoutDefer();
    config(['hotel.prices_include_tax' => true]);
    $this->travelTo(today()->setTime(9, 0));
    $this->hall = Hall::create(['name' => 'Grand Ballroom', 'capacity_theatre' => 10, 'capacity_banquet' => 8, 'hourly_rate' => 50000, 'full_day_rate' => 400000]);
    // A concert tonight, 18:00 – 22:00.
    $this->event = app(EventService::class)->save([
        'title' => 'Jazz Night', 'event_type' => 'other', 'status' => 'tentative', 'hall_id' => $this->hall->id,
        'starts_at' => today()->setTime(18, 0), 'ends_at' => today()->setTime(22, 0), 'setup' => 'theatre', 'guests_expected' => 10,
        'organiser_name' => 'Hotel', 'hall_rate_type' => 'custom', 'hall_charge' => 0,
    ]);
});

function newStaff(string $role): User
{
    $u = User::create(['name' => "{$role} person", 'email' => str($role)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
    $u->assignRole($role);

    return $u;
}

test('the hall is handed over and taken back; overtime and damage go on the bill and show in the usage report', function () {
    $e = $this->event;
    $this->post(route('events.check-in', $e))->assertSessionHas('error');   // only confirmed events
    $this->post(route('events.status', $e), ['status' => 'confirmed'])->assertSessionHas('success');

    $this->travelTo(today()->setTime(17, 30));
    $this->post(route('events.check-in', $e), ['notes' => 'Keys and two mics'])->assertSessionHas('success');
    $this->post(route('events.check-in', $e))->assertSessionHas('error');   // once
    $this->get(route('events.show', $e))->assertOk()->assertSee('Take the hall back');

    // Ran to 23:20: 80 minutes over → 2 hours at the hourly rate.
    $this->travelTo(today()->setTime(23, 20));
    $this->get(route('events.show', $e))->assertSee('Charge overtime: 2 h');
    $this->post(route('events.check-out', $e), ['guests_actual' => 9, 'hall_condition' => 'damaged'])->assertSessionHas('error');   // say what
    $this->post(route('events.check-out', $e), [
        'guests_actual' => 9, 'hall_condition' => 'damaged', 'charge_overtime' => 1,
        'damages' => [['description' => '2 broken chairs', 'amount' => 30000], ['description' => '', 'amount' => '']],
    ])->assertSessionHas('success');

    $e->refresh();
    expect($e->checked_out_at)->not->toBeNull()->and($e->guests_actual)->toBe(9)->and($e->hall_condition)->toBe('damaged')
        ->and($e->items->pluck('kind', 'description')->all())->toBe(['Overtime: 2 h past 10:00 PM' => 'overtime', '2 broken chairs' => 'damage'])
        ->and((float) $e->total)->toEqual(130000.0);
    $this->post(route('events.check-out', $e), ['hall_condition' => 'good'])->assertSessionHas('error');   // once

    $r = $this->get(route('events.usage', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))->assertOk()->assertSee('Grand Ballroom');
    $row = $r->viewData('report')['rows']->first();
    expect($row['events'])->toBe(1)->and($row['booked_hours'])->toEqual(4.0)->and($row['used_hours'])->toEqual(5.8)
        ->and($row['guests_actual'])->toBe(9)->and($row['overtime'])->toEqual(100000.0)->and($row['damages'])->toEqual(30000.0);
});

test('tickets are set up, sold at the desk within the hall’s capacity, and scanned once at the door', function () {
    $e = $this->event;
    $this->post(route('events.tickets.configure', $e), ['is_ticketed' => 1, 'ticket_info' => 'Doors 5:30 PM'])->assertSessionHas('success');
    $this->post(route('events.tickets.types.store', $e), ['name' => 'Regular', 'price' => 5000, 'max_per_order' => 6])->assertSessionHas('success');
    $this->post(route('events.tickets.types.store', $e), ['name' => 'Table for 4', 'price' => 30000, 'admits' => 4, 'quantity' => 1])->assertSessionHas('success');
    [$regular, $table] = $e->ticketTypes()->get()->all();

    $this->post(route('events.tickets.sales', $e), ['open' => 1])->assertSessionHas('error');   // not confirmed yet
    $this->post(route('events.status', $e), ['status' => 'confirmed']);
    $this->post(route('events.tickets.sales', $e), ['open' => 1])->assertSessionHas('success');

    $sell = fn (array $lines, array $over = []) => $this->post(route('events.tickets.sell', $e), $over + ['lines' => $lines, 'buyer_name' => 'Ada', 'buyer_phone' => '0803', 'method' => 'cash']);
    $sell([$regular->id => 7])->assertSessionHas('error');                       // more than one order may take
    $sell([$table->id => 1, $regular->id => 3], ['key' => 'k1'])->assertRedirect();
    $sell([$table->id => 1, $regular->id => 3], ['key' => 'k1']);                // sent twice: one sale
    $order = EventTicketOrder::sole();
    expect($order->quantity)->toBe(4)->and((float) $order->total)->toEqual(45000.0)->and($order->tickets)->toHaveCount(4)
        ->and($e->fresh()->ticketSeatsSold())->toBe(7);
    $sell([$table->id => 1])->assertSessionHas('error');                         // sold out
    $sell([$regular->id => 4])->assertSessionHas('error');                       // only 3 places left in the hall
    $sell([$regular->id => 3])->assertRedirect();

    $code = $order->tickets->first()->code;
    $this->get(route('events.tickets.order', $order))->assertOk()->assertSee($code);
    auth()->logout();
    $this->get(route('tickets.public', $code))->assertOk()->assertSee('Jazz Night')->assertSee('Doors 5:30 PM');
    $this->get('/tickets/TNOTAREALCODE')->assertNotFound();
    $this->actingAs(newStaff('Security Officer'));

    // At the door: too early, then valid once.
    $this->get(route('events.tickets.door', ['code' => $code]))->assertOk()->assertViewHas('result', fn ($r) => $r['state'] === 'early');
    $this->travelTo(today()->setTime(17, 45));
    $this->get(route('events.tickets.door', ['code' => route('tickets.public', $code)]))->assertViewHas('result', fn ($r) => $r['state'] === 'valid');   // a scanned link works too
    $this->post(route('events.tickets.admit'), ['code' => strtolower($code)])->assertSessionHas('ticket_result', fn ($r) => $r['state'] === 'admitted');
    $this->post(route('events.tickets.admit'), ['code' => $code])->assertSessionHas('ticket_result', fn ($r) => $r['state'] === 'used');
    $this->post(route('events.tickets.admit'), ['code' => 'TXXXXXXXXXXX'])->assertSessionHas('ticket_result', fn ($r) => $r['state'] === 'invalid');
    $this->post(route('events.tickets.sell', $e), ['lines' => [$regular->id => 1], 'buyer_name' => 'X', 'method' => 'cash'])->assertForbidden();   // security doesn't sell

    // An order with a used ticket can't be cancelled; another can, and its tickets stop working.
    $this->actingAs($this->admin);
    $this->post(route('events.tickets.cancel', $order), ['reason' => 'Changed mind'])->assertSessionHas('error');
    $other = EventTicketOrder::latest('id')->first();
    $this->post(route('events.tickets.cancel', $other), ['reason' => 'Changed mind'])->assertSessionHas('success');
    $this->post(route('events.tickets.admit'), ['code' => $other->tickets->first()->code])->assertSessionHas('ticket_result', fn ($r) => $r['state'] === 'void');

    // Ticket money is in the day's report: 45,000 + 15,000 sold − 15,000 refunded.
    expect(app(NightAuditService::class)->report(today())['revenue']['tickets'])->toBe(['count' => 2, 'revenue' => 45000.0]);
    $this->get(route('events.tickets.index'))->assertOk()->assertSee($order->number);
    $this->get(route('events.show', $e))->assertOk()->assertSee('Table for 4');
    $this->post(route('events.tickets.configure', $e), ['is_ticketed' => 0])->assertSessionHas('error');   // tickets sold
});

test('a staying guest buys tickets in the app, charged to the room; cancelling voids the charge', function () {
    $e = $this->event;
    $this->post(route('events.status', $e), ['status' => 'confirmed']);
    $this->post(route('events.tickets.configure', $e), ['is_ticketed' => 1]);
    $this->post(route('events.tickets.types.store', $e), ['name' => 'Regular', 'price' => 5000]);
    $this->post(route('events.tickets.sales', $e), ['open' => 1]);
    $type = $e->ticketTypes()->first();

    $r = Reservation::where('status', ReservationStatus::CheckedIn->value)->first();
    $token = app(OnlineBookingService::class)->ensureToken($r);
    auth()->logout();

    $list = $this->getJson("/api/v1/guest/stays/{$token}/events")->assertOk()->json('data');
    expect($list['can_buy'])->toBeTrue()->and($list['events'][0]['title'])->toBe('Jazz Night')->and($list['events'][0]['ticket_types'][0]['price'])->toEqual(5000);

    $res = $this->postJson("/api/v1/guest/stays/{$token}/tickets", ['event_id' => $e->id, 'lines' => [['ticket_type_id' => $type->id, 'quantity' => 2]], 'key' => 'abc'])->assertCreated();
    $this->postJson("/api/v1/guest/stays/{$token}/tickets", ['event_id' => $e->id, 'lines' => [['ticket_type_id' => $type->id, 'quantity' => 2]], 'key' => 'abc']);   // retried
    expect($res->json('data.tickets'))->toHaveCount(2);
    $order = EventTicketOrder::sole();
    $charge = FolioCharge::find($order->folio_charge_id);
    expect($order->channel)->toBe('app')->and($order->reservation_id)->toBe($r->id)->and((float) $charge->total)->toEqual(10000.0)
        ->and($charge->folio->reservation_id)->toBe($r->id);

    // A guest who hasn't arrived can't charge tickets.
    $pending = Reservation::where('status', ReservationStatus::Confirmed->value)->first();
    $t2 = app(OnlineBookingService::class)->ensureToken($pending);
    $this->postJson("/api/v1/guest/stays/{$t2}/tickets", ['event_id' => $e->id, 'lines' => [['ticket_type_id' => $type->id, 'quantity' => 1]]])->assertStatus(422);

    $this->actingAs($this->admin)->post(route('events.tickets.cancel', $order), ['reason' => 'Guest asked'])->assertSessionHas('success');
    expect($charge->fresh()->isVoided())->toBeTrue()->and(EventTicket::where('status', 'void')->count())->toBe(2);
});

test('front desk and cashiers sell tickets; only event managers set them up', function () {
    $e = $this->event;
    $cashier = newStaff('Cashier');
    $this->actingAs($cashier)->post(route('events.tickets.configure', $e), ['is_ticketed' => 1])->assertForbidden();
    $this->get(route('events.tickets.door'))->assertOk();
    $this->get(route('events.tickets.index'))->assertOk();
    $this->actingAs(newStaff('Front Desk'))->get(route('events.usage'))->assertOk();
    $this->post(route('events.check-in', $e))->assertSessionHas('error');   // allowed, but not confirmed
    expect(EventItem::KINDS)->toHaveKeys(['overtime', 'damage']);
});
