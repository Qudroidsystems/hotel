<?php

use App\Http\Controllers\Api\V1\AppController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GuestBookingController;
use App\Http\Controllers\Api\V1\GuestStayController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\WorkController;
use App\Http\Middleware\MobileStaff;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile apps API — /api/v1
|--------------------------------------------------------------------------
| The guest app uses /guest/... (no sign-in). The staff app signs in with
| Laravel Sanctum tokens; until Sanctum is installed (composer require
| laravel/sanctum) only /app/config and the guest routes exist, and
| /app/config says so (staff_api: false).
*/

// Hardware: a building-management system or IoT hub reports room / area equipment states with its key.
Route::prefix('facility')->middleware('throttle:120,1')->controller(\App\Http\Controllers\Api\V1\FacilityReportController::class)->group(function () {
    Route::post('/report', 'report');
    Route::get('/devices', 'devices');
});

// Guests: their own booking, opened with the reference + e-mail/phone or the private link (no staff sign-in).
Route::prefix('guest')->group(function () {
    // Chat and calls with the hotel's sections (the booking's token is the key)
    Route::prefix('stays/{token}')->controller(\App\Http\Controllers\Chat\GuestChatController::class)->middleware('throttle:guest-chat')->group(function () {
        Route::get('/chat', 'index');
        Route::get('/chat/{section}', 'thread')->whereNumber('section');
        Route::post('/chat/{section}', 'send')->whereNumber('section');
        Route::post('/chat/{section}/typing', 'typing')->whereNumber('section');
        Route::post('/calls', 'call');
        Route::get('/calls/ringing', 'ringing');
        Route::get('/calls/{uuid}', 'show')->whereUuid('uuid');
        Route::post('/calls/{uuid}/signal', 'signal')->whereUuid('uuid');
        Route::post('/calls/{uuid}/answer', 'answer')->whereUuid('uuid');
        Route::post('/calls/{uuid}/end', 'end')->whereUuid('uuid');
    });
    Route::post('/find', [GuestStayController::class, 'find'])->middleware('throttle:booking-lookup');
    // Guest accounts: sign in with a code sent to the e-mail / phone they booked with.
    Route::post('/auth/code', [\App\Http\Controllers\Api\V1\GuestAccountController::class, 'code'])->middleware('throttle:booking-lookup');
    Route::post('/auth/verify', [\App\Http\Controllers\Api\V1\GuestAccountController::class, 'verify'])->middleware('throttle:booking-lookup');
    Route::middleware('throttle:mobile-public')->group(function () {
        Route::get('/me', [\App\Http\Controllers\Api\V1\GuestAccountController::class, 'me']);
        Route::patch('/me', [\App\Http\Controllers\Api\V1\GuestAccountController::class, 'update']);
        Route::post('/auth/logout', [\App\Http\Controllers\Api\V1\GuestAccountController::class, 'logout']);
    });
    Route::middleware('throttle:mobile-public')->group(function () {
        Route::get('/stays/{token}', [GuestStayController::class, 'show']);
        Route::get('/stays/{token}/menu', [GuestStayController::class, 'menu']);
        Route::get('/stays/{token}/rides', [GuestStayController::class, 'rides']);
        Route::get('/stays/{token}/events', [GuestStayController::class, 'events']);
        Route::get('/stays/{token}/feedback', [GuestStayController::class, 'feedbackForm']);
        Route::get('/stays/{token}/weather', [GuestStayController::class, 'weather']);
        Route::get('/stays/{token}/places', [GuestStayController::class, 'places']);
        Route::get('/stays/{token}/places/{id}', [GuestStayController::class, 'place'])->where('id', '[A-Za-z0-9_-]{10,300}');
        Route::post('/stays/{token}/rides/quote', [GuestStayController::class, 'rideQuote']);
        Route::get('/hotels', [GuestBookingController::class, 'hotels']);
        // The website's content for the app's home screen (Website in the sidebar)
        Route::get('/website', [\App\Http\Controllers\Api\V1\GuestWebsiteController::class, 'show']);
        Route::get('/booking', [GuestBookingController::class, 'options']);
        Route::get('/search', [GuestBookingController::class, 'search']);
        // Browsing without a booking, as on the website: events with tickets, the halls and when they are free
        Route::get('/events', [\App\Http\Controllers\Api\V1\GuestExploreController::class, 'events']);
        Route::post('/events/{event}/quote', [\App\Http\Controllers\Api\V1\GuestExploreController::class, 'ticketQuote'])->whereNumber('event');
        Route::get('/ticket-orders/{token}', [\App\Http\Controllers\Api\V1\GuestExploreController::class, 'ticketOrderShow'])->where('token', '[A-Za-z0-9]{48}');
        Route::get('/venues', [\App\Http\Controllers\Api\V1\GuestExploreController::class, 'venues']);
        Route::get('/venues/requests', [\App\Http\Controllers\Api\V1\GuestExploreController::class, 'myRequests']);
        Route::get('/venues/{hall}/availability', [\App\Http\Controllers\Api\V1\GuestExploreController::class, 'availability'])->whereNumber('hall');
    });
    Route::post('/bookings', [GuestBookingController::class, 'store'])->middleware('throttle:booking-actions');
    Route::post('/events/{event}/orders', [\App\Http\Controllers\Api\V1\GuestExploreController::class, 'ticketOrder'])->whereNumber('event')->middleware('throttle:ticket-orders');
    Route::post('/venues/requests', [\App\Http\Controllers\Api\V1\GuestExploreController::class, 'request'])->middleware('throttle:booking-actions');
    Route::post('/website/enquiries', [\App\Http\Controllers\Api\V1\GuestWebsiteController::class, 'enquiry'])->middleware('throttle:booking-actions');
    Route::post('/website/subscribe', [\App\Http\Controllers\Api\V1\GuestWebsiteController::class, 'subscribe'])->middleware('throttle:booking-actions');
    // The guest's luggage list (optional), ticked off when packing to leave
    Route::prefix('stays/{token}/luggage')->controller(\App\Http\Controllers\Api\V1\GuestLuggageController::class)->middleware('throttle:luggage')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::post('/unpack', 'unpack');
        Route::patch('/{item}', 'update')->whereNumber('item');
        Route::delete('/{item}', 'destroy')->whereNumber('item');
    });
    Route::middleware('throttle:arrival')->group(function () {
        Route::post('/stays/{token}/arrival', [GuestStayController::class, 'arrival']);
        Route::post('/stays/{token}/arrival/stop', [GuestStayController::class, 'stopArrival']);
    });
    Route::middleware('throttle:stay-actions')->group(function () {
        Route::post('/stays/{token}/requests', [GuestStayController::class, 'request']);
        Route::post('/stays/{token}/cancel', [GuestStayController::class, 'cancel']);
        Route::post('/stays/{token}/orders', [GuestStayController::class, 'order']);
        Route::post('/stays/{token}/tickets', [GuestStayController::class, 'buyTickets']);
        Route::post('/stays/{token}/feedback', [GuestStayController::class, 'feedback']);
        Route::post('/stays/{token}/rides', [GuestStayController::class, 'bookRide']);
        Route::post('/stays/{token}/rides/{ride}/cancel', [GuestStayController::class, 'cancelRide'])->whereNumber('ride');
        Route::post('/stays/{token}/orders/{order}/cancel', [GuestStayController::class, 'cancelOrder'])->whereNumber('order');
    });
});

