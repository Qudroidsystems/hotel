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
use App\Http\Controllers\Staff\StaffAppController;
use App\Http\Controllers\Booking\BookingController;
use App\Http\Controllers\Booking\GuestPortalController;
use App\Http\Controllers\Booking\GuestRequestController;
use App\Http\Controllers\Finance\ExpenseCategoryController;
use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\It\AlertController as ItAlertController;
use App\Http\Controllers\It\AssetController as ItAssetController;
use App\Http\Controllers\It\ControlRoomController;
use App\Http\Controllers\It\MaintenanceController as ItMaintenanceController;
use App\Http\Controllers\It\MonitorController as ItMonitorController;
use App\Http\Controllers\It\TicketController as ItTicketController;
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

// The hotel's public website (Website in the sidebar). Switched off, the main address opens staff sign-in as before.
Route::get('/', [\App\Http\Controllers\Website\SiteController::class, 'home'])->middleware('throttle:booking-pages')->name('home');
Route::middleware(['throttle:booking-pages', \App\Http\Middleware\WebsiteOn::class])->name('site.')->controller(\App\Http\Controllers\Website\SiteController::class)->group(function () {
    Route::get('/accommodation', 'rooms')->name('rooms');
    Route::get('/accommodation/{type}', 'room')->whereNumber('type')->name('rooms.show');
    Route::get('/dining', 'dining')->name('dining');
    Route::get('/dining/{slug}', 'item')->defaults('kind', 'dining')->name('dining.show');
    Route::get('/meetings-events', 'events')->name('events');
    Route::get('/experiences', 'experiences')->name('experiences');
    Route::get('/experiences/{slug}', 'item')->defaults('kind', 'experience')->name('experiences.show');
    Route::get('/offers', 'offers')->name('offers');
    Route::get('/offers/{slug}', 'item')->defaults('kind', 'offer')->name('offers.show');
    Route::get('/gallery', 'gallery')->name('gallery');
    Route::get('/journal', 'journal')->name('journal');
    Route::get('/journal/{slug}', 'item')->defaults('kind', 'post')->name('journal.show');
    Route::get('/about', 'about')->name('about');
    Route::get('/contact', 'contact')->name('contact');
    Route::get('/page/{slug}', 'page')->name('page');
    Route::post('/contact', 'send')->withoutMiddleware('throttle:booking-pages')->middleware('throttle:booking-actions')->name('send');
    Route::post('/newsletter', 'subscribe')->withoutMiddleware('throttle:booking-pages')->middleware('throttle:booking-actions')->name('subscribe');
});

