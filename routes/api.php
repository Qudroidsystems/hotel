<?php

use App\Http\Controllers\Api\V1\AppController;
use App\Http\Controllers\Api\V1\AuthController;
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
    Route::middleware('throttle:mobile-public')->group(function () {
        Route::get('/stays/{token}', [GuestStayController::class, 'show']);
    });
    Route::middleware('throttle:stay-actions')->group(function () {
        Route::post('/stays/{token}/requests', [GuestStayController::class, 'request']);
        Route::post('/stays/{token}/cancel', [GuestStayController::class, 'cancel']);
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
