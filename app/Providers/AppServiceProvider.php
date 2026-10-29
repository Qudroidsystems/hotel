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
        // Sign-in tokens for the staff mobile app. Registered here because composer.json
        // lists Sanctum under "dont-discover"; skipped until it is installed.
        if (class_exists(\Laravel\Sanctum\SanctumServiceProvider::class)) {
            $this->app->register(\Laravel\Sanctum\SanctumServiceProvider::class);
        }

        // Which hotel this request works in (multi-hotel); one per request.
        $this->app->scoped(\App\Support\Tenancy\CurrentHotel::class);
        // Form checks (exists:, unique:) look at the current hotel's rows only.
        $this->app->extend('validation.presence', fn ($v, $app) => new \App\Support\Tenancy\HotelPresenceVerifier($app['db']));
    }

    public function boot(): void
    {
        // Settings saved by the administrator override config/hotel.php (Settings → Hotel Settings).
        HotelSettings::apply();
        // The template's sample logo is not the hotel's: until one is uploaded (Hotel Settings → Branding),
        // pages show the hotel's name instead of a picture.
        config(['hotel.has_logo' => filled(config('hotel.logo')) && ! str_starts_with((string) config('hotel.logo'), 'theme/')]);

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
        // Chat and calls: a live conversation asks often (new messages, call signals), so per booking, generously.
        RateLimiter::for('guest-chat', fn (Request $r) => Limit::perMinute(240)->by('gc|' . ($r->route('token') ?: $r->input('token') ?: $r->ip())));
        RateLimiter::for('arrival', fn (Request $r) => Limit::perMinute(20)->by('ar|' . $r->route('token')));
        RateLimiter::for('booking-lookup', fn (Request $r) => Limit::perMinute(6)->by('bl|' . $r->ip()));
        RateLimiter::for('paystack-webhook', fn (Request $r) => Limit::perMinute(300)->by('pw|' . $r->ip()));
        RateLimiter::for('twilio-webhook', fn (Request $r) => Limit::perMinute(600)->by('tw|' . $r->ip()));

        // Staff mobile app: sign-in attempts per address and login name; signed-in calls per person.
        RateLimiter::for('mobile-public', fn (Request $r) => Limit::perMinute(60)->by('mp|' . $r->ip()));
        RateLimiter::for('mobile-login', fn (Request $r) => [
            Limit::perMinute(10)->by('ml|' . $r->ip()),
            Limit::perMinute(5)->by('mlu|' . mb_strtolower((string) $r->input('login', $r->input('email')))),
        ]);
        RateLimiter::for('mobile-api', fn (Request $r) => Limit::perMinute(180)->by('ma|' . ($r->user()?->id ?? $r->ip())));

        // Alerts for the staff app and phones when work is assigned or something needs approving.
        \App\Services\Mobile\StaffAlerts::register();
        // Arrival sharing stops once the guest is checked in (or the booking ends another way).
        \App\Models\Reservation::updated(function (\App\Models\Reservation $r) {
            if ($r->wasChanged('status') && in_array($r->status, [\App\Enums\ReservationStatus::CheckedIn, \App\Enums\ReservationStatus::Cancelled, \App\Enums\ReservationStatus::NoShow], true)
                && \Illuminate\Support\Facades\Schema::hasTable('guest_arrivals')) {
                app(\App\Services\Booking\ArrivalService::class)->stopFor($r, $r->status === \App\Enums\ReservationStatus::CheckedIn ? 'checked_in' : 'cancelled');
            }
        });

        // Texts to guests (Hotel Settings → Guest Messages): confirmation, deposit link, thank you, request updates.
        \App\Services\Messaging\MessagingHooks::register();

        // Guest loyalty: points follow the bill (earned when it settles after check-out; a voided points line gives them back).
        \App\Models\Folio::saved(function (\App\Models\Folio $folio) {
            if ($folio->wasChanged('status') && self::loyaltyReady()) {
                app(\App\Services\Loyalty\LoyaltyService::class)->syncEarn($folio);
            }
        });
        \App\Models\FolioCharge::saved(function (\App\Models\FolioCharge $charge) {
            if ($charge->wasChanged('voided_at') && $charge->voided_at && self::loyaltyReady()) {
                app(\App\Services\Loyalty\LoyaltyService::class)->chargeVoided($charge);
            }
        });

        // Room lifecycle: a room that needs cleaning gets a housekeeping task (spec §3.3).
        RoomStatusLog::created(fn (RoomStatusLog $log) => app(HousekeepingService::class)->roomStatusChanged($log));
    }

    /** The loyalty tables exist (false until `php artisan migrate` has run). */
    private static function loyaltyReady(): bool
    {
        static $ready = null;

        return $ready ??= \Illuminate\Support\Facades\Schema::hasTable('loyalty_entries');
    }
}
