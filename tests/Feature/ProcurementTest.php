<?php

use App\Models\AuditLog;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\PurchaseRequest;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockReceipt;
use App\Models\Inventory\Supplier;
use App\Models\Inventory\SupplierInvoice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Database\Seeders\PosDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, PosDemoSeeder::class, InventoryDemoSeeder::class]);
    auth()->logout();
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->bar = $make('Kemi Bar', 'Bar Manager');
    $this->store = $make('Sola Store', 'Storekeeper');
    $this->gm = $make('Gbenga Manager', 'General Manager');
    $this->gm2 = $make('Ngozi Manager', 'General Manager');
    $this->accounts = $make('Tunde Accounts', 'Accountant');
    $this->item = StockItem::where('name', 'Star Lager 60cl')->firstOrFail();
    $this->supplier = Supplier::firstOrFail();
    $this->main = StockLocation::where('code', 'MAIN')->firstOrFail();
});

test('a department request is approved by someone else and turned into a purchase order', function () {
    $this->actingAs($this->bar)->get(route('inventory.requests.create'))->assertOk();
    $this->post(route('inventory.requests.store'), [
        'department' => 'Bar', 'reason' => 'Football final on Sunday',
        'lines' => [['item_id' => $this->item->id, 'quantity' => 48, 'unit_cost' => '']],
    ])->assertRedirect();
    $pr = PurchaseRequest::firstOrFail();
    expect($pr->number)->toStartWith('PR-')->and($pr->status)->toBe('submitted')
        ->and((float) $pr->estimated_total)->toBe(48 * 980.0);   // last price paid

    // The requester can't approve, even with the permission.
    $this->post(route('inventory.requests.decide', $pr), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($this->gm)->post(route('inventory.requests.decide', $pr), ['decision' => 'reject'])->assertSessionHas('error');   // needs a note
    $this->post(route('inventory.requests.decide', $pr), ['decision' => 'approve', 'note' => 'OK'])->assertSessionHas('success');
    expect($pr->fresh()->status)->toBe('approved');

    $this->actingAs($this->store)->post(route('inventory.requests.order', $pr), ['supplier_id' => $this->supplier->id, 'location_id' => $this->main->id])->assertRedirect();
    $pr->refresh();
    expect($pr->status)->toBe('ordered')->and($pr->purchaseOrder->status)->toBe('draft')->and($pr->purchaseOrder->lines)->toHaveCount(1);

    // The bar manager only sees their own requests.
    $this->actingAs($this->bar)->get(route('inventory.requests.index', ['status' => 'ordered']))->assertOk()->assertSee($pr->number);
});

test('orders over the limit need approval from someone who did not raise them', function () {
    config(['hotel.procurement.approval_limit' => 50000]);
    $this->actingAs($this->gm)->post(route('inventory.po.store'), [
        'supplier_id' => $this->supplier->id, 'location_id' => $this->main->id, 'send' => 1,
        'lines' => [['item_id' => $this->item->id, 'quantity' => 100, 'unit_cost' => 1200]],   // 22% above the last 980
    ])->assertRedirect();
    $po = PurchaseOrder::latest('id')->firstOrFail();
    expect($po->status)->toBe('pending')
        ->and(AuditLog::where('action', 'inventory.po_submitted')->where('flagged', true)->exists())->toBeTrue();

    $this->get(route('inventory.po.show', $po))->assertOk()->assertSee('another manager has to approve')->assertSee('+22.4%');
    $this->post(route('inventory.po.order', $po))->assertSessionHas('error');
    $this->post(route('inventory.po.approve', $po))->assertSessionHas('error');                    // own order
    $this->actingAs($this->store)->post(route('inventory.po.approve', $po))->assertForbidden();   // no approval right

    $this->actingAs($this->gm2)->post(route('inventory.po.reject', $po), ['reason' => 'Get a second quote'])->assertSessionHas('success');
    expect($po->fresh()->status)->toBe('draft')->and($po->fresh()->rejection_reason)->toBe('Get a second quote');

    $this->actingAs($this->gm)->post(route('inventory.po.submit', $po))->assertSessionHas('success');
    $this->actingAs($this->gm2)->post(route('inventory.po.approve', $po), ['send' => 1])->assertSessionHas('success');
    expect($po->fresh()->status)->toBe('ordered')->and($po->fresh()->approved_by)->toBe($this->gm2->id);

    // Small orders go straight out.
    $this->actingAs($this->store)->post(route('inventory.po.store'), [
        'supplier_id' => $this->supplier->id, 'location_id' => $this->main->id, 'send' => 1,
        'lines' => [['item_id' => $this->item->id, 'quantity' => 10, 'unit_cost' => 980]],
    ]);
    expect(PurchaseOrder::latest('id')->first()->status)->toBe('ordered');
});

test('refused goods stay outstanding, and the supplier bill is checked and paid', function () {
    $this->actingAs($this->store);
    $po = app(\App\Services\Inventory\StockService::class)->savePurchaseOrder(null, ['supplier_id' => $this->supplier->id, 'location_id' => $this->main->id],
        [['item_id' => $this->item->id, 'quantity' => 24, 'unit_cost' => 1000]]);
    app(\App\Services\Inventory\StockService::class)->markOrdered($po);
    $before = $this->item->fresh()->onHand();

    $this->post(route('inventory.receipts.store'), ['purchase_order_id' => $po->id, 'received_on' => today()->toDateString(), 'lines' => [
        ['item_id' => $this->item->id, 'quantity' => 20, 'unit_cost' => 1000, 'purchase_order_line_id' => $po->lines[0]->id, 'rejected_quantity' => 4, 'reject_reason' => ''],
    ]])->assertSessionHas('error');   // needs a reason
    $this->post(route('inventory.receipts.store'), ['purchase_order_id' => $po->id, 'received_on' => today()->toDateString(), 'lines' => [
        ['item_id' => $this->item->id, 'quantity' => 20, 'unit_cost' => 1000, 'purchase_order_line_id' => $po->lines[0]->id, 'rejected_quantity' => 4, 'reject_reason' => 'Broken bottles'],
    ]])->assertRedirect();
    $receipt = StockReceipt::latest('id')->firstOrFail();
    expect($this->item->fresh()->onHand())->toBe($before + 20)
        ->and($po->fresh()->status)->toBe('partial')
        ->and((float) $receipt->total)->toBe(20000.0);
    $this->get(route('inventory.receipts.show', $receipt))->assertSee('refused: Broken bottles');

    // Billed for all 24: flagged as not matching the delivery.
    $this->post(route('inventory.invoices.store'), ['stock_receipt_id' => $receipt->id, 'invoice_number' => 'INV-9', 'invoice_date' => today()->toDateString(), 'amount' => 24000])->assertRedirect();
    $inv = SupplierInvoice::where('invoice_number', 'INV-9')->firstOrFail();
    expect($inv->supplier_id)->toBe($this->supplier->id)->and($inv->due_date->toDateString())->toBe(today()->addDays(30)->toDateString())
        ->and(AuditLog::where('action', 'inventory.invoice_recorded')->where('flagged', true)->exists())->toBeTrue();
    $this->get(route('inventory.invoices.show', $inv))->assertSee('more than the goods received');
    $this->post(route('inventory.invoices.store'), ['stock_receipt_id' => $receipt->id, 'invoice_number' => 'INV-9', 'invoice_date' => today()->toDateString(), 'amount' => 1])->assertSessionHas('error');

    // Storekeeper records bills but can't pay them.
    $this->post(route('inventory.invoices.pay', $inv), ['amount' => 100, 'method' => 'cash', 'paid_on' => today()->toDateString()])->assertForbidden();

    $this->actingAs($this->accounts)->post(route('inventory.invoices.pay', $inv), ['amount' => 30000, 'method' => 'bank_transfer', 'paid_on' => today()->toDateString()])->assertSessionHas('error');
    $this->post(route('inventory.invoices.pay', $inv), ['amount' => 10000, 'method' => 'bank_transfer', 'reference' => 'TRF1', 'paid_on' => today()->toDateString()])->assertSessionHas('success');
    expect($inv->fresh()->status)->toBe('part_paid')->and($inv->fresh()->balance())->toBe(14000.0)
        ->and($this->supplier->balance())->toBeGreaterThanOrEqual(14000.0);
    $this->post(route('inventory.invoices.void', $inv), ['reason' => 'x'])->assertSessionHas('error');   // money already paid
    $this->post(route('inventory.invoices.pay', $inv), ['amount' => 14000, 'method' => 'cash', 'paid_on' => today()->toDateString()])->assertSessionHas('success');
    expect($inv->fresh()->status)->toBe('paid');

    $this->get(route('inventory.invoices.index', ['show' => 'paid']))->assertOk()->assertSee('INV-9');
    $this->actingAs($this->gm)->get(route('inventory.items.show', $this->item))->assertOk()->assertSee('Prices paid');
    $this->get(route('inventory.suppliers.index'))->assertOk()->assertSee('Owed');
});
