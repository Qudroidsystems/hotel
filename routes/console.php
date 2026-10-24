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

Schedule::command('rooms:prepare-arrivals')->dailyAt('00:05');
Schedule::command('folios:night-audit')->dailyAt('02:00');
Schedule::command('security:prune-visitors')->dailyAt('03:00');
