<?php

use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\CorrectionController;
use App\Http\Controllers\Attendance\KioskController;
use App\Http\Controllers\Attendance\SetupController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\CashShiftController;
use App\Http\Controllers\Billing\FolioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\CountController;
use App\Http\Controllers\Inventory\InventoryReportController;
use App\Http\Controllers\Inventory\InventorySettingsController;
use App\Http\Controllers\Inventory\PurchaseOrderController;
use App\Http\Controllers\Inventory\PurchaseRequestController;
use App\Http\Controllers\Inventory\ReceiptController;
use App\Http\Controllers\Inventory\RecipeController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\SupplierController;
use App\Http\Controllers\Inventory\SupplierInvoiceController;
use App\Http\Controllers\Inventory\TransferController;
use App\Http\Controllers\Operations\HousekeepingController;
use App\Http\Controllers\Operations\MaintenanceController;
use App\Http\Controllers\Security\IncidentController;
use App\Http\Controllers\Security\LostItemController;
use App\Http\Controllers\Security\VisitorController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Pos\PosMenuController;
use App\Http\Controllers\Pos\PosOrderController;
use App\Http\Controllers\Pos\PosTerminalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Reports\AdvancedReportController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reservations\GuestController;
use App\Http\Controllers\Reservations\ReservationController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Settings\BankAccountController;
use App\Http\Controllers\Settings\HotelSettingsController;
use App\Http\Controllers\Rooms\BuildingController;
use App\Http\Controllers\Rooms\RoomController;
use App\Http\Controllers\Rooms\RoomTypeController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'))->name('home');

// Staff accounts are created by an administrator, so public sign-up is off.
Auth::routes(['register' => false, 'verify' => false]);

// Connection check used by every page to notice a dropped network.
Route::get('/ping', fn () => response()->json(['ok' => true, 'time' => now()->toIso8601String()])
    ->header('Cache-Control', 'no-store'))->name('ping');

