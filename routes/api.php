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

// Guests: their own booking, opened with the reference + e-mail/phone or the private link (no staff sign-in).
Route::prefix('guest')->group(function () {
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
        Route::get('/stays/{token}/places', [GuestStayController::class, 'places']);
        Route::get('/stays/{token}/places/{id}', [GuestStayController::class, 'place'])->where('id', '[A-Za-z0-9_-]{10,300}');
        Route::post('/stays/{token}/rides/quote', [GuestStayController::class, 'rideQuote']);
        Route::get('/hotels', [GuestBookingController::class, 'hotels']);
        Route::get('/booking', [GuestBookingController::class, 'options']);
        Route::get('/search', [GuestBookingController::class, 'search']);
    });
    Route::post('/bookings', [GuestBookingController::class, 'store'])->middleware('throttle:booking-actions');
    Route::middleware('throttle:arrival')->group(function () {
        Route::post('/stays/{token}/arrival', [GuestStayController::class, 'arrival']);
        Route::post('/stays/{token}/arrival/stop', [GuestStayController::class, 'stopArrival']);
    });
    Route::middleware('throttle:stay-actions')->group(function () {
        Route::post('/stays/{token}/requests', [GuestStayController::class, 'request']);
        Route::post('/stays/{token}/cancel', [GuestStayController::class, 'cancel']);
        Route::post('/stays/{token}/orders', [GuestStayController::class, 'order']);
        Route::post('/stays/{token}/rides', [GuestStayController::class, 'bookRide']);
        Route::post('/stays/{token}/rides/{ride}/cancel', [GuestStayController::class, 'cancelRide'])->whereNumber('ride');
        Route::post('/stays/{token}/orders/{order}/cancel', [GuestStayController::class, 'cancelOrder'])->whereNumber('order');
    });
});

// The hotel's name, logo and colour, for both apps. `staff_api` says whether staff can sign in yet.
Route::get('/app/config', [AppController::class, 'config'])->middleware('throttle:mobile-public');

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
        ->whereIn('kind', ['guest', 'housekeeping', 'maintenance', 'ticket', 'expense', 'po', 'pr', 'shift', 'count'])
        ->whereNumber('id')->where('action', '[a-z_]+');
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
