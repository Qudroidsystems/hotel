<?php

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\CashShift;
use App\Models\Folio;
use App\Models\Pos\PosItem;
use App\Models\Pos\PosOutlet;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Pos\PosService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PosDemoSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class, PosDemoSeeder::class]);
    auth()->logout();
    $this->cashier = User::create(['name' => 'Ada Cashier', 'email' => 'ada@test.local', 'password' => bcrypt('Secret@123')]);
    $this->cashier->assignRole('Cashier');
    $this->manager = User::create(['name' => 'Gbenga Manager', 'email' => 'gm@test.local', 'password' => bcrypt('Secret@123')]);
    $this->manager->assignRole('General Manager');
});

function barSale(User $cashier, string $method, float $price): void
{
    $outlet = PosOutlet::first();
    $item = PosItem::first();
    $r = app(PosService::class)->sync([[
        'uuid' => (string) Str::uuid(), 'number' => $outlet->code . '-T-' . random_int(1000, 9999), 'outlet_id' => $outlet->id,
        'ordered_at' => now()->toIso8601String(), 'method' => $method, 'cashier_id' => $cashier->id, 'device_id' => 'TEST',
        'items' => [['item_id' => $item->id, 'name' => $item->name, 'charge_type' => $item->charge_type->value, 'quantity' => 1, 'unit_price' => $price]],
    ]], $cashier);
    expect($r[0]["result"] ?? null)->not->toBe("rejected", $r[0]["message"] ?? "");
}

test('a shift expects the float plus cash taken, less paid-outs', function () {
    $this->actingAs($this->cashier)->post(route('cash-shifts.store'), ['station' => 'Main bar', 'opening_float' => 10000])->assertRedirect();
    $shift = CashShift::open()->where('user_id', $this->cashier->id)->firstOrFail();
    expect($shift->number)->toStartWith('SH-');

    $this->travel(1)->minutes();
    barSale($this->cashier, 'cash', 3000);
    barSale($this->cashier, 'card', 5000);
    app(FolioService::class)->receivePayment(Folio::where('status', 'open')->first(), PaymentKind::Payment, PaymentMethod::Cash, 20000);
    $this->post(route('cash-shifts.movement', $shift), ['type' => 'paid_out', 'amount' => 1500, 'reason' => 'Bought ice'])->assertRedirect();

    $totals = app(App\Services\Billing\CashShiftService::class)->totals($shift);
    $cashIn = $totals['methods']['cash']['net'];
    expect((float) $totals['expected_cash'])->toBe(round(10000 + $cashIn - 1500, 2))
        ->and($totals['methods'])->toHaveKey('card');

    // A count that is short by more than the tolerance needs a note.
    $this->post(route('cash-shifts.close', $shift), ['counted_cash' => $totals['expected_cash'] - 1000])->assertSessionHas('error');
    expect($shift->fresh()->isOpen())->toBeTrue();

    $this->post(route('cash-shifts.close', $shift), ['counted_cash' => $totals['expected_cash'] - 1000, 'close_note' => 'Gave wrong change'])
        ->assertRedirect(route('cash-shifts.show', $shift));

    $shift->refresh();
    expect($shift->status)->toBe(CashShift::CLOSED)
        ->and((float) $shift->variance)->toBe(-1000.0)
        ->and($shift->varianceLabel())->toBe('Short')
        ->and(AuditLog::where('action', 'billing.shift_closed')->value('flagged'))->toBeTrue();
});

test('close figures are frozen when a sale is voided later', function () {
    $this->actingAs($this->cashier)->post(route('cash-shifts.store'), ['station' => 'Main bar', 'opening_float' => 0]);
    $shift = CashShift::open()->firstOrFail();
    $this->travel(1)->minutes();
    barSale($this->cashier, 'cash', 4000);
    $expected = app(App\Services\Billing\CashShiftService::class)->totals($shift)['expected_cash'];
    $this->post(route('cash-shifts.close', $shift), ['counted_cash' => $expected]);

    $order = App\Models\Pos\PosOrder::where('cashier_id', $this->cashier->id)->latest('id')->first();
    app(PosService::class)->void($order, 'Wrong item', $this->manager);

    expect((float) app(App\Services\Billing\CashShiftService::class)->totals($shift->fresh())['expected_cash'])->toBe((float) $expected);
});

test('only one open shift per person', function () {
    $this->actingAs($this->cashier)->post(route('cash-shifts.store'), ['station' => 'Bar', 'opening_float' => 0]);
    $this->post(route('cash-shifts.store'), ['station' => 'Bar', 'opening_float' => 0])->assertSessionHas('error');

    expect(CashShift::where('user_id', $this->cashier->id)->count())->toBe(1);
});

test('a supervisor signs off, but nobody signs off their own shift', function () {
    $this->actingAs($this->manager)->post(route('cash-shifts.store'), ['station' => 'Front desk', 'opening_float' => 0]);
    $own = CashShift::open()->where('user_id', $this->manager->id)->first();
    $this->post(route('cash-shifts.close', $own), ['counted_cash' => 0]);
    $this->post(route('cash-shifts.review', $own))->assertSessionHas('error');

    $this->actingAs($this->cashier)->post(route('cash-shifts.store'), ['station' => 'Bar', 'opening_float' => 0]);
    $shift = CashShift::open()->where('user_id', $this->cashier->id)->first();
    $this->post(route('cash-shifts.close', $shift), ['counted_cash' => 0]);
    $this->post(route('cash-shifts.review', $shift))->assertForbidden();

    $this->actingAs($this->manager)->post(route('cash-shifts.review', $shift), ['review_note' => 'Cash received'])->assertSessionHas('success');
    expect($shift->fresh()->status)->toBe(CashShift::REVIEWED);
});

test('cashiers only see their own shifts; managers see the list', function () {
    $this->actingAs($this->manager)->post(route('cash-shifts.store'), ['station' => 'Front desk', 'opening_float' => 500]);
    $managers = CashShift::where('user_id', $this->manager->id)->first();

    $this->actingAs($this->cashier)->get(route('cash-shifts.index'))->assertOk()->assertSee('Start your shift')->assertDontSee($managers->number);
    $this->get(route('cash-shifts.show', $managers))->assertForbidden();

    $this->actingAs($this->manager)->get(route('cash-shifts.index'))->assertOk()->assertSee($managers->number)->assertSee('Close and count');
    $this->get(route('cash-shifts.show', [$managers, 'print' => 1]))->assertOk()->assertSee('Shift report');
});