// The hotel's name, logo and colour, for both apps. `staff_api` says whether staff can sign in yet.
Route::get('/app/config', [AppController::class, 'config'])->middleware('throttle:mobile-public');
// Sensors and switches report to Energy Saver: room presence, grid on/off, generators, fuel (X-Device-Key).
Route::post('/energy/device', [\App\Http\Controllers\Api\V1\EnergyApiController::class, 'device'])->middleware('throttle:120,1');
// Lets a socket listen on a private channel: guests with their booking token, staff with their sign-in.
Route::post('/realtime/auth', [\App\Http\Controllers\Chat\RealtimeController::class, 'auth'])->middleware('throttle:guest-chat');

if (! class_exists(\Laravel\Sanctum\Sanctum::class)) {
    return;   // staff sign-in needs Laravel Sanctum (composer require laravel/sanctum)
}

Route::middleware('throttle:mobile-public')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:mobile-login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:mobile-login');
});

Route::middleware(['auth:sanctum', MobileStaff::class, 'throttle:mobile-api'])->group(function () {
    // Account
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::put('/auth/password', [AuthController::class, 'password']);
    Route::post('/devices', [AuthController::class, 'device']);
    Route::delete('/devices', [AuthController::class, 'forgetDevice']);

    Route::get('/me/profile', [AppController::class, 'profile']);
    Route::put('/me/profile', [AppController::class, 'saveProfile']);
    Route::get('/me/preferences', [AppController::class, 'preferences']);
    Route::put('/me/preferences', [AppController::class, 'savePreferences']);

    // Screens
    Route::get('/home', [AppController::class, 'home']);
    Route::get('/front-desk', [AppController::class, 'frontDesk']);
    Route::get('/rooms', [AppController::class, 'rooms']);
    Route::get('/work', [WorkController::class, 'work']);
    Route::get('/approvals', [WorkController::class, 'approvals']);
    Route::post('/items/{kind}/{id}/actions/{action}', [WorkController::class, 'act'])
        ->whereIn('kind', array_merge(\App\Services\Mobile\StaffActions::WORK_KINDS, \App\Services\Mobile\StaffActions::APPROVAL_KINDS))
        ->whereNumber('id')->where('action', '[a-z_]+');
    // Guest chat and calls (the sections this person answers)
    // The door: tonight's ticketed events, scanning tickets in
    Route::get('/tickets/door', [\App\Http\Controllers\Api\V1\TicketDoorController::class, 'index']);
    Route::post('/tickets/scan', [\App\Http\Controllers\Api\V1\TicketDoorController::class, 'scan'])->middleware('throttle:240,1');
    // Energy Saver: rooms and places to switch off, the generator to run
    Route::controller(\App\Http\Controllers\Api\V1\EnergyApiController::class)->group(function () {
        Route::get('/energy', 'index');
        Route::post('/energy/actions/{action}/done', 'done')->whereNumber('action');
        Route::post('/energy/actions/{action}/skip', 'skip')->whereNumber('action');
        Route::post('/energy/grid', 'grid');
        Route::post('/energy/sources/{source}/running', 'running')->whereNumber('source');
        Route::post('/energy/sources/{source}/fuel', 'fuel')->whereNumber('source');
        Route::post('/energy/sources/{source}/delivery', 'delivery')->whereNumber('source');
    });
    Route::controller(\App\Http\Controllers\Chat\StaffChatController::class)->group(function () {
        Route::get('/chats', 'index');
        Route::get('/chats/{conversation}', 'show')->whereNumber('conversation');
        Route::post('/chats/{conversation}', 'send')->whereNumber('conversation')->middleware('throttle:60,1');
        Route::post('/chats/{conversation}/typing', 'typing')->whereNumber('conversation');
        Route::post('/chats/{conversation}/close', 'close')->whereNumber('conversation');
        Route::post('/chats/{conversation}/assign', 'assign')->whereNumber('conversation');
        Route::post('/chats/{conversation}/call', 'callBack')->whereNumber('conversation');
        Route::get('/calls/ringing', 'ringing');
        Route::get('/calls/{uuid}', 'call')->whereUuid('uuid');
        Route::post('/calls/{uuid}/answer', 'answer')->whereUuid('uuid');
        Route::post('/calls/{uuid}/signal', 'signal')->whereUuid('uuid');
        Route::post('/calls/{uuid}/end', 'end')->whereUuid('uuid');
        Route::post('/calls/{uuid}/transfer', 'transfer')->whereUuid('uuid');
        Route::get('/calls/{uuid}/targets', 'targets')->whereUuid('uuid');
        Route::post('/calls/{uuid}/tap/{listen}', 'tap')->whereUuid('uuid')->whereNumber('listen');
    });
    Route::get('/report/options', [WorkController::class, 'reportOptions']);
    Route::post('/report', [WorkController::class, 'report']);

    // Night audit, events, company billing
    Route::controller(\App\Http\Controllers\Api\V1\OperationsController::class)->group(function () {
        Route::get('/night-audit', 'nightAudit');
        Route::post('/night-audit/close', 'closeDay')->middleware('throttle:6,1');
        Route::get('/night-audit/{audit}', 'auditReport')->whereNumber('audit');
        Route::get('/events', 'events');
        Route::get('/events/{event}', 'event')->whereNumber('event');
        Route::post('/events/{event}/status', 'eventStatus')->whereNumber('event');
        Route::get('/companies', 'companies');
        Route::get('/group', 'group');
        Route::get('/owner', 'owner');
        Route::get('/driver', 'driver');
        Route::post('/driver/duty', 'driverDuty');
        Route::post('/driver/location', 'driverLocation');
        Route::post('/folios/{folio}/to-company', 'billToCompany')->whereNumber('folio')->middleware('throttle:30,1');
    });

    // Alerts
    Route::prefix('notifications')->controller(NotificationController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/unread-count', 'unreadCount');
        Route::post('/read-all', 'readAll');
        Route::post('/clear-read', 'clearRead');
        Route::get('/settings', 'settings');
        Route::put('/settings', 'saveSettings');
        Route::post('/{id}/read', 'read')->whereUuid('id');
        Route::delete('/{id}', 'destroy')->whereUuid('id');
        Route::post('/{id}/actions/{action}', 'action')->whereUuid('id')->where('action', '[a-z_]+');
    });
});
