<?php

use App\Services\Reservations\ReservationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Rooms assigned to today's arrivals show as Reserved on the room board.
// Needs the scheduler: `* * * * * php /path/to/hotel/artisan schedule:run`
Artisan::command('rooms:prepare-arrivals', function (ReservationService $service) {
    $this->info($service->prepareTodaysArrivals() . " room(s) marked Reserved for today's arrivals.");
})->purpose("Mark rooms assigned to today's arrivals as Reserved");

// Night audit: post last night's room charge to every in-house guest's bill.
Artisan::command('folios:night-audit', function (\App\Services\Billing\FolioService $folios) {
    $this->info($folios->nightAudit() . ' room night(s) posted.');
})->purpose("Post room charges for nights already stayed by in-house guests");

// Visitor privacy: clear phone, ID number and plate from old visits (spec §10.1).
Artisan::command('security:prune-visitors', function (\App\Services\Security\SecurityService $security) {
    $this->info($security->pruneVisitors() . ' old visitor record(s) anonymised.');
})->purpose('Clear personal details from visitor records older than the retention period');

// IT control room (spec §11): run the monitors that are due, escalate overdue
// tickets, and trim old check history.
Artisan::command('it:monitor {--all : Check every monitor now, not only the ones due}', function (\App\Services\It\MonitorService $monitors) {
    $this->info($monitors->runDue((bool) $this->option('all')) . ' monitor(s) checked.');
})->purpose('Run the IT control room checks that are due');

Artisan::command('it:escalate', function (\App\Services\It\ServiceDesk $desk) {
    $this->info($desk->escalateOverdue() . ' overdue IT ticket(s) escalated.');
})->purpose('Escalate IT tickets that missed their response or fix target');

Artisan::command('it:prune', function (\App\Services\It\MonitorService $monitors) {
    $this->info($monitors->prune() . ' old check result(s) removed.');
})->purpose('Remove IT check history older than the retention period');

// Online bookings waiting for a deposit are released when their hold runs out.
Artisan::command('bookings:expire-holds', function (\App\Services\Booking\OnlineBookingService $booking) {
    $this->info($booking->expireHolds() . ' unpaid online booking(s) released.');
})->purpose('Cancel online bookings whose deposit was not paid in time');

Schedule::command('bookings:expire-holds')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('it:monitor')->everyMinute()->withoutOverlapping(10);
Schedule::command('it:escalate')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('it:prune')->dailyAt('03:30');
Schedule::command('rooms:prepare-arrivals')->dailyAt('00:05');
Schedule::command('folios:night-audit')->dailyAt('02:00');
Schedule::command('security:prune-visitors')->dailyAt('03:00');
