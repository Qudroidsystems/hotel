<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\User;
use App\Services\It\MonitorService;
use App\Support\HotelSettings;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Is this server ready to run the hotel? Checks the server, the database, the nightly jobs, backups, and each
 * hotel's accounts and set-up. Red lines must be fixed before going live; amber ones are worth a look.
 *   php artisan hotel:launch-check          (--brief: only what needs attention)
 */
class LaunchCheck extends Command
{
    protected $signature = 'hotel:launch-check {--brief : Only show what needs attention} {--backups=/var/backups/hotel : Where deploy/backup.sh writes}';

    protected $description = 'Check the server and each hotel are ready to go live';

    private int $fails = 0;
    private int $warns = 0;

    public function handle(): int
    {
        $this->line('<options=bold>Server</>');
        $this->server();

        $this->newLine();
        $this->line('<options=bold>Database</>');
        $dbOk = $this->database();

        if ($dbOk) {
            $this->newLine();
            $this->line('<options=bold>Jobs and backups</>');
            $this->jobs();

            $this->newLine();
            $this->line('<options=bold>Accounts</>');
            $this->accounts();

            foreach (CurrentHotel::get()->hotels() as $hotel) {
                CurrentHotel::get()->run($hotel, function () use ($hotel) {
                    HotelSettings::applyFor($hotel->id);
                    $this->newLine();
                    $this->line('<options=bold>' . $hotel->name . '</>');
                    $this->hotel($hotel);
                });
            }
        }

        $this->newLine();
        if ($this->fails) {
            $this->error(" {$this->fails} to fix before going live" . ($this->warns ? ", {$this->warns} to look at" : '') . '. ');

            return self::FAILURE;
        }
        $this->info($this->warns ? " Ready, with {$this->warns} thing(s) to look at. " : ' Ready to go live. ');

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------

    private function server(): void
    {
        $prod = app()->environment('production');
        $this->check($prod, 'APP_ENV is production', 'APP_ENV is "' . app()->environment() . '": set APP_ENV=production on the live server', warnOnly: true);
        $this->check(! config('app.debug'), 'Debug pages are off', 'APP_DEBUG is true: error pages would show passwords and keys. Set APP_DEBUG=false');
        $this->check(filled(config('app.key')), 'APP_KEY is set', 'APP_KEY is empty: run php artisan key:generate (and keep a copy of it safe)');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $this->check($https, 'APP_URL uses https (' . config('app.url') . ')', 'APP_URL is ' . config('app.url') . ': use the https:// address, or Paystack, the apps and push notifications will fail', warnOnly: ! $prod);
        $this->check(version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP ' . PHP_VERSION, 'PHP ' . PHP_VERSION . ' is too old: install PHP 8.3');
        foreach (['mbstring', 'pdo_mysql', 'gd', 'zip', 'intl', 'curl', 'xml'] as $ext) {
            if (! extension_loaded($ext)) {
                $this->check(false, '', "PHP extension {$ext} is missing: sudo apt install php8.3-{$ext}");
            }
        }
        $this->check(class_exists(\Laravel\Sanctum\Sanctum::class), 'Staff app sign-in (Sanctum) installed', 'Laravel Sanctum is missing, so the staff app cannot sign in: composer require laravel/sanctum');
        $this->check(file_exists(public_path('storage')), 'Uploaded files are reachable (storage link)', 'No public/storage link: run php artisan storage:link');
        foreach ([storage_path(), base_path('bootstrap/cache'), public_path('uploads')] as $dir) {
            $this->check(is_dir($dir) && is_writable($dir), 'Writable: ' . str_replace(base_path() . '/', '', $dir), 'Not writable by the web server: ' . $dir . ' (chown -R www-data:www-data)');
        }
    }

    private function database(): bool
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->check(false, '', 'Cannot reach the database: ' . $e->getMessage());

            return false;
        }
        $this->check(true, 'Connected to ' . DB::connection()->getDatabaseName() . ' (' . DB::connection()->getDriverName() . ')', '');

        $migrator = app('migrator');
        $ran = $migrator->getRepository()->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
        $pending = array_diff($files, $ran);
        $this->check(! $pending, 'All ' . count($files) . ' migrations have run', count($pending) . ' migration(s) not run: php artisan migrate --force');

        return ! $pending;
    }

    private function jobs(): void
    {
        $beat = Cache::get(MonitorService::HEARTBEAT);
        $fresh = $beat && \Illuminate\Support\Carbon::parse($beat)->gt(now()->subMinutes(5));
        $this->check($fresh, 'Scheduler is running (last run ' . ($beat ? \Illuminate\Support\Carbon::parse($beat)->diffForHumans() : 'never') . ')',
            'The scheduler is not running' . ($beat ? ' (last run ' . \Illuminate\Support\Carbon::parse($beat)->diffForHumans() . ')' : '') . ': install deploy/crontab with sudo crontab -u www-data deploy/crontab');

        $dir = (string) $this->option('backups');
        $latest = is_dir($dir) ? collect(glob($dir . '/db_*.sql.gz') ?: [])->sortByDesc(fn ($f) => filemtime($f))->first() : null;
        $this->check($latest && filemtime($latest) > now()->subHours(26)->timestamp,
            'Last night\'s backup: ' . ($latest ? basename($latest) : ''),
            $latest ? 'Newest backup is ' . \Illuminate\Support\Carbon::createFromTimestamp(filemtime($latest))->diffForHumans() . ': check deploy/backup.sh and storage/logs/backup.log' : "No backups in {$dir}: install deploy/crontab and run deploy/backup.sh once",
            warnOnly: ! app()->environment('production'));
        $remote = (string) env('BACKUP_REMOTE', '');
        $this->check($remote !== '', 'Backups are copied off the server (' . $remote . ')', 'BACKUP_REMOTE is not set: a backup kept only on this server is lost with it', warnOnly: true);
    }

