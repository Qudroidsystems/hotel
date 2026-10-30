<?php

use App\Services\Reservations\ReservationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Multi-hotel: each job runs once for every active hotel, with that hotel's data and settings.
$eachHotel = fn (\Closure $body) => function () use ($body) {
    $command = $this;
    $hotels = \App\Support\Tenancy\CurrentHotel::get()->hotels();
    foreach ($hotels as $hotel) {
        \App\Support\Tenancy\CurrentHotel::get()->run($hotel, function () use ($body, $command, $hotels, $hotel) {
            if ($hotels->count() > 1) {
                $command->line("<comment>{$hotel->name}</comment>");
            }
            app()->call(\Closure::bind($body, $command, $command));
        });
    }
};

// Rooms assigned to today's arrivals show as Reserved on the room board.
// Needs the scheduler: `* * * * * php /path/to/hotel/artisan schedule:run`
Artisan::command('rooms:prepare-arrivals', $eachHotel(function (ReservationService $service) {
    $this->info($service->prepareTodaysArrivals() . " room(s) marked Reserved for today's arrivals.");
}))->purpose("Mark rooms assigned to today's arrivals as Reserved");

// Night audit: post last night's room charge to every in-house guest's bill.
Artisan::command('folios:night-audit', $eachHotel(function (\App\Services\Billing\FolioService $folios) {
    $this->info($folios->nightAudit() . ' room night(s) posted.');
}))->purpose("Post room charges for nights already stayed by in-house guests");

// Visitor privacy: clear phone, ID number and plate from old visits (spec §10.1).
Artisan::command('security:prune-visitors', $eachHotel(function (\App\Services\Security\SecurityService $security) {
    $this->info($security->pruneVisitors() . ' old visitor record(s) anonymised.');
}))->purpose('Clear personal details from visitor records older than the retention period');

// IT control room (spec §11): run the monitors that are due, escalate overdue
// tickets, and trim old check history.
Artisan::command('it:monitor {--all : Check every monitor now, not only the ones due}', $eachHotel(function (\App\Services\It\MonitorService $monitors) {
    $this->info($monitors->runDue((bool) $this->option('all')) . ' monitor(s) checked.');
}))->purpose('Run the IT control room checks that are due');

Artisan::command('it:escalate', $eachHotel(function (\App\Services\It\ServiceDesk $desk) {
    $this->info($desk->escalateOverdue() . ' overdue IT ticket(s) escalated.');
}))->purpose('Escalate IT tickets that missed their response or fix target');

Artisan::command('tasks:escalate', $eachHotel(function (\App\Services\Operations\TaskService $tasks) {
    $this->info($tasks->escalate() . ' overdue task reminder(s) sent.');
}))->purpose('Remind and escalate department tasks that are past their due time');

// Internet & guest Wi-Fi: read every internet line (Starlink dishes…), track Wi-Fi codes, close expired ones.
Artisan::command('network:poll', $eachHotel(function (\App\Services\Network\NetworkService $network) {
    $this->info($network->poll() . ' internet line(s) read.');
}))->purpose('Read the internet lines and guest Wi-Fi codes');

Artisan::command('network:prune', $eachHotel(function (\App\Services\Network\NetworkService $network) {
    $this->info($network->prune() . ' old internet reading(s) removed.');
}))->purpose('Remove internet line readings older than the retention period');

Artisan::command('it:prune', $eachHotel(function (\App\Services\It\MonitorService $monitors) {
    $this->info($monitors->prune() . ' old check result(s) removed.');
}))->purpose('Remove IT check history older than the retention period');

// Online bookings waiting for a deposit are released when their hold runs out.
Artisan::command('rates:auto-price {--force : Even when automatic pricing is off}', $eachHotel(function (\App\Services\Rates\RevenueService $revenue) {
    if (! $this->option('force') && ! $revenue->rules()['auto']) {
        return;
    }
    $this->info($revenue->apply(null, null, true) . ' night price(s) set from the suggestions.');
}))->purpose('Apply the price suggestions to nights not priced by hand (Hotel Settings → Price Suggestions → automatic)');

Artisan::command('calls:expire', $eachHotel(function (\App\Services\Chat\CallService $calls) {
    $n = $calls->expire();
    $this->info("{$n} unanswered guest call(s) marked missed.");
}))->purpose('Guest calls ringing for too long become missed calls (shown in the chat with a call-back)');

Artisan::command('arrivals:expire', $eachHotel(function (\App\Services\Booking\ArrivalService $arrivals) {
    $n = $arrivals->expire();
    $this->info("{$n} arrival share(s) stopped.");
}))->purpose('Stop arrival sharing that has gone quiet or run too long, and forget the position');