// CSRF refresh (keeps long-open pages working)
Route::get('/refresh-csrf', function () {
    abort_unless(request()->ajax(), 404);
    Session::regenerateToken();

    return response()->json(['csrf_token' => csrf_token()]);
})->name('refresh.csrf');

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Users
    Route::get('/users/roles', [UserController::class, 'roles'])->name('users.roles');
    Route::get('/user/overview/{id}', [UserController::class, 'show'])->name('users.overview');
    Route::resource('users', UserController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

    // Roles & permissions
    Route::post('roles/bulk-remove-users', [RoleController::class, 'bulkRemoveUsers'])->name('roles.bulkremoveusers');
    Route::get('/roles/{role}/users', [RoleController::class, 'getRoleUsers'])->name('roles.users');
    Route::get('/adduser/{id}', [RoleController::class, 'adduser'])->name('roles.adduser');
    Route::post('/updateuserrole', [RoleController::class, 'updateuserrole'])->name('roles.updateuserrole');
    Route::delete('roles/removeuserrole/{userid}/{roleid}', [RoleController::class, 'removeuserrole'])->name('roles.removeuserrole');
    Route::resource('roles', RoleController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::resource('permissions', PermissionController::class)->only(['index', 'store', 'update', 'destroy']);

    // My account / profile settings
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/settings/{id}', [ProfileController::class, 'show'])->name('settings');
        Route::post('/update-info', [ProfileController::class, 'updateProfile'])->name('update-info');
        Route::post('/update-avatar', [ProfileController::class, 'updateAvatar'])->name('update-avatar');
        Route::post('/update-email', [ProfileController::class, 'updateEmail'])->name('update-email');
        Route::post('/update-password', [ProfileController::class, 'updatePassword'])->name('update-password');
    });

    // Rooms & property
    Route::get('/rooms/bulk/create', [RoomController::class, 'bulkCreate'])->name('rooms.bulk.create');
    Route::post('/rooms/bulk', [RoomController::class, 'bulkStore'])->name('rooms.bulk.store');
    Route::post('/rooms/bulk-status', [RoomController::class, 'bulkStatus'])->name('rooms.bulk-status');
    Route::post('/rooms/{room}/status', [RoomController::class, 'changeStatus'])->name('rooms.status');
    Route::resource('rooms', RoomController::class);

    Route::delete('/room-types/photos/{photo}', [RoomTypeController::class, 'destroyPhoto'])->name('room-types.photos.destroy');
    Route::resource('room-types', RoomTypeController::class)->except(['show'])->parameters(['room-types' => 'roomType']);

    Route::get('/buildings', [BuildingController::class, 'index'])->name('buildings.index');
    Route::post('/buildings', [BuildingController::class, 'store'])->name('buildings.store');
    Route::put('/buildings/{building}', [BuildingController::class, 'update'])->name('buildings.update');
    Route::delete('/buildings/{building}', [BuildingController::class, 'destroy'])->name('buildings.destroy');
    Route::post('/buildings/{building}/floors', [BuildingController::class, 'storeFloor'])->name('floors.store');
    Route::put('/floors/{floor}', [BuildingController::class, 'updateFloor'])->name('floors.update');
    Route::delete('/floors/{floor}', [BuildingController::class, 'destroyFloor'])->name('floors.destroy');

    // Housekeeping (rooms to clean, inspection) and maintenance (work orders)
    Route::prefix('housekeeping')->name('housekeeping.')->controller(HousekeepingController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/tasks', 'store')->name('store');
        Route::post('/stayovers', 'stayovers')->name('stayovers');
        Route::post('/tasks/{task}/assign', 'assign')->name('assign');
        Route::post('/tasks/{task}/start', 'start')->name('start');
        Route::post('/tasks/{task}/finish', 'finish')->name('finish');
        Route::post('/tasks/{task}/inspect', 'inspect')->name('inspect');
        Route::post('/tasks/{task}/cancel', 'cancel')->name('cancel');
    });
    Route::prefix('maintenance')->name('maintenance.')->controller(MaintenanceController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{order}', 'show')->name('show');
        Route::post('/{order}/assign', 'assign')->name('assign');
        Route::post('/{order}/start', 'start')->name('start');
        Route::post('/{order}/hold', 'hold')->name('hold');
        Route::post('/{order}/complete', 'complete')->name('complete');
        Route::post('/{order}/cancel', 'cancel')->name('cancel');
    });

    // Security: visitor register + passes, incidents, lost & found
    Route::prefix('security/visitors')->name('visitors.')->controller(VisitorController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/check', 'check')->name('check');
        Route::get('/{visitor}/pass', 'pass')->name('pass');
        Route::post('/{visitor}/sign-out', 'signOut')->name('sign-out');
        Route::post('/{visitor}/extend', 'extend')->name('extend');
    });
    Route::prefix('security/incidents')->name('incidents.')->controller(IncidentController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/contacts', 'storeContact')->name('contacts.store');
        Route::put('/contacts/{contact}', 'updateContact')->name('contacts.update');
        Route::delete('/contacts/{contact}', 'destroyContact')->name('contacts.destroy');
        Route::get('/{incident}', 'show')->name('show');
        Route::post('/{incident}/updates', 'addUpdate')->name('updates.store');
        Route::post('/{incident}/assign', 'assign')->name('assign');
    });
    Route::prefix('security/lost-found')->name('lost-found.')->controller(LostItemController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::post('/{item}/hand-over', 'handOver')->name('hand-over');
        Route::post('/{item}/dispose', 'dispose')->name('dispose');
    });

    // Reservations & front desk
    Route::prefix('reservations')->name('reservations.')->controller(ReservationController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/create', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/availability', 'availability')->name('availability');
        Route::get('/calendar', 'calendar')->name('calendar');
        Route::get('/scan', 'scan')->name('scan');
        Route::post('/lookup', 'lookup')->middleware('throttle:60,1')->name('lookup');
        Route::get('/{reservation}', 'show')->name('show');
        Route::put('/{reservation}', 'update')->name('update');
        Route::get('/{reservation}/print', 'print')->name('print');
        Route::post('/{reservation}/rooms/{line}/assign', 'assignRoom')->name('assign-room');
        Route::post('/{reservation}/rooms/{line}/unassign', 'unassignRoom')->name('unassign-room');
        Route::post('/{reservation}/confirm', 'confirm')->name('confirm');
        Route::post('/{reservation}/check-in', 'checkIn')->name('check-in');
        Route::post('/{reservation}/check-out', 'checkOut')->name('check-out');
        Route::post('/{reservation}/cancel', 'cancel')->name('cancel');
        Route::post('/{reservation}/no-show', 'noShow')->name('no-show');
        Route::post('/{reservation}/dates', 'changeDates')->name('dates');
        Route::post('/{reservation}/discount', 'discount')->name('discount');
    });
    // Guest QR codes point here (opaque token, no personal data).
    Route::get('/r/{token}', [ReservationController::class, 'qr'])->where('token', '[A-Za-z0-9]{40,64}')->middleware('throttle:60,1')->name('reservations.qr');

    Route::get('/guests/search', [GuestController::class, 'search'])->name('guests.search');
    Route::resource('guests', GuestController::class)->except(['create', 'store']);

    // Guest billing
    Route::controller(FolioController::class)->group(function () {
        Route::get('/folios/{folio}', 'show')->name('folios.show');
        Route::get('/folios/{folio}/print', 'print')->name('folios.print');
        Route::post('/folios/{folio}/charges', 'storeCharge')->name('folios.charges.store');
        Route::post('/folios/{folio}/payments', 'storePayment')->name('folios.payments.store');
        Route::post('/charges/{charge}/void', 'voidCharge')->name('charges.void');
        Route::get('/payments/{payment}/receipt', 'receipt')->name('payments.receipt');
        Route::post('/payments/{payment}/void', 'voidPayment')->name('payments.void');
    });
    Route::get('/billing/collections', [BillingController::class, 'payments'])->name('billing.payments');
    Route::get('/billing/outstanding', [BillingController::class, 'outstanding'])->name('billing.outstanding');
    Route::prefix('cash-shifts')->name('cash-shifts.')->controller(CashShiftController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{shift}', 'show')->name('show');
        Route::post('/{shift}/movements', 'movement')->name('movement');
        Route::post('/{shift}/close', 'close')->name('close');
        Route::post('/{shift}/review', 'review')->name('review');
    });

    // Bar & restaurant POS. The till (/pos) keeps working offline and sends sales to /pos/sync.
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosTerminalController::class, 'terminal'])->name('terminal');
        Route::get('/data', [PosTerminalController::class, 'data'])->name('data');
        Route::get('/ping', [PosTerminalController::class, 'ping'])->name('ping');
        Route::post('/sync', [PosTerminalController::class, 'sync'])->middleware('throttle:120,1')->name('sync');

        Route::get('/orders', [PosOrderController::class, 'index'])->name('orders');
        Route::get('/orders/{order}/receipt', [PosOrderController::class, 'receipt'])->name('orders.receipt');
        Route::post('/orders/{order}/void', [PosOrderController::class, 'void'])->name('orders.void');
        Route::post('/orders/{order}/resolve', [PosOrderController::class, 'resolve'])->name('orders.resolve');

        Route::controller(PosMenuController::class)->group(function () {
            Route::get('/menu', 'index')->name('menu');
            Route::post('/items', 'storeItem')->name('items.store');
            Route::put('/items/{item}', 'updateItem')->name('items.update');
            Route::patch('/items/{item}', 'quickItem')->name('items.quick');
            Route::delete('/items/{item}', 'destroyItem')->name('items.destroy');
            Route::post('/categories', 'storeCategory')->name('categories.store');
            Route::put('/categories/{category}', 'updateCategory')->name('categories.update');
            Route::delete('/categories/{category}', 'destroyCategory')->name('categories.destroy');
            Route::post('/outlets', 'storeOutlet')->name('outlets.store');
            Route::put('/outlets/{outlet}', 'updateOutlet')->name('outlets.update');
            Route::delete('/outlets/{outlet}', 'destroyOutlet')->name('outlets.destroy');
        });
    });

    // Inventory & stock
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [StockController::class, 'index'])->name('index');
        Route::post('/items', [StockController::class, 'store'])->name('items.store');
        Route::get('/items/{item}', [StockController::class, 'show'])->name('items.show');
        Route::put('/items/{item}', [StockController::class, 'update'])->name('items.update');
        Route::post('/items/{item}/adjust', [StockController::class, 'adjust'])->name('items.adjust');

        Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
        Route::get('/receipts/create', [ReceiptController::class, 'create'])->name('receipts.create');
        Route::post('/receipts', [ReceiptController::class, 'store'])->name('receipts.store');
        Route::get('/receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');

        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('po.index');
        Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('po.create');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('po.store');
        Route::post('/purchase-orders/reorder', [PurchaseOrderController::class, 'reorder'])->name('po.reorder');
        Route::get('/purchase-orders/{po}', [PurchaseOrderController::class, 'show'])->name('po.show');
        Route::get('/purchase-orders/{po}/edit', [PurchaseOrderController::class, 'edit'])->name('po.edit');
        Route::put('/purchase-orders/{po}', [PurchaseOrderController::class, 'update'])->name('po.update');
        Route::post('/purchase-orders/{po}/order', [PurchaseOrderController::class, 'order'])->name('po.order');
        Route::post('/purchase-orders/{po}/cancel', [PurchaseOrderController::class, 'cancel'])->name('po.cancel');
        Route::post('/purchase-orders/{po}/submit', [PurchaseOrderController::class, 'submit'])->name('po.submit');
        Route::post('/purchase-orders/{po}/approve', [PurchaseOrderController::class, 'approve'])->name('po.approve');
        Route::post('/purchase-orders/{po}/reject', [PurchaseOrderController::class, 'reject'])->name('po.reject');

        Route::get('/requests', [PurchaseRequestController::class, 'index'])->name('requests.index');
        Route::get('/requests/create', [PurchaseRequestController::class, 'create'])->name('requests.create');
        Route::post('/requests', [PurchaseRequestController::class, 'store'])->name('requests.store');
        Route::get('/requests/{pr}', [PurchaseRequestController::class, 'show'])->name('requests.show');
        Route::post('/requests/{pr}/decide', [PurchaseRequestController::class, 'decide'])->name('requests.decide');
        Route::post('/requests/{pr}/cancel', [PurchaseRequestController::class, 'cancel'])->name('requests.cancel');
        Route::post('/requests/{pr}/order', [PurchaseRequestController::class, 'order'])->name('requests.order');

        Route::get('/invoices', [SupplierInvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/create', [SupplierInvoiceController::class, 'create'])->name('invoices.create');
        Route::post('/invoices', [SupplierInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('/invoices/{invoice}', [SupplierInvoiceController::class, 'show'])->name('invoices.show');
        Route::post('/invoices/{invoice}/pay', [SupplierInvoiceController::class, 'pay'])->name('invoices.pay');
        Route::post('/invoices/{invoice}/void', [SupplierInvoiceController::class, 'void'])->name('invoices.void');

        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/create', [TransferController::class, 'create'])->name('transfers.create');
        Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::get('/transfers/{transfer}', [TransferController::class, 'show'])->name('transfers.show');

        Route::get('/counts', [CountController::class, 'index'])->name('counts.index');
        Route::post('/counts', [CountController::class, 'store'])->name('counts.store');
        Route::get('/counts/{count}', [CountController::class, 'show'])->name('counts.show');
        Route::post('/counts/{count}/save', [CountController::class, 'save'])->name('counts.save');
        Route::post('/counts/{count}/post', [CountController::class, 'post'])->name('counts.post');
        Route::post('/counts/{count}/cancel', [CountController::class, 'cancel'])->name('counts.cancel');

        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

        Route::get('/recipes', [RecipeController::class, 'index'])->name('recipes.index');
        Route::put('/recipes/{item}', [RecipeController::class, 'update'])->name('recipes.update');

        Route::get('/settings', [InventorySettingsController::class, 'index'])->name('settings');
        Route::post('/locations', [InventorySettingsController::class, 'storeLocation'])->name('locations.store');
        Route::put('/locations/{location}', [InventorySettingsController::class, 'updateLocation'])->name('locations.update');
        Route::post('/categories', [InventorySettingsController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [InventorySettingsController::class, 'updateCategory'])->name('categories.update');
        Route::post('/outlet-stores', [InventorySettingsController::class, 'outlets'])->name('outlets.save');

        Route::get('/reports', [InventoryReportController::class, 'index'])->name('reports');
    });

    // Staff attendance. The kiosk (/attendance/kiosk) keeps working offline and sends scans to /attendance/kiosk/sync.
    Route::get('/my-attendance', [AttendanceController::class, 'my'])->name('attendance.my');
    Route::prefix('attendance')->name('attendance.')->group(function () {
        Route::get('/', [AttendanceController::class, 'board'])->name('board');
        Route::get('/timesheet', [AttendanceController::class, 'timesheet'])->name('timesheet');
        Route::get('/leave', [AttendanceController::class, 'leave'])->name('leave');
        Route::get('/staff/{user}', [AttendanceController::class, 'staff'])->name('staff');
        Route::get('/photos/{punch}', [AttendanceController::class, 'photo'])->name('photo');

        Route::post('/staff/{user}/punches', [CorrectionController::class, 'addPunch'])->name('punches.store');
        Route::post('/punches/{punch}/void', [CorrectionController::class, 'voidPunch'])->name('punches.void');
        Route::post('/staff/{user}/rota', [CorrectionController::class, 'setRota'])->name('rota');
        Route::post('/leave', [CorrectionController::class, 'storeLeave'])->name('leave.store');
        Route::post('/leave/{leave}/cancel', [CorrectionController::class, 'cancelLeave'])->name('leave.cancel');

        Route::controller(SetupController::class)->group(function () {
            Route::get('/setup', 'index')->name('setup');
            Route::post('/setup/staff', 'addStaff')->name('staff.add');
            Route::put('/setup/staff/{profile}', 'updateStaff')->name('staff.update');
            Route::post('/setup/staff/{profile}/badge', 'reissueBadge')->name('staff.badge');
            Route::get('/badges', 'badges')->name('badges');
            Route::post('/shifts', 'storeShift')->name('shifts.store');
            Route::put('/shifts/{shift}', 'updateShift')->name('shifts.update');
            Route::delete('/shifts/{shift}', 'destroyShift')->name('shifts.destroy');
            Route::post('/departments', 'storeDepartment')->name('departments.store');
            Route::put('/departments/{department}', 'updateDepartment')->name('departments.update');
            Route::delete('/departments/{department}', 'destroyDepartment')->name('departments.destroy');
            Route::post('/points', 'storePoint')->name('points.store');
            Route::put('/points/{point}', 'updatePoint')->name('points.update');
            Route::delete('/points/{point}', 'destroyPoint')->name('points.destroy');
        });

        Route::get('/kiosk', [KioskController::class, 'kiosk'])->name('kiosk');
        Route::get('/kiosk/data', [KioskController::class, 'data'])->name('kiosk.data');
        Route::get('/kiosk/ping', [KioskController::class, 'ping'])->name('kiosk.ping');
        Route::post('/kiosk/sync', [KioskController::class, 'sync'])->middleware('throttle:120,1')->name('kiosk.sync');
    });

    // Management reports
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/advanced', [AdvancedReportController::class, 'index'])->name('reports.advanced');

    // Audit trail
    Route::get('/audit-trail', [AuditLogController::class, 'index'])->name('audit.index');

    // Hotel settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/hotel', [HotelSettingsController::class, 'index'])->name('hotel.index');
        Route::put('/hotel/{group}', [HotelSettingsController::class, 'update'])->where('group', '[a-z_]+')->name('hotel.update');
        Route::post('/hotel/reset', [HotelSettingsController::class, 'reset'])->name('hotel.reset');
        Route::get('/bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts.index');
        Route::post('/bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
        Route::put('/bank-accounts/{account}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
        Route::delete('/bank-accounts/{account}', [BankAccountController::class, 'destroy'])->name('bank-accounts.destroy');
    });

    /*
    | Hotel modules (rooms, reservations, front desk, POS, inventory, HR,
    | security, IT control room…) go below, each in its own route group or
    | its own routes/<module>.php file required from here.
    */
});
