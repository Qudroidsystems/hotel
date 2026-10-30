<?php

use App\Services\Reports\ReportEmails;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
});

test('the weekly and monthly periods are the last full week and month', function () {
    $svc = app(ReportEmails::class);
    [$from, $to] = $svc->period('week', Carbon::parse('2026-11-02'));   // a Monday
    expect($from->toDateString())->toBe('2026-10-26')->and($to->toDateString())->toBe('2026-11-01');
    [$from, $to] = $svc->period('month', Carbon::parse('2026-11-01'));
    expect($from->toDateString())->toBe('2026-10-01')->and($to->toDateString())->toBe('2026-10-31');
});

test('the report goes to the addresses in the settings, and to nobody when there are none', function () {
    $svc = app(ReportEmails::class);
    expect($svc->send('week'))->toBe(0);

    config(['hotel.reports.weekly_emails' => ['owner@hotel.test', 'gm@hotel.test']]);
    expect($svc->send('week'))->toBe(2);
    $this->artisan('reports:email month')->assertSuccessful()->expectsOutputToContain('Nobody');

    $html = view('reports.email', $svc->build('month'))->render();
    expect($html)->toContain('Monthly report')->toContain('Occupancy')->toContain('Guest feedback')->toContain('Servicing plans overdue');
});