Artisan::command('bookings:expire-holds', $eachHotel(function (\App\Services\Booking\OnlineBookingService $booking) {
    $this->info($booking->expireHolds() . ' unpaid online booking(s) released.');
}))->purpose('Cancel online bookings whose deposit was not paid in time');

// Guest loyalty: give member numbers, award points for settled stays (first run: past stays too) and
// move guests between tiers as the 12-month window moves on.
Artisan::command('loyalty:sync', $eachHotel(function (\App\Services\Loyalty\LoyaltyService $loyalty) {
    if (! $loyalty->enabled()) {
        $this->warn('The loyalty programme is switched off (Hotel Settings → Guest Loyalty).');

        return;
    }
    $points = 0;
    \App\Models\Folio::where('status', 'closed')->whereHas('reservation', fn ($q) => $q->where('status', 'checked_out'))
        ->with('reservation.guest')->chunkById(200, function ($folios) use ($loyalty, &$points) {
            foreach ($folios as $folio) {
                $points += $loyalty->syncEarn($folio);
            }
        });
    $moved = 0;
    \App\Models\Guest::whereHas('reservations', fn ($q) => $q->where('status', 'checked_out'))->orWhere('loyalty_tier', '!=', 'member')
        ->chunkById(200, function ($guests) use ($loyalty, &$moved) {
            foreach ($guests as $g) {
                $before = $g->loyalty_tier;
                $loyalty->ensureNumber($g);
                $moved += $loyalty->refreshTier($g) !== $before ? 1 : 0;
            }
        });
    $this->info(number_format($points) . " point(s) awarded; {$moved} guest(s) changed tier.");
}))->purpose('Award loyalty points for settled stays and update member tiers');

// Guest texts: a reminder the day before arrival (Hotel Settings → Guest Messages).
Artisan::command('messages:arrival-reminders', $eachHotel(function (\App\Services\Messaging\GuestMessenger $messenger) {
    if (! $messenger->enabled() || ! config('hotel.messaging.send_reminder', true)) {
        $this->info('Arrival reminders are switched off.');

        return;
    }
    $sent = 0;
    \App\Models\Reservation::whereIn('status', ['confirmed', 'pending'])->whereDate('check_in_date', today()->addDay())->with('guest')
        ->each(function ($r) use ($messenger, &$sent) {
            $sent += ($messenger->aboutBooking($r, 'arrival_reminder')?->status === 'sent') ? 1 : 0;
        });
    $this->info("{$sent} arrival reminder(s) sent.");
}))->purpose('Text guests arriving tomorrow');

// Luggage lists: on check-out day, remind guests to go through what they brought (from the set time, once).
Artisan::command('luggage:checkout-reminders', $eachHotel(function (\App\Services\Booking\LuggageService $luggage) {
    $this->info($luggage->sendReminders() . ' luggage reminder(s) sent.');
}))->purpose('Remind guests leaving today to check their luggage list');

// Night audit: remind staff when a finished day hasn't been closed, and close it by itself when that is switched on.
Artisan::command('night-audit:auto', $eachHotel(function (\App\Services\NightAudit\NightAuditService $audits) {
    $closed = 0;
    for ($i = 0; $i < 7; $i++) {
        $date = \App\Models\NightAudit::businessDate();
        if ($date->gte(today())) {
            break;   // the day being worked hasn't ended yet
        }
        $now = now()->format('H:i');
        $key = 'night-audit-reminded:' . \App\Support\Tenancy\CurrentHotel::get()->id() . ':' . $date->toDateString();
        if ($now >= (string) config('hotel.night_audit.day_ends_at', '06:00') && ! \Illuminate\Support\Facades\Cache::has($key)) {
            \Illuminate\Support\Facades\Cache::put($key, true, now()->addDays(2));
            app(\App\Services\Mobile\StaffAlerts::class)->notify('Run night audit', [
                'title' => 'Night audit: ' . $date->format('D j M') . ' is not closed',
                'body'  => config('hotel.night_audit.auto_close') ? 'It will close by itself at ' . config('hotel.night_audit.auto_close_at') . '.' : 'Close the day from Front Desk → Night Audit.',
                'url'   => route('night-audit.index'), 'thread' => 'night-audit', 'level' => 'high',
            ]);
        }
        if (! config('hotel.night_audit.auto_close') || $now < (string) config('hotel.night_audit.auto_close_at', '05:00')) {
            break;
        }
        $noShows = config('hotel.night_audit.auto_no_shows', true) ? $audits->checks($date)['arrivals']->pluck('id')->all() : [];
        $audits->close($date, $noShows, 'Closed automatically.', true);
        $closed++;
    }
    $this->info($closed ? "{$closed} day(s) closed." : 'Nothing to close.');
}))->purpose('Remind about, or automatically run, the night audit');

