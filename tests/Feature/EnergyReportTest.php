<?php

use App\Models\Energy\EnergyEvent;
use App\Models\Energy\EnergyFuelDelivery;
use App\Models\Energy\EnergySample;
use App\Models\Energy\EnergySource;
use App\Models\Finance\Expense;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\Energy\EnergyAdvisor;
use App\Services\Energy\EnergyReportService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(today()->setTime(6, 0));
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    Notification::fake();
    $this->withoutDefer();
    $this->gm = User::create(['name' => 'Gbenga GM', 'email' => 'gm@test.local', 'password' => bcrypt('Secret@123'), 'must_change_password' => false]);
    $this->gm->assignRole('General Manager');
    $this->gm->hotels()->sync([\App\Models\Hotel::first()->id]);
    $this->energy = app(EnergyAdvisor::class);
    $this->grid = EnergySource::create(['name' => 'Grid', 'kind' => 'grid', 'running' => true]);
    $this->gen = EnergySource::create(['name' => 'Mikano 100 kVA', 'kind' => 'generator', 'capacity_kw' => 80, 'litres_per_hour' => 20, 'tank_litres' => 600, 'fuel_litres' => 400]);
});

test('a day of grid outages and generator running is sampled and reported: hours, diesel, outages, cost per room-night', function () {
    $this->energy->fuel($this->gen, 400, $this->gm);
    // 06:00 – 10:00 on grid, 10:00 – 16:00 grid off with the generator on, then grid back.
    for ($t = today()->setTime(6, 0); $t->lt(today()->setTime(18, 0)); $t->addMinutes(15)) {
        $this->travelTo($t->copy());
        if ($t->format('H:i') === '10:00') {
            $this->energy->setGrid(false, $this->gm);
            $this->energy->setRunning($this->gen, true, $this->gm);
        }
        if ($t->format('H:i') === '16:00') {
            $this->energy->setRunning($this->gen, false, $this->gm);
            $this->energy->setGrid(true, $this->gm);
        }
        $this->energy->sync(alert: false);
    }
    expect(EnergySample::count())->toBe(48);

    $r = app(EnergyReportService::class)->build(today(), today());
    $g = $r['generators']->firstWhere('source.id', $this->gen->id);
    expect($r['grid']['outages'])->toBe(1)->and($r['grid']['off_hours'])->toEqual(6.0)
        ->and($g['hours'])->toEqual(6.0)->and($g['starts'])->toBe(1)
        ->and($g['expected_litres'])->toBeGreaterThan(0)->and($r['kwh'])->toBeGreaterThan(0)
        ->and($r['kwh_gen'])->toBeGreaterThan(0)->and($r['kwh_grid'])->toBeGreaterThan(0)
        ->and($r['room_nights'])->toBeGreaterThan(0)->and($r['per_room_night'])->toBeGreaterThan(0);

    // The tank read at 18:00: about what the generator should have burned → no alarm.
    $this->travelTo(today()->setTime(18, 0));
    $check = $this->energy->fuel($this->gen, round(400 - $g['expected_litres'] - 5, 1), $this->gm);
    expect($check['flagged'])->toBeFalse();

    $this->actingAs($this->gm)->get(route('energy.report', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))->assertOk()->assertSee('Diesel check')->assertSee('Mikano 100 kVA');
    $csv = $this->actingAs($this->gm)->get(route('energy.report', ['csv' => 1]))->assertOk()->getContent();
    expect($csv)->toContain('Diesel burned');
});

test('a delivery fills the tank and goes into Expenses; more diesel gone than burned alerts the control room', function () {
    $this->energy->fuel($this->gen, 100, $this->gm);
    $this->actingAs($this->gm)->post(route('energy.fuel.store'), [
        'source_id' => $this->gen->id, 'litres' => 400, 'price_per_litre' => 1200, 'supplier_name' => 'Total filling station', 'expense' => 1,
    ])->assertSessionHas('success');
    $d = EnergyFuelDelivery::firstOrFail();
    expect($this->gen->fresh()->fuel_litres)->toEqual(500.0)->and($d->total)->toEqual(480000.0)->and($d->expense_id)->not->toBeNull();
    $e = Expense::find($d->expense_id);
    expect((float) $e->amount)->toEqual(480000.0)->and($e->category->name)->toContain('Diesel')->and($e->payee)->toBe('Total filling station');

    // Ten hours later, nothing ran, but the tank reads 380 L: 120 L gone with none burned … nothing expected, so
    // no comparison; with the generator having run a little, a big gap is flagged.
    $this->travelTo(now()->addHours(2));
    $this->energy->setGrid(false, $this->gm);
    $this->energy->setRunning($this->gen, true, $this->gm);
    for ($i = 0; $i < 8; $i++) {
        $this->travelTo(now()->addMinutes(15));
        $this->energy->sync(alert: false);
    }
    $this->travelTo(now()->addHours(6));
    Notification::fake();
    $sec = User::create(['name' => 'Sule Control', 'email' => 'sule.c@test.local', 'password' => bcrypt('Secret@123'), 'must_change_password' => false]);
    $sec->assignRole('Security Officer');
    $sec->hotels()->sync([\App\Models\Hotel::first()->id]);
    $check = $this->energy->fuel($this->gen, 380, $this->gm);
    expect($check['flagged'])->toBeTrue()->and($check['delivered'])->toEqual(400.0)->and($check['used'])->toEqual(120.0);
    // The control room hears (not the person who read the tank: they see it on screen).
    Notification::assertSentTo($sec, StaffAlert::class, fn ($n) => str_contains(json_encode($n->toArray($sec)), 'more gone than burned'));

    $this->actingAs($this->gm)->get(route('energy.fuel.index'))->assertOk()->assertSee('Total filling station')->assertSee($e->number);
});

test('the staff app records a delivery; only managers can', function () {
    $sec = User::create(['name' => 'Sule', 'email' => 'sule@test.local', 'password' => bcrypt('Secret@123'), 'must_change_password' => false]);
    $sec->assignRole('Security Officer');
    \Laravel\Sanctum\Sanctum::actingAs($sec, ['*']);
    $this->postJson("/api/v1/energy/sources/{$this->gen->id}/delivery", ['litres' => 200])->assertForbidden();
    \Laravel\Sanctum\Sanctum::actingAs($this->gm, ['*']);
    $this->postJson("/api/v1/energy/sources/{$this->gen->id}/delivery", ['litres' => 200, 'price_per_litre' => 1100, 'level_after' => 590])->assertOk();
    expect($this->gen->fresh()->fuel_litres)->toEqual(590.0)->and(EnergyEvent::where('kind', 'delivery')->count())->toBe(1);
});
