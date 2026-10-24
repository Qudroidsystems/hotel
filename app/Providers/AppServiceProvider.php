<?php

namespace App\Providers;

use App\Models\RoomStatusLog;
use App\Services\Operations\HousekeepingService;
use App\Support\HotelSettings;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
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

        // Room lifecycle: a room that needs cleaning gets a housekeeping task (spec §3.3).
        RoomStatusLog::created(fn (RoomStatusLog $log) => app(HousekeepingService::class)->roomStatusChanged($log));
    }
}
