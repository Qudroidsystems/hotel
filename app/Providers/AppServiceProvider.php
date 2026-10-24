<?php

namespace App\Providers;

use App\Models\RoomStatusLog;
use App\Services\Operations\HousekeepingService;
use App\Support\HotelSettings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Settings saved by the administrator override config/hotel.php (Settings → Hotel Settings).
        HotelSettings::apply();

        // Render pagination with the Bootstrap 5 theme (matches the Velzon UI).
        Paginator::useBootstrapFive();

        // The Super Admin role passes every permission check.
        Gate::before(fn ($user) => $user->hasRole('Super Admin') ? true : null);

        // @money($amount) → ₦25,000.00 (symbol from config/hotel.php)
        Blade::directive('money', fn ($expr) => "<?php echo e(config('hotel.currency_symbol') . number_format((float) ($expr), 2)); ?>");

        // Public booking pages: browsing and actions are limited separately, so a guest
        // looking at rooms is never blocked from actually booking.
        RateLimiter::for('booking-pages', fn (Request $r) => Limit::perMinute(90)->by('bp|' . $r->ip()));
        // Guests on the hotel Wi-Fi can share one public IP, so per-IP limits stay generous
        // and actions on an existing booking are limited per booking instead.
        RateLimiter::for('booking-actions', fn (Request $r) => Limit::perMinute(30)->by('ba|' . $r->ip()));
        RateLimiter::for('stay-actions', fn (Request $r) => Limit::perMinute(8)->by('sa|' . $r->route('token')));
        RateLimiter::for('booking-lookup', fn (Request $r) => Limit::perMinute(6)->by('bl|' . $r->ip()));
        RateLimiter::for('paystack-webhook', fn (Request $r) => Limit::perMinute(300)->by('pw|' . $r->ip()));

        // Room lifecycle: a room that needs cleaning gets a housekeeping task (spec §3.3).
        RoomStatusLog::created(fn (RoomStatusLog $log) => app(HousekeepingService::class)->roomStatusChanged($log));
    }
}
