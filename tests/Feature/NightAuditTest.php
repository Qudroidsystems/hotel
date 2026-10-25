<?php

use App\Enums\ReservationStatus;
use App\Models\FolioCharge;
use App\Models\NightAudit;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\NightAudit\NightAuditService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    $this->withoutDefer();
    $this->travelTo(today()->setTime(23, 30));
});

test('closing the day marks no-shows, bills tonight’s rooms, saves the report and locks the day', function () {
    $date = NightAudit::businessDate();
    expect($date->toDateString())->toBe(today()->toDateString());

    $due = Reservation::whereIn('status', ['pending', 'confirmed'])->where('check_in_date', '<=', today()->toDateString() . ' 23:59:59')->get();
    expect($due)->not->toBeEmpty();
    $keep = $due->first();
    $mark = $due->skip(1)->pluck('id')->all();
    // Guests staying tonight (those leaving today have no night to bill).
    $inHouse = Reservation::where('status', ReservationStatus::CheckedIn->value)->where('check_out_date', '>', today()->toDateString() . ' 23:59:59')->with('rooms')->get()->sum(fn ($r) => $r->rooms->count());

    $this->get(route('night-audit.index'))->assertOk()->assertSee('Arrivals who haven')->assertSee($keep->reference);
    $this->post(route('night-audit.run'), ['date' => $date->toDateString(), 'no_shows' => $mark, 'notes' => 'Quiet night'])->assertRedirect();

    $audit = NightAudit::first();
    expect($audit->business_date->toDateString())->toBe(today()->toDateString())
        ->and($keep->fresh()->status)->not->toBe(ReservationStatus::NoShow)
        ->and(Reservation::whereIn('id', $mark)->where('status', 'no_show')->count())->toBe(count($mark))
        ->and(FolioCharge::where('type', 'room')->whereDate('service_date', today())->count())->toBeGreaterThanOrEqual($inHouse)
        ->and($audit->report['rooms']['sold'])->toBeGreaterThanOrEqual($inHouse)
        ->and($audit->report['rooms']['occupancy'])->toBeGreaterThan(0);
    $this->get(route('night-audit.show', $audit))->assertOk()->assertSee('Occupancy')->assertSee('Quiet night');

    // The next day is tomorrow; today can't be closed twice.
    expect(NightAudit::businessDate()->toDateString())->toBe(today()->addDay()->toDateString());
    expect(fn () => app(NightAuditService::class)->close(today()))->toThrow(\App\Exceptions\BookingException::class);

    // Money dated on a closed day needs a manager to void it.
    $charge = FolioCharge::where('type', 'room')->whereDate('service_date', today())->first();
    $fd = User::create(['name' => 'FD', 'email' => 'fd@test.local', 'password' => bcrypt('Secret@123')]);
    $fd->assignRole('Front Desk');
    $this->actingAs($fd);
    expect(fn () => app(FolioService::class)->voidCharge($charge, 'Oops'))->toThrow(\App\Exceptions\BookingException::class, 'closed');
    $this->actingAs($this->admin);
    app(FolioService::class)->voidCharge($charge, 'Manager correction');
    expect($charge->fresh()->voided_at)->not->toBeNull();
});

test('days close in order; the automatic run reminds staff, closes when switched on, and emails the report', function () {
    Mail::fake();
    $this->travelTo(today()->addDays(2)->setTime(5, 30));   // two days behind (first day = the day before yesterday)
    config(['hotel.night_audit.auto_close' => true, 'hotel.night_audit.auto_close_at' => '05:00', 'hotel.night_audit.day_ends_at' => '05:00', 'hotel.night_audit.report_emails' => ['gm@bluxury.ng']]);
    NightAudit::create(['business_date' => today()->subDays(3)->toDateString(), 'report' => [], 'closed_at' => now()]);

    expect(fn () => app(NightAuditService::class)->close(today()->subDay()))->toThrow(\App\Exceptions\BookingException::class, 'in order');

    $this->artisan('night-audit:auto')->assertSuccessful();
    expect(NightAudit::count())->toBe(3)->and(NightAudit::businessDate()->toDateString())->toBe(today()->toDateString())
        ->and(NightAudit::latest('business_date')->first()->automatic)->toBeTrue();
});

test('only staff who may run it can close the day', function () {
    $hk = User::create(['name' => 'HK', 'email' => 'hk@test.local', 'password' => bcrypt('Secret@123')]);
    $hk->assignRole('Housekeeping');
    $this->actingAs($hk)->get(route('night-audit.index'))->assertForbidden();
    $owner = User::create(['name' => 'Owner', 'email' => 'owner@test.local', 'password' => bcrypt('Secret@123')]);
    $owner->assignRole('Hotel Owner');
    $this->actingAs($owner)->get(route('night-audit.index'))->assertOk()->assertDontSee('Close ' . today()->format('D j M'));
    $this->actingAs($owner)->post(route('night-audit.run'), ['date' => today()->toDateString()])->assertForbidden();
});