    private function accounts(): void
    {
        $admins = User::role('Super Admin')->get();
        $default = $admins->first(fn ($u) => Hash::check((string) env('ADMIN_PASSWORD', 'ChangeMe@123'), $u->password) || Hash::check('ChangeMe@123', $u->password));
        $this->check(! $default, 'Super Admin password has been changed', 'The Super Admin (' . $default?->email . ') still has the starting password: sign in and change it');

        $demo = User::where('email', 'like', '%@smarthotel.test')->count();
        $this->check($demo === 0, 'No demo staff accounts', "{$demo} demo staff account(s) (@smarthotel.test) exist: this looks like demo data, not the live hotel");
        $this->check(User::where('is_disabled', false)->count() > 1, User::where('is_disabled', false)->count() . ' active staff accounts', 'Only the Super Admin exists: add staff under Users', warnOnly: true);
        if (CurrentHotel::multi()) {
            $this->check(true, 'Several hotels: ' . Hotel::active()->count() . ' open', '');
            $unassigned = User::where('is_disabled', false)->whereDoesntHave('hotels')->get()->reject(fn ($u) => $u->seesAllHotels())->count();
            $this->check($unassigned === 0, 'Every member of staff works at a hotel', "{$unassigned} staff account(s) are not added to any hotel (Settings → Hotels)", warnOnly: true);
        }
    }

    private function hotel(Hotel $hotel): void
    {
        $rooms = \App\Models\Room::active()->count();
        $this->check($rooms > 0, "{$rooms} rooms set up", 'No rooms yet: add room types and rooms');
        $this->check((bool) config('hotel.has_logo'), 'Logo uploaded', 'No logo: Hotel Settings → Branding (the name is shown instead)', warnOnly: true);

        $demoGuests = \App\Models\Guest::where('email', 'like', '%@example.com')->count();
        $this->check($demoGuests === 0, 'No demo guests', "{$demoGuests} demo guest(s) (@example.com): this looks like demo data", warnOnly: true);

        $mail = config('mail.default');
        $this->check($mail !== 'log' && filled(config('mail.mailers.smtp.host')), 'Email sends through ' . config('mail.mailers.smtp.host'), 'Email is not set up, so booking confirmations and password resets go nowhere: Hotel Settings → Email');

        $pay = app(\App\Services\Booking\PaystackService::class);
        if (! $pay->enabled()) {
            $this->check(false, '', 'Online payments are off (no Paystack key): online bookings are held unpaid. Hotel Settings → Online Payments', warnOnly: true);
        } else {
            $live = str_starts_with((string) config('services.paystack.secret_key'), 'sk_live_');
            $this->check($live, 'Paystack live key', 'Paystack is using a TEST key (sk_test_): guests are not really charged. Put the sk_live_ key in Hotel Settings → Online Payments');
        }

        if (config('hotel.messaging.enabled')) {
            $p = config('hotel.messaging.provider');
            $ok = $p === 'termii' ? filled(config('services.termii.api_key')) : filled(config('services.twilio.sid')) && filled(config('services.twilio.auth_token'));
            $this->check($ok, "Guest messages through {$p}", "Guest messages are on but the {$p} account is missing: Hotel Settings → SMS & WhatsApp Accounts");
        } else {
            $this->check(true, 'Guest messages off (switch on after go-live week)', '');
        }
        $this->check(filled(config('services.fcm.credentials_json')) || filled(config('services.fcm.credentials')), 'Push notifications (Firebase) set up',
            'No Firebase key: staff get alerts inside the app but no phone notifications. Hotel Settings → Phone Notifications', warnOnly: true);

        $behind = \App\Models\NightAudit::exists() ? \App\Models\NightAudit::businessDate()->diffInDays(today(), false) : 0;
        $this->check($behind <= 1, 'Night audit is up to date', (int) $behind . ' day(s) not closed: Front Desk → Night Audit', warnOnly: true);
    }

    private function check(bool $ok, string $good, string $bad, bool $warnOnly = false): void
    {
        if ($ok) {
            if (! $this->option('brief') && $good !== '') {
                $this->line("  <fg=green>✔</> {$good}");
            }

            return;
        }
        if ($warnOnly) {
            $this->warns++;
            $this->line("  <fg=yellow>!</> {$bad}");
        } else {
            $this->fails++;
            $this->line("  <fg=red>✖</> {$bad}");
        }
    }
}