// Events: a heads-up the afternoon before, for the kitchen, setup crew and front desk.
Artisan::command('events:tomorrow', $eachHotel(function () {
    $events = \App\Models\Events\Event::with('hall')->whereIn('status', ['confirmed', 'tentative'])
        ->where('starts_at', '>=', today()->addDay())->where('starts_at', '<', today()->addDays(2))->orderBy('starts_at')->get();
    if ($events->isNotEmpty()) {
        app(\App\Services\Mobile\StaffAlerts::class)->notify('View events', [
            'title' => $events->count() . ' event(s) tomorrow',
            'body'  => $events->map(fn ($e) => $e->starts_at->format('g:i A') . ' ' . $e->title . ' (' . $e->hall?->name . ', ' . $e->guests_expected . ')')->implode(' · '),
            'url'   => route('events.index'), 'thread' => 'events-tomorrow:' . today()->toDateString(),
        ]);
    }
    $this->info($events->count() . ' event(s) tomorrow.');
}))->purpose('Tell staff about tomorrow\'s events');

// Room calendars: read Airbnb, Vrbo ... calendars and book in, move or cancel their bookings.
Artisan::command('channels:sync {--full : Read every calendar now}', $eachHotel(function (\App\Services\Channels\ChannelManager $channels) {
    $d = $channels->run((bool) $this->option('full'));
    $this->info("Calendar changes: {$d['calendars']}.");
    foreach ($d['errors'] as $e) {
        $this->warn($e);
    }
}))->purpose('Read the room calendars (iCal) from Airbnb, Vrbo …');

Schedule::command('events:tomorrow')->dailyAt('16:00');
Schedule::command('channels:sync')->everyFiveMinutes()->withoutOverlapping(15);
Schedule::command('night-audit:auto')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('bookings:expire-holds')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('arrivals:expire')->everyTenMinutes()->withoutOverlapping();
Schedule::command('calls:expire')->everyMinute()->withoutOverlapping();
Schedule::command('rates:auto-price')->dailyAt('02:15')->withoutOverlapping();
Schedule::command('it:monitor')->everyMinute()->withoutOverlapping(10);
Schedule::command('it:escalate')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tasks:escalate')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('it:prune')->dailyAt('03:30');
Schedule::command('network:poll')->everyMinute()->withoutOverlapping(5);
Schedule::command('network:prune')->dailyAt('03:40');
Schedule::command('rooms:prepare-arrivals')->dailyAt('00:05');
Schedule::command('folios:night-audit')->dailyAt('02:00');
Schedule::command('security:prune-visitors')->dailyAt('03:00');
Schedule::command('loyalty:sync')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('luggage:checkout-reminders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('messages:arrival-reminders')->dailyAt((string) config('hotel.messaging.reminder_time', '10:00'))->withoutOverlapping();

// Call console demo: the demo hardware hub flips a few equipment states (php artisan facility:simulate --loop).
Artisan::command('facility:simulate {--loop : Keep going every 20 seconds} {--n=4 : How many devices to change}', $eachHotel(function (\App\Services\Facility\FacilityService $facility) {
    do {
        $hub = \App\Models\Facility\FacilityHub::where('is_active', true)->first();
        $devices = \App\Models\Facility\FacilityDevice::active()->inRandomOrder()->limit((int) $this->option('n'))->get();
        if (! $hub || $devices->isEmpty()) {
            $this->warn('No demo hub or hardware-fed equipment (run db:seed --class=CallConsoleDemoSeeder).');

            return;
        }
        $report = $devices->map(fn ($d) => ['code' => $d->code, 'state' => match (true) {
            $d->is_critical && $d->state === 'fault' => 'on',
            mt_rand(1, 25) === 1 => 'fault',
            default => $d->state === 'on' ? 'off' : 'on',
        }])->all();
        // Everything else the hub knows reports in too (keeps them from showing "No signal").
        $rest = \App\Models\Facility\FacilityDevice::active()->where('source', 'hub')->whereNotIn('id', $devices->pluck('id'))
            ->get()->map(fn ($d) => ['code' => $d->code, 'state' => $d->state])->all();
        $r = $facility->report($hub, array_merge($report, $rest));
        $this->info(now()->format('H:i:s') . " — {$r['changed']} changed, {$r['updated']} reported");
        if ($this->option('loop')) {
            sleep(20);
        }
    } while ($this->option('loop'));
}))->purpose('Demo: flip a few equipment states as hardware would');
