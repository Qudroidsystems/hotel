<?php

use App\Enums\ChargeType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Models\AuditLog;
use App\Models\Folio;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Inventory\StockService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\InventoryDemoSeeder;
use Database\Seeders\PosDemoSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    $this->admin = User::role('Super Admin')->first();
    $this->admin->forceFill(['must_change_password' => false])->save();
    auth()->logout();
});

function staff(string $role): User
{
    $user = User::create(['name' => "Test {$role}", 'email' => str($role)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
    $user->assignRole($role);

    return $user;
}

test('a rate change records the old and new price', function () {
    $this->actingAs($this->admin);
    $type = RoomType::first();
    $old = (string) $type->getRawOriginal('base_rate');

    $type->update(['base_rate' => 99999]);

    $log = AuditLog::where('action', 'rooms.updated')->latest('id')->first();
    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->admin->id)
        ->and((float) $log->old_values['base_rate'])->toBe((float) $old)
        ->and((float) $log->new_values['base_rate'])->toBe(99999.0)
        ->and($log->subject_label)->toBe($type->name);
});

test('voiding a charge records the reason and flags large amounts', function () {
    $this->actingAs($this->admin);
    $folio = Folio::where('status', 'open')->first();
    $service = app(FolioService::class);

    $small = $service->postCharge($folio, ChargeType::Minibar, 'Water', 1, 500);
    $service->voidCharge($small, 'Posted to the wrong room');
    $big = $service->postCharge($folio, ChargeType::Damage, 'Broken TV', 1, 250000);
    $service->voidCharge($big, 'Guest disputed');

    $logs = AuditLog::where('action', 'billing.charge_voided')->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs[0]->reason)->toBe('Posted to the wrong room')
        ->and($logs[0]->flagged)->toBeFalse()
        ->and($logs[1]->flagged)->toBeTrue()
        ->and($logs[1]->subject_id)->toBe($folio->id);
});

test('refunds and payment voids are recorded', function () {
    $this->actingAs($this->admin);
    $folio = Folio::where('status', 'open')->first();
    $service = app(FolioService::class);

    $paid = $service->receivePayment($folio, PaymentKind::Payment, PaymentMethod::Cash, 20000);
    $service->voidPayment($paid, 'Counted twice');

    expect(AuditLog::where('action', 'billing.payment_voided')->value('reason'))->toBe('Counted twice');
});

test('stock adjustments are recorded with the reason', function () {
    $this->seed([PosDemoSeeder::class, InventoryDemoSeeder::class]);
    $this->actingAs($this->admin);
    $item = StockItem::first();
    $location = StockLocation::first();

    app(StockService::class)->adjust($item, $location->id, StockMovementType::Adjustment, 3, 'Found in the cellar');

    $log = AuditLog::where('action', 'inventory.adjustment')->latest('id')->first();
    expect($log->reason)->toBe('Found in the cellar')
        ->and($log->subject_id)->toBe($item->id);
});

test('role permission changes list what was added and removed', function () {
    $this->actingAs($this->admin);
    $role = Role::findByName('Cashier');
    $keep = $role->permissions->reject(fn ($p) => $p->name === 'Charge to room')->pluck('id')->all();
    $add = Permission::findByName('Give POS discounts')->id;

    $this->put(route('roles.update', $role->id), ['name' => 'Cashier', 'permission' => [...$keep, $add]])->assertRedirect();

    $log = AuditLog::where('action', 'roles.permissions_changed')->latest('id')->first();
    expect($log->new_values['added'])->toBe(['Give POS discounts'])
        ->and($log->old_values['removed'])->toBe(['Charge to room']);
});

test('sign-ins are recorded and repeated failures are flagged, without passwords', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'wrong-' . $i]);
    }
    $this->post('/login', ['email' => $this->admin->email, 'password' => 'ChangeMe@123']);

    $failed = AuditLog::where('action', 'auth.failed')->orderBy('id')->get();
    expect($failed)->toHaveCount(5)
        ->and($failed->first()->flagged)->toBeFalse()
        ->and($failed->last()->flagged)->toBeTrue()
        ->and(AuditLog::where('action', 'auth.login')->where('user_id', $this->admin->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->get()->contains(fn ($l) => str_contains(json_encode($l->toArray()), 'wrong-')))->toBeFalse();
});

test('password changes are recorded as hidden', function () {
    $this->actingAs($this->admin);
    $user = staff('Cashier');
    $user->update(['password' => bcrypt('Another@123')]);

    $log = AuditLog::where('action', 'users.updated')->where('subject_id', $user->id)->latest('id')->first();
    expect($log->description)->toContain('password')
        ->and($log->new_values['password'])->toBe('[hidden]');
});

test('audit entries cannot be changed or deleted', function () {
    $log = AuditLog::first() ?? App\Support\Audit::record('security.test', 'Test entry');

    expect(fn () => $log->update(['description' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});

test('managers can view and export the trail, front desk cannot', function () {
    $this->actingAs($this->admin);
    App\Support\Audit::record('security.test', '=HYPERLINK("http://x")', reason: '+1 test');

    $this->actingAs(staff('General Manager'))->get(route('audit.index'))->assertOk()->assertSee('HYPERLINK');
    $csv = $this->get(route('audit.index', ['export' => 'csv']))->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->toContain("'+1 test");

    $this->actingAs(staff('Front Desk'))->get(route('audit.index'))->assertForbidden();
});
