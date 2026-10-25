<?php

use App\Models\Company;
use App\Models\Events\Event;
use App\Models\Events\EventPackage;
use App\Models\Events\Hall;
use App\Models\User;
use App\Services\Companies\CompanyLedger;
use App\Services\NightAudit\NightAuditService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    $this->withoutDefer();
    config(['hotel.prices_include_tax' => true]);
    $this->hall = Hall::create(['name' => 'Grand Ballroom', 'capacity_theatre' => 300, 'capacity_banquet' => 180, 'hourly_rate' => 50000, 'half_day_rate' => 250000, 'full_day_rate' => 400000]);
    $this->pkg = EventPackage::create(['name' => 'Full-day delegate', 'price_per_person' => 12000, 'description' => 'Tea breaks, lunch']);
    $this->day = today()->addDays(20)->toDateString();
});

function eventForm(array $over = []): array
{
    return $over + [
        'title' => 'Shell annual review', 'event_type' => 'conference', 'status' => 'tentative', 'hall_id' => test()->hall->id,
        'date' => test()->day, 'start_time' => '09:00', 'end_time' => '17:00', 'setup' => 'theatre', 'guests_expected' => 100,
        'organiser_name' => 'Mrs Ade', 'organiser_phone' => '08031234567', 'package_id' => test()->pkg->id, 'hall_rate_type' => 'full_day',
        'deposit_due' => 500000, 'requirements' => 'Projector, 2 microphones',
    ];
}

test('booking an event prices the hall and package, and a held hall can’t be double-booked', function () {
    $this->post(route('events.store'), eventForm())->assertRedirect();
    $e = Event::first();
    expect($e->number)->toBe('EV-000001')->and($e->status)->toBe('tentative')
        ->and((float) $e->hall_charge)->toEqual(400000.0)->and((float) $e->total)->toEqual(1600000.0);

    // Overlapping provisional booking in the same hall is refused; an enquiry is allowed.
    $this->post(route('events.store'), eventForm(['title' => 'Wedding', 'start_time' => '15:00', 'end_time' => '22:00']))->assertSessionHas('error');
    $this->post(route('events.store'), eventForm(['title' => 'Wedding', 'status' => 'enquiry', 'start_time' => '15:00', 'end_time' => '22:00', 'setup' => 'banquet', 'guests_expected' => 150]))->assertRedirect();
    $wedding = Event::where('title', 'Wedding')->first();
    $this->post(route('events.status', $wedding), ['status' => 'confirmed'])->assertSessionHas('error');

    // Capacity per layout.
    $this->post(route('events.store'), eventForm(['title' => 'Too big', 'status' => 'enquiry', 'setup' => 'banquet', 'guests_expected' => 250]))->assertSessionHas('error');

    $this->get(route('events.show', $e))->assertOk()->assertSee('Grand Ballroom')->assertSee('Full-day delegate');
    $this->get(route('events.index'))->assertOk()->assertSee('Shell annual review');
    $this->get(route('events.index', ['view' => 'calendar', 'month' => today()->addDays(20)->format('Y-m')]))->assertOk()->assertSee('Shell annual review');
    $this->get(route('events.sheet', $e))->assertOk()->assertSee('Projector, 2 microphones');
});

test('extras, payments and billing a company settle the event', function () {
    $this->post(route('events.store'), eventForm(['hall_rate_type' => 'custom', 'hall_charge' => 300000, 'package_id' => null, 'guests_expected' => 50]))->assertRedirect();
    $e = Event::first();
    $this->post(route('events.items.store', $e), ['kind' => 'equipment', 'description' => 'Projector', 'quantity' => 1, 'unit_price' => 25000])->assertSessionHas('success');
    expect((float) $e->fresh()->total)->toEqual(325000.0);

    $this->post(route('events.status', $e), ['status' => 'confirmed'])->assertSessionHas('success');
    $this->post(route('events.payments.store', $e), ['kind' => 'payment', 'method' => 'transfer', 'amount' => 100000, 'reference' => 'TRF1'])->assertSessionHas('success');
    $company = app(CompanyLedger::class)->create(['name' => 'Shell', 'payment_terms_days' => 30]);
    $this->post(route('events.to-company', $e), ['company_id' => $company->id, 'amount' => 300000])->assertSessionHas('error');   // more than is left
    $this->post(route('events.to-company', $e), ['company_id' => $company->id, 'amount' => 225000])->assertSessionHas('success');
    expect($e->fresh()->balance())->toEqual(0.0)->and($company->balance())->toEqual(225000.0);

    // Counted in the day's report.
    $this->travelTo(today()->addDays(20)->setTime(23, 0));
    expect(app(NightAuditService::class)->report(today())['revenue']['events'])->toBe(['count' => 1, 'revenue' => 325000.0]);

    $this->post(route('events.status', $e), ['status' => 'cancelled'])->assertSessionHas('error');   // needs a reason
    $this->post(route('events.status', $e), ['status' => 'cancelled', 'reason' => 'Postponed'])->assertSessionHas('success');
});

test('halls and packages are managed by managers; front desk books events', function () {
    $this->get(route('events.halls.index'))->assertOk()->assertSee('Grand Ballroom');
    $this->post(route('events.halls.store'), ['name' => 'Boardroom', 'capacity_boardroom' => 14, 'hourly_rate' => 15000])->assertSessionHas('success');
    $this->put(route('events.packages.update', $this->pkg), ['name' => 'Full-day delegate', 'price_per_person' => 13500, 'is_active' => 1])->assertSessionHas('success');

    $fd = User::create(['name' => 'FD', 'email' => 'fd@test.local', 'password' => bcrypt('Secret@123')]);
    $fd->assignRole('Front Desk');
    $this->actingAs($fd)->get(route('events.halls.index'))->assertForbidden();
    $this->actingAs($fd)->get(route('events.create'))->assertOk();
    $this->actingAs($fd)->post(route('events.store'), eventForm())->assertRedirect();
});