// Staff accounts are created by an administrator, so public sign-up is off.
Auth::routes(['register' => false, 'verify' => false]);
// Second step of signing in for accounts with two-step sign-in
Route::get('/two-factor/challenge', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
Route::post('/two-factor/challenge', [\App\Http\Controllers\Auth\TwoFactorChallengeController::class, 'store'])->middleware('throttle:20,1')->name('two-factor.verify');

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

// ── Public: online booking and the guest's own booking page (spec §4, §5) ──
Route::middleware('throttle:booking-pages')->group(function () {
    // Privacy policy and booking terms (linked from the booking pages and the app store listings)
    Route::view('/privacy', 'legal.privacy', ['title' => 'Privacy policy'])->name('legal.privacy');
    Route::view('/terms', 'legal.terms', ['title' => 'Booking terms'])->name('legal.terms');

    // Group booking site (multi-hotel): every hotel, searched together
    Route::get('/hotels', [\App\Http\Controllers\Booking\MarketplaceController::class, 'index'])->name('hotels.public');
    Route::get('/hotels/{hotelSlug}', [\App\Http\Controllers\Booking\MarketplaceController::class, 'show'])->where('hotelSlug', '[a-z0-9-]+')->name('hotels.public.show');
    Route::get('/book', [BookingController::class, 'index'])->name('book.index');
    Route::get('/book/search', [BookingController::class, 'search'])->name('book.search');
    Route::get('/book/details', [BookingController::class, 'details'])->name('book.details');
    Route::post('/book', [BookingController::class, 'store'])->withoutMiddleware('throttle:booking-pages')->middleware('throttle:booking-actions')->name('book.store');
    Route::get('/book/pay/{token}', [BookingController::class, 'pay'])->withoutMiddleware('throttle:booking-pages')->middleware('throttle:stay-actions')->name('book.pay');
    Route::get('/book/paystack/callback', [BookingController::class, 'callback'])->name('book.paystack.callback');

    Route::get('/stay', [GuestPortalController::class, 'find'])->name('stay.find');
    Route::post('/stay', [GuestPortalController::class, 'lookup'])->withoutMiddleware('throttle:booking-pages')->middleware('throttle:booking-lookup')->name('stay.lookup');
    Route::get('/stay/{token}', [GuestPortalController::class, 'show'])->name('stay.show');
    Route::get('/stay/{token}/bill', [GuestPortalController::class, 'receipt'])->name('stay.receipt');
    Route::get('/tickets/{code}', [\App\Http\Controllers\Events\TicketController::class, 'public'])->name('tickets.public');
    Route::get('/stay/{token}/weather', [GuestPortalController::class, 'weather'])->name('stay.weather');
    Route::post('/stay/{token}/arrival', [GuestPortalController::class, 'arrival'])->withoutMiddleware('throttle:booking-pages')->middleware('throttle:arrival')->name('stay.arrival');
    Route::post('/stay/{token}/rides', [GuestPortalController::class, 'bookRide'])->withoutMiddleware('throttle:booking-pages')->middleware('throttle:stay-actions')->name('stay.rides.book');
    Route::post('/stay/{token}/rides/{ride}/cancel', [GuestPortalController::class, 'cancelRide'])->whereNumber('ride')->withoutMiddleware('throttle:booking-pages')->middleware('throttle:stay-actions')->name('stay.rides.cancel');
    Route::get('/stay/{token}/order', [GuestPortalController::class, 'order'])->name('stay.order');
    Route::post('/stay/{token}/order', [GuestPortalController::class, 'placeOrder'])->withoutMiddleware('throttle:booking-pages')->middleware('throttle:stay-actions')->name('stay.order.place');
    Route::post('/stay/{token}/orders/{order}/cancel', [GuestPortalController::class, 'cancelOrder'])->whereNumber('order')->withoutMiddleware('throttle:booking-pages')->middleware('throttle:stay-actions')->name('stay.order.cancel');
    // The QR card in each room: the guest confirms their surname once, then orders / opens their booking.
    Route::get('/room/{code}', [\App\Http\Controllers\Booking\RoomCardController::class, 'show'])->name('room.card');
    Route::post('/room/{code}', [\App\Http\Controllers\Booking\RoomCardController::class, 'verify'])->withoutMiddleware('throttle:booking-pages')->middleware('throttle:booking-lookup')->name('room.card.verify');
    // Chat and calls from the booking page (same JSON as the guest app)
    Route::get('/stay/{token}/chat', [GuestPortalController::class, 'chat'])->name('stay.chat');
    // A voice message in guest chat, played through a signed link that lasts a few hours (guest, staff, both apps)
    Route::get('/chat/audio/{message}', \App\Http\Controllers\Chat\ChatAudioController::class)->whereNumber('message')->middleware('signed')->name('chat.audio');
    Route::prefix('stay/{token}/live')->name('stay.live.')->controller(\App\Http\Controllers\Chat\GuestChatController::class)
        ->withoutMiddleware('throttle:booking-pages')->middleware('throttle:guest-chat')->group(function () {
            Route::get('/chat', 'index')->name('index');
            Route::get('/chat/{section}', 'thread')->whereNumber('section')->name('thread');
            Route::post('/chat/{section}', 'send')->whereNumber('section')->name('send');
            Route::post('/chat/{section}/typing', 'typing')->whereNumber('section')->name('typing');
            Route::post('/calls', 'call')->name('call');
            Route::get('/calls/ringing', 'ringing')->name('ringing');
            Route::get('/calls/{uuid}', 'show')->whereUuid('uuid')->name('call.show');
            Route::post('/calls/{uuid}/signal', 'signal')->whereUuid('uuid')->name('call.signal');
            Route::post('/calls/{uuid}/answer', 'answer')->whereUuid('uuid')->name('call.answer');
            Route::post('/calls/{uuid}/end', 'end')->whereUuid('uuid')->name('call.end');
        });
    Route::post('/stay/{token}/realtime/auth', [\App\Http\Controllers\Chat\RealtimeController::class, 'auth'])->withoutMiddleware('throttle:booking-pages')->middleware('throttle:guest-chat')->name('stay.realtime.auth');
    Route::post('/stay/{token}/cancel', [GuestPortalController::class, 'cancel'])->withoutMiddleware('throttle:booking-pages')->middleware('throttle:stay-actions')->name('stay.cancel');
    // The guest's luggage list (optional), ticked off when packing to leave
    Route::prefix('stay/{token}/luggage')->name('stay.luggage.')->controller(\App\Http\Controllers\Booking\GuestLuggageController::class)
        ->withoutMiddleware('throttle:booking-pages')->middleware('throttle:luggage')->group(function () {
            Route::post('/', 'store')->name('store');
            Route::post('/unpack', 'unpack')->name('unpack');
            Route::post('/{item}', 'update')->whereNumber('item')->name('update');
            Route::post('/{item}/remove', 'destroy')->whereNumber('item')->name('remove');
        });
    Route::post('/stay/{token}/requests', [GuestPortalController::class, 'request'])->withoutMiddleware('throttle:booking-pages')->middleware('throttle:stay-actions')->name('stay.request');
});
Route::post('/paystack/webhook', [BookingController::class, 'webhook'])->middleware('throttle:paystack-webhook')->name('book.paystack.webhook');
// Twilio: delivery reports and guests' replies to texts (checked by signature).
Route::post('/twilio/status', [\App\Http\Controllers\Messaging\TwilioWebhookController::class, 'status'])->middleware('throttle:twilio-webhook')->name('twilio.status');
Route::post('/twilio/inbound', [\App\Http\Controllers\Messaging\TwilioWebhookController::class, 'inbound'])->middleware('throttle:twilio-webhook')->name('twilio.inbound');
// Room calendars, public: each room's calendar for Airbnb, Vrbo ...
Route::get('/ical/{token}.ics', [\App\Http\Controllers\Channels\ChannelController::class, 'export'])->middleware('throttle:60,1')->name('channels.ical.export');

// Termii: delivery reports and replies (Termii dashboard → Webhook URL: https://<your site>/termii/events).
Route::post('/termii/events', [\App\Http\Controllers\Messaging\TwilioWebhookController::class, 'termii'])->middleware('throttle:twilio-webhook')->name('termii.events');

// Staff App manifest: fetched by the browser without cookies, so it must be public.
Route::get('/staff/manifest.webmanifest', [StaffAppController::class, 'manifest'])->name('staff.manifest');

Route::middleware('auth')->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Website: the public site's content, settings, enquiries and newsletter list
    Route::prefix('website')->name('website.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Website\WebsiteAdminController::class, 'index'])->name('index');
        Route::get('/settings/{group?}', [\App\Http\Controllers\Website\WebsiteAdminController::class, 'settings'])->name('settings');
        Route::post('/settings/{group}', [\App\Http\Controllers\Website\WebsiteAdminController::class, 'saveSettings'])->name('settings.save');
        Route::controller(\App\Http\Controllers\Website\WebsiteContentController::class)->prefix('content/{kind}')->name('content.')->where(['kind' => '[a-z]+'])->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/new', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{item}/edit', 'edit')->whereNumber('item')->name('edit');
            Route::put('/{item}', 'update')->whereNumber('item')->name('update');
            Route::delete('/{item}', 'destroy')->whereNumber('item')->name('destroy');
            Route::post('/{item}/toggle', 'toggle')->whereNumber('item')->name('toggle');
            Route::post('/{item}/move', 'move')->whereNumber('item')->name('move');
        });
        Route::controller(\App\Http\Controllers\Website\WebsiteEnquiryController::class)->group(function () {
            Route::get('/enquiries', 'index')->name('enquiries.index');
            Route::get('/enquiries/{message}', 'show')->whereNumber('message')->name('enquiries.show');
            Route::put('/enquiries/{message}', 'update')->whereNumber('message')->name('enquiries.update');
            Route::get('/newsletter', 'subscribers')->name('subscribers');
            Route::get('/newsletter/export', 'export')->name('subscribers.export');
            Route::post('/newsletter/{subscriber}/remove', 'removeSubscriber')->whereNumber('subscriber')->name('subscribers.remove');
        });
    });

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

    // Two-step sign-in: your own set-up, and the administrator's list and reset
    Route::get('/two-factor', [\App\Http\Controllers\Auth\TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('/two-factor/confirm', [\App\Http\Controllers\Auth\TwoFactorController::class, 'confirm'])->middleware('throttle:10,1')->name('two-factor.confirm');
    Route::post('/two-factor/recovery-codes', [\App\Http\Controllers\Auth\TwoFactorController::class, 'recoveryCodes'])->middleware('throttle:10,1')->name('two-factor.recovery');
    Route::post('/two-factor/restart', [\App\Http\Controllers\Auth\TwoFactorController::class, 'restart'])->middleware('throttle:10,1')->name('two-factor.restart');
    Route::post('/two-factor/disable', [\App\Http\Controllers\Auth\TwoFactorController::class, 'disable'])->middleware('throttle:10,1')->name('two-factor.disable');
    Route::get('/security/two-step', [\App\Http\Controllers\Security\TwoFactorAdminController::class, 'index'])->name('security.two-factor');
    Route::post('/security/two-step/{user}/reset', [\App\Http\Controllers\Security\TwoFactorAdminController::class, 'reset'])->name('security.two-factor.reset');

    // My account / profile settings
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/settings/{id}', [ProfileController::class, 'show'])->name('settings');
        Route::post('/update-info', [ProfileController::class, 'updateProfile'])->name('update-info');
        Route::post('/update-avatar', [ProfileController::class, 'updateAvatar'])->name('update-avatar');
        Route::post('/update-email', [ProfileController::class, 'updateEmail'])->name('update-email');
        Route::post('/update-password', [ProfileController::class, 'updatePassword'])->name('update-password');
    });

    // Rooms & property
    Route::get('/rooms/qr-cards', [\App\Http\Controllers\Booking\RoomCardController::class, 'cards'])->name('rooms.qr-cards');
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
        Route::get('/board', 'board')->name('board');
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

    // Department tasks (any member of staff sees their own and their department's)
    Route::prefix('tasks')->name('tasks.')->controller(\App\Http\Controllers\Operations\TaskController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{task}', 'show')->name('show');
        Route::post('/{task}/take', 'take')->name('take');
        Route::post('/{task}/start', 'start')->name('start');
        Route::post('/{task}/finish', 'finish')->name('finish');
        Route::post('/{task}/check', 'check')->name('check');
        Route::post('/{task}/change', 'change')->name('change');
        Route::post('/{task}/cancel', 'cancel')->name('cancel');
        Route::post('/{task}/comment', 'comment')->name('comment');
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
        Route::post('/{reservation}/guest-link', 'guestLink')->name('guest-link');
    });
    // Guest QR codes point here (opaque token, no personal data).
    Route::get('/r/{token}', [ReservationController::class, 'qr'])->where('token', '[A-Za-z0-9]{40,64}')->middleware('throttle:60,1')->name('reservations.qr');

    Route::get('/guests/search', [GuestController::class, 'search'])->name('guests.search');
    Route::resource('guests', GuestController::class)->except(['create', 'store']);
    Route::post('/guests/{guest}/points', [GuestController::class, 'adjustPoints'])->name('guests.points');
    Route::post('/folios/{folio}/loyalty', [GuestController::class, 'redeemPoints'])->name('folios.loyalty');

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

        Route::get('/room-orders', [\App\Http\Controllers\Pos\RoomOrderController::class, 'index'])->name('room-orders');
        Route::post('/room-orders/{order}/{action}', [\App\Http\Controllers\Pos\RoomOrderController::class, 'act'])->whereIn('action', ['accept', 'send', 'deliver', 'decline'])->name('room-orders.act');
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

    // Guest requests sent from the guest portal (spec §12.2)
    // Texts to and from guests (SMS / WhatsApp)
    Route::get('/messages', [\App\Http\Controllers\Messaging\MessageController::class, 'index'])->name('messages.index');
    Route::post('/messages/{message}/read', [\App\Http\Controllers\Messaging\MessageController::class, 'markRead'])->name('messages.read');
    Route::post('/reservations/{reservation}/messages', [\App\Http\Controllers\Messaging\MessageController::class, 'sendForReservation'])->middleware('throttle:30,1')->name('reservations.messages.store');
    Route::post('/guests/{guest}/messages', [\App\Http\Controllers\Messaging\MessageController::class, 'sendForGuest'])->middleware('throttle:30,1')->name('guests.messages.store');

    Route::get('/guest-requests', [GuestRequestController::class, 'index'])->name('guest-requests.index');
    Route::post('/guest-requests/{guestRequest}', [GuestRequestController::class, 'update'])->name('guest-requests.update');
    Route::post('/guest-requests/{guestRequest}/maintenance', [GuestRequestController::class, 'toMaintenance'])->name('guest-requests.maintenance');

    // Staff App: phone-first, installable (spec §16)
    Route::prefix('staff')->name('staff.')->controller(StaffAppController::class)->group(function () {
        Route::get('/', 'home')->name('home');
        Route::get('/work', 'work')->name('work');
        Route::get('/approvals', 'approvals')->name('approvals');
        Route::get('/report', 'report')->name('report');
        Route::get('/front-desk', 'frontDesk')->name('front-desk');
        Route::get('/stock', 'stock')->name('stock');
        Route::get('/more', 'more')->name('more');
    });

    // Rates & seasons: rate plans, seasons and the rate calendar
    Route::prefix('rates')->name('rates.')->controller(\App\Http\Controllers\Rates\RateController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/check', 'check')->name('check');
        Route::get('/plans/new', 'createPlan')->name('plans.create');
        Route::post('/plans', 'storePlan')->name('plans.store');
        Route::get('/plans/{plan}/edit', 'editPlan')->name('plans.edit');
        Route::put('/plans/{plan}', 'updatePlan')->name('plans.update');
        Route::delete('/plans/{plan}', 'destroyPlan')->name('plans.destroy');
        Route::post('/seasons', 'storeSeason')->name('seasons.store');
        Route::put('/seasons/{season}', 'updateSeason')->name('seasons.update');
        Route::delete('/seasons/{season}', 'destroySeason')->name('seasons.destroy');
        Route::post('/calendar', 'updateCalendar')->name('calendar.update');
    });
    Route::get('/rates/revenue', [\App\Http\Controllers\Rates\RevenueController::class, 'index'])->name('rates.revenue');
    Route::post('/rates/revenue/apply', [\App\Http\Controllers\Rates\RevenueController::class, 'apply'])->name('rates.revenue.apply');

    // Guest chat & calls: the inbox (and the browser ringer), the JSON it uses, and setting up sections
    Route::get('/chat', [\App\Http\Controllers\Chat\ChatPageController::class, 'index'])->name('chat.index');
    Route::get('/chat/sections', [\App\Http\Controllers\Chat\ChatPageController::class, 'sections'])->name('chat.sections');
    Route::post('/chat/sections', [\App\Http\Controllers\Chat\ChatPageController::class, 'saveSection'])->name('chat.sections.store');
    Route::put('/chat/sections/{section}', [\App\Http\Controllers\Chat\ChatPageController::class, 'saveSection'])->name('chat.sections.update');
    Route::post('/realtime/auth', [\App\Http\Controllers\Chat\RealtimeController::class, 'auth'])->name('realtime.auth');
    Route::prefix('chat/api')->name('chat.api.')->controller(\App\Http\Controllers\Chat\StaffChatController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/{conversation}', 'show')->whereNumber('conversation')->name('show');
        Route::post('/{conversation}', 'send')->whereNumber('conversation')->name('send');
        Route::post('/{conversation}/typing', 'typing')->whereNumber('conversation')->name('typing');
        Route::post('/{conversation}/close', 'close')->whereNumber('conversation')->name('close');
        Route::post('/{conversation}/assign', 'assign')->whereNumber('conversation')->name('assign');
        Route::post('/{conversation}/call', 'callBack')->whereNumber('conversation')->name('call');
        Route::get('/calls/ringing', 'ringing')->name('ringing');
        Route::get('/calls/{uuid}', 'call')->whereUuid('uuid')->name('call.show');
        Route::post('/calls/{uuid}/answer', 'answer')->whereUuid('uuid')->name('call.answer');
        Route::post('/calls/{uuid}/signal', 'signal')->whereUuid('uuid')->name('call.signal');
        Route::post('/calls/{uuid}/end', 'end')->whereUuid('uuid')->name('call.end');
        Route::post('/calls/{uuid}/transfer', 'transfer')->whereUuid('uuid')->name('call.transfer');
        Route::get('/calls/{uuid}/targets', 'targets')->whereUuid('uuid')->name('call.targets');
        Route::post('/calls/{uuid}/tap/{listen}', 'tap')->whereUuid('uuid')->whereNumber('listen')->name('call.tap');
    });

    // Car service: dispatch board, cars, drivers, set-fare places
    Route::prefix('transport')->name('transport.')->controller(\App\Http\Controllers\Transport\TransportController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/rides', 'storeRide')->name('rides.store');
        Route::post('/rides/{ride}/assign', 'assign')->name('rides.assign');
        Route::post('/rides/{ride}/auto', 'auto')->name('rides.auto');
        Route::post('/rides/{ride}/cancel', 'cancel')->name('rides.cancel');
        Route::post('/rides/{ride}/status/{action}', 'status')->name('rides.status');
        Route::post('/cars', 'saveVehicle')->name('cars.store');
        Route::put('/cars/{vehicle}', 'saveVehicle')->name('cars.update');
        Route::post('/drivers', 'saveDriver')->name('drivers.store');
        Route::put('/drivers/{driver}', 'saveDriver')->name('drivers.update');
        Route::post('/places', 'savePlace')->name('places.store');
        Route::put('/places/{place}', 'savePlace')->name('places.update');
    });

    // Company accounts (city ledger)
    Route::prefix('companies')->name('companies.')->controller(\App\Http\Controllers\Companies\CompanyController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::post('/', 'store')->name('store');
        Route::get('/{company}', 'show')->name('show');
        Route::get('/{company}/edit', 'edit')->name('edit');
        Route::put('/{company}', 'update')->name('update');
        Route::get('/{company}/statement', 'statement')->name('statement');
        Route::get('/{company}/invoices/{entry}', 'invoice')->name('invoice');
        Route::post('/{company}/payments', 'payment')->middleware('throttle:30,1')->name('payment');
        Route::post('/{company}/entries', 'entry')->name('entry');
        Route::post('/{company}/entries/{entry}/void', 'void')->name('void');
    });
    Route::post('/folios/{folio}/to-company', [\App\Http\Controllers\Companies\CompanyController::class, 'transfer'])->middleware('throttle:30,1')->name('folios.to-company');

    // Night audit: close the business day
    Route::prefix('night-audit')->name('night-audit.')->controller(\App\Http\Controllers\NightAudit\NightAuditController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/run', 'run')->middleware('throttle:6,1')->name('run');
        Route::get('/{audit}', 'show')->name('show');
    });

    // Room calendars (iCal) for Airbnb, Vrbo and similar sites
    Route::prefix('channels')->name('channels.')->controller(\App\Http\Controllers\Channels\ChannelController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/pull', 'pull')->middleware('throttle:10,1')->name('pull');
        Route::post('/calendars', 'storeFeed')->name('ical.store');
        Route::put('/calendars/{feed}', 'updateFeed')->name('ical.update');
        Route::delete('/calendars/{feed}', 'destroyFeed')->name('ical.destroy');
        Route::post('/calendars/{feed}/sync', 'syncFeed')->middleware('throttle:10,1')->name('ical.sync');
    });

    // Events & halls
    Route::prefix('events')->name('events.')->group(function () {
        Route::controller(\App\Http\Controllers\Events\HallController::class)->group(function () {
            Route::get('/halls', 'index')->name('halls.index');
            Route::post('/halls', 'store')->name('halls.store');
            Route::put('/halls/{hall}', 'update')->name('halls.update');
            Route::post('/packages', 'storePackage')->name('packages.store');
            Route::put('/packages/{package}', 'updatePackage')->name('packages.update');
        });
        Route::get('/usage', [\App\Http\Controllers\Events\HallUsageController::class, 'report'])->name('usage');
        Route::post('/{event}/check-in', [\App\Http\Controllers\Events\HallUsageController::class, 'checkIn'])->name('check-in');
        Route::post('/{event}/check-out', [\App\Http\Controllers\Events\HallUsageController::class, 'checkOut'])->name('check-out');
        // Tickets
        Route::controller(\App\Http\Controllers\Events\TicketController::class)->name('tickets.')->group(function () {
            Route::get('/tickets', 'index')->name('index');
            Route::get('/tickets/door', 'door')->name('door');
            Route::post('/tickets/door', 'admit')->middleware('throttle:120,1')->name('admit');
            Route::get('/tickets/orders/{order}', 'order')->name('order');
            Route::post('/tickets/orders/{order}/cancel', 'cancel')->name('cancel');
            Route::post('/{event}/tickets/setup', 'configure')->name('configure');
            Route::post('/{event}/tickets/sales', 'sales')->name('sales');
            Route::post('/{event}/tickets/types', 'storeType')->name('types.store');
            Route::put('/{event}/tickets/types/{type}', 'updateType')->name('types.update');
            Route::post('/{event}/tickets/sell', 'sell')->middleware('throttle:60,1')->name('sell');
        });
        Route::controller(\App\Http\Controllers\Events\EventController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/new', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{event}', 'show')->name('show');
            Route::get('/{event}/edit', 'edit')->name('edit');
            Route::put('/{event}', 'update')->name('update');
            Route::post('/{event}/status', 'status')->name('status');
            Route::post('/{event}/items', 'addItem')->name('items.store');
            Route::delete('/{event}/items/{item}', 'removeItem')->name('items.destroy');
            Route::post('/{event}/payments', 'payment')->middleware('throttle:30,1')->name('payments.store');
            Route::post('/{event}/payments/{payment}/void', 'voidPayment')->name('payments.void');
            Route::post('/{event}/to-company', 'toCompany')->name('to-company');
            Route::get('/{event}/sheet', 'sheet')->name('sheet');
        });
    });

    // Operating expenses (spec §9, §14)
    Route::prefix('expenses')->name('expenses.')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('index');
        Route::post('/', [ExpenseController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::get('/categories', [ExpenseCategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [ExpenseCategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [ExpenseCategoryController::class, 'update'])->name('categories.update');
        Route::get('/{expense}', [ExpenseController::class, 'show'])->name('show');
        Route::put('/{expense}', [ExpenseController::class, 'update'])->name('update');
        Route::post('/{expense}/approve', [ExpenseController::class, 'approve'])->name('approve');
        Route::post('/{expense}/reject', [ExpenseController::class, 'reject'])->name('reject');
        Route::post('/{expense}/pay', [ExpenseController::class, 'pay'])->name('pay');
        Route::post('/{expense}/void', [ExpenseController::class, 'void'])->name('void');
        Route::post('/{expense}/attachments', [ExpenseController::class, 'attach'])->name('attach');
        Route::get('/{expense}/files/{index}', [ExpenseController::class, 'file'])->whereNumber('index')->name('file');
    });

    // IT control room & service desk (spec §11)
    Route::prefix('it')->name('it.')->group(function () {
        Route::get('/', [ControlRoomController::class, 'index'])->name('index');
        Route::post('/check-now', [ControlRoomController::class, 'runChecks'])->middleware('throttle:6,1')->name('check-now');
        Route::get('/report', [ControlRoomController::class, 'report'])->name('report');

        // The call console: calls, listening in, voice messages, network, weather, the equipment board
        Route::prefix('console')->name('console.')->controller(\App\Http\Controllers\It\CallConsoleController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/feed', 'feed')->name('feed');
            Route::get('/board', 'board')->name('board');
            Route::get('/weather', 'weather')->name('weather');
            Route::get('/calls/{uuid}', 'show')->whereUuid('uuid')->name('call');
            Route::post('/calls/{uuid}/answer', 'answer')->whereUuid('uuid')->name('answer');
            Route::post('/calls/{uuid}/signal', 'signal')->whereUuid('uuid')->name('signal');
            Route::post('/calls/{uuid}/end', 'end')->whereUuid('uuid')->name('end');
            Route::get('/calls/{uuid}/targets', 'targets')->whereUuid('uuid')->name('targets');
            Route::post('/calls/{uuid}/transfer', 'transfer')->whereUuid('uuid')->name('transfer');
            Route::post('/calls/{uuid}/tap/{listen}', 'tap')->whereUuid('uuid')->whereNumber('listen')->name('tap');
            Route::post('/calls/{uuid}/listen', 'listen')->whereUuid('uuid')->middleware('throttle:20,1')->name('listen');
            Route::get('/listens/{listen}', 'listenShow')->name('listen.show');
            Route::post('/listens/{listen}/signal', 'listenSignal')->name('listen.signal');
            Route::post('/listens/{listen}/end', 'listenEnd')->name('listen.end');
            Route::get('/voice/{message}', 'voice')->name('voice');
        });
        Route::prefix('equipment-board')->name('facilities.')->controller(\App\Http\Controllers\It\FacilityController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::post('/standard', 'standard')->name('standard');
            Route::put('/{device}', 'update')->name('update');
            Route::delete('/{device}', 'destroy')->name('destroy');
            Route::post('/{device}/state', 'state')->middleware('throttle:120,1')->name('state');
            Route::post('/hubs', 'hubStore')->name('hubs.store');
            Route::post('/hubs/{hub}/revoke', 'hubRevoke')->name('hubs.revoke');
        });

        Route::get('/monitors', [ItMonitorController::class, 'index'])->name('monitors.index');
        Route::post('/monitors', [ItMonitorController::class, 'store'])->name('monitors.store');
        Route::get('/monitors/{monitor}', [ItMonitorController::class, 'show'])->name('monitors.show');
        Route::put('/monitors/{monitor}', [ItMonitorController::class, 'update'])->name('monitors.update');
        Route::delete('/monitors/{monitor}', [ItMonitorController::class, 'destroy'])->name('monitors.destroy');
        Route::post('/monitors/{monitor}/check', [ItMonitorController::class, 'check'])->middleware('throttle:20,1')->name('monitors.check');
        Route::post('/monitors/{monitor}/toggle', [ItMonitorController::class, 'toggle'])->name('monitors.toggle');

        Route::get('/equipment', [ItAssetController::class, 'index'])->name('assets.index');
        Route::post('/equipment', [ItAssetController::class, 'store'])->name('assets.store');
        Route::put('/equipment/{asset}', [ItAssetController::class, 'update'])->name('assets.update');
        Route::delete('/equipment/{asset}', [ItAssetController::class, 'destroy'])->name('assets.destroy');

        Route::get('/alerts', [ItAlertController::class, 'index'])->name('alerts.index');
        Route::post('/alerts/{alert}/acknowledge', [ItAlertController::class, 'acknowledge'])->name('alerts.acknowledge');
        Route::post('/alerts/{alert}/resolve', [ItAlertController::class, 'resolve'])->name('alerts.resolve');
        Route::post('/alerts/{alert}/ticket', [ItAlertController::class, 'ticket'])->name('alerts.ticket');

        Route::get('/maintenance', [ItMaintenanceController::class, 'index'])->name('maintenance.index');
        Route::post('/maintenance', [ItMaintenanceController::class, 'store'])->name('maintenance.store');
        Route::put('/maintenance/{window}', [ItMaintenanceController::class, 'update'])->name('maintenance.update');
        Route::post('/maintenance/{window}/cancel', [ItMaintenanceController::class, 'cancel'])->name('maintenance.cancel');

        // Any signed-in staff member can raise and follow tickets.
        Route::get('/tickets', [ItTicketController::class, 'index'])->name('tickets.index');
        Route::post('/tickets', [ItTicketController::class, 'store'])->middleware('throttle:20,1')->name('tickets.store');
        Route::get('/tickets/{ticket}', [ItTicketController::class, 'show'])->name('tickets.show');
        Route::post('/tickets/{ticket}/updates', [ItTicketController::class, 'update'])->name('tickets.update');
        Route::post('/tickets/{ticket}/assign', [ItTicketController::class, 'assign'])->name('tickets.assign');
        Route::post('/tickets/{ticket}/triage', [ItTicketController::class, 'triage'])->name('tickets.triage');

        // Internet & guest Wi-Fi: Starlink dishes and other lines, speed plans, guest Wi-Fi codes
        Route::prefix('network')->name('network.')->controller(\App\Http\Controllers\It\NetworkController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/summary', 'summary')->name('summary');
            Route::post('/refresh', 'refresh')->middleware('throttle:10,1')->name('refresh');
            Route::post('/links', 'storeLink')->name('links.store');
            Route::put('/links/{link}', 'updateLink')->name('links.update');
            Route::delete('/links/{link}', 'destroyLink')->name('links.destroy');
            Route::post('/plans', 'storePlan')->name('plans.store');
            Route::put('/plans/{plan}', 'updatePlan')->name('plans.update');
            Route::delete('/plans/{plan}', 'destroyPlan')->name('plans.destroy');
            Route::post('/codes', 'storePass')->middleware('throttle:30,1')->name('passes.store');
            Route::put('/codes/{pass}', 'updatePass')->name('passes.update');
            Route::post('/codes/{pass}/revoke', 'revokePass')->name('passes.revoke');
            Route::get('/codes/{pass}/print', 'printPass')->name('passes.print');
        });
    });

    // Group overview (multi-hotel)
    Route::get('/group', [\App\Http\Controllers\Settings\GroupController::class, 'index'])->name('group.index');
    Route::get('/group/reports', [\App\Http\Controllers\Settings\GroupController::class, 'reports'])->name('group.reports');
    Route::get('/group/commission', [\App\Http\Controllers\Settings\GroupController::class, 'commission'])->name('group.commission');
    Route::get('/group/commission/{hotel}', [\App\Http\Controllers\Settings\GroupController::class, 'statement'])->name('group.statement');
    Route::post('/group/commission/payments', [\App\Http\Controllers\Settings\GroupController::class, 'recordPayment'])->name('group.commission.pay');

    // Work in another hotel (multi-hotel top-bar switcher)
    Route::post('/hotels/switch', [\App\Http\Controllers\Settings\HotelController::class, 'switch'])->name('hotels.switch');

    // Hotel settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/hotel', [HotelSettingsController::class, 'index'])->name('hotel.index');
        Route::put('/hotel/{group}', [HotelSettingsController::class, 'update'])->where('group', '[a-z_]+')->name('hotel.update');
        Route::post('/hotel/reset', [HotelSettingsController::class, 'reset'])->name('hotel.reset');
        // Hotels: one or several, and who works where
        Route::get('/hotels', [\App\Http\Controllers\Settings\HotelController::class, 'index'])->name('hotels.index');
        Route::post('/hotels/mode', [\App\Http\Controllers\Settings\HotelController::class, 'mode'])->name('hotels.mode');
        Route::post('/hotels', [\App\Http\Controllers\Settings\HotelController::class, 'store'])->name('hotels.store');
        Route::put('/hotels/{hotel}', [\App\Http\Controllers\Settings\HotelController::class, 'update'])->name('hotels.update');
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
