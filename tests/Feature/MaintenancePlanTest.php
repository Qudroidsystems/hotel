<?php

use App\Models\MaintenanceOrder;
use App\Models\MaintenancePlan;
use App\Models\Room;
use App\Models\User;
use App\Services\Operations\MaintenancePlanService;
use App\Services\Operations\MaintenanceService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    $make = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->gm = $make('Grace Manager', 'General Manager');
    $this->tech = $make('Tunde Tech', 'Maintenance Technician');
    $this->cleaner = $make('Bisi Cleaner', 'Housekeeping');
});

afterEach(fn () => Carbon::setTestNow());

$plan = fn (array $over = []) => $over + [
    'title' => 'Generator oil and filter change', 'category' => 'electrical', 'scope' => 'one', 'location' => 'Generator house',
    'every' => 1, 'unit' => 'month', 'next_due_on' => today()->addDays(10)->toDateString(), 'lead_days' => 3, 'priority' => 'high',
];

test('a manager adds a plan; technicians can see it; others cannot', function () use ($plan) {
    $this->actingAs($this->gm)->post(route('maintenance.plans.store'), $plan(['assigned_to' => $this->tech->id]))->assertSessionHas('success');
    $p = MaintenancePlan::sole();
    expect($p->interval_label)->toBe('Every month')->and($p->where)->toBe('Generator house')->and($p->opensOn()->toDateString())->toBe(today()->addDays(7)->toDateString());

    $this->actingAs($this->tech)->get(route('maintenance.plans.index'))->assertOk()->assertSee('Generator oil and filter change')->assertDontSee('Add a servicing plan');
    $this->actingAs($this->tech)->post(route('maintenance.plans.store'), $plan())->assertForbidden();
    $this->actingAs($this->cleaner)->get(route('maintenance.plans.index'))->assertForbidden();

    $this->actingAs($this->gm)->post(route('maintenance.plans.store'), $plan(['location' => null]))->assertSessionHas('error');
    $this->actingAs($this->gm)->get(route('maintenance.plans.index', ['edit' => $p->id]))->assertOk()->assertSee('Change “Generator oil and filter change”');
    $this->actingAs($this->gm)->put(route('maintenance.plans.update', $p), $plan(['every' => 3]))->assertRedirect(route('maintenance.plans.index'));
    expect($p->fresh()->interval_label)->toBe('Every 3 months');
});

test('the job opens a few days early, once; finishing it sets the next due date from the day it was done', function () use ($plan) {
    $this->actingAs($this->gm)->post(route('maintenance.plans.store'), $plan(['assigned_to' => $this->tech->id]));
    $p = MaintenancePlan::sole();
    $svc = app(MaintenancePlanService::class);

    expect($svc->openDue())->toBe(0);                                  // 10 days away, opens 3 days before
    expect($svc->openDue(today()->addDays(7)))->toBe(1)->and($svc->openDue(today()->addDays(8)))->toBe(0);   // only once

    $order = MaintenanceOrder::sole();
    expect($order->maintenance_plan_id)->toBe($p->id)->and($order->assigned_to)->toBe($this->tech->id)->and($order->priority)->toBe('high')
        ->and($order->description)->toContain('Planned servicing')->and($order->due_at->toDateString())->toBe(today()->addDays(10)->toDateString());

    Carbon::setTestNow(now()->addDays(12));   // done two days late
    app(MaintenanceService::class)->complete($order->fresh(), $this->tech, 'Oil and filter changed', 18000);
    $p->refresh();
    expect($p->last_done_on->toDateString())->toBe(today()->toDateString())
        ->and($p->next_due_on->toDateString())->toBe(today()->addMonthNoOverflow()->toDateString());
});

test('a plan for every room opens one job per room; the next date waits for the last one', function () use ($plan) {
    $this->actingAs($this->gm)->post(route('maintenance.plans.store'), $plan(['title' => 'Service AC unit', 'category' => 'air_conditioning', 'scope' => 'rooms', 'location' => null, 'every' => 3]));
    $p = MaintenancePlan::sole();
    $rooms = Room::active()->count();

    $this->actingAs($this->gm)->post(route('maintenance.plans.now', $p))->assertSessionHas('success');
    expect(MaintenanceOrder::where('maintenance_plan_id', $p->id)->count())->toBe($rooms)
        ->and(MaintenanceOrder::where('maintenance_plan_id', $p->id)->whereNull('room_id')->count())->toBe(0);
    $this->actingAs($this->gm)->post(route('maintenance.plans.now', $p))->assertSessionHas('error');

    $orders = MaintenanceOrder::where('maintenance_plan_id', $p->id)->get();
    $first = $orders->shift();
    app(MaintenanceService::class)->complete($first, $this->tech, 'Cleaned filters', null);
    expect($p->fresh()->next_due_on->toDateString())->toBe(today()->addDays(10)->toDateString());   // others still open

    $orders->each(fn ($o) => app(MaintenanceService::class)->cancel($o, 'Room out of order'));
    expect($p->fresh()->next_due_on->toDateString())->toBe(today()->addMonthsNoOverflow(3)->toDateString());
});

test('a paused plan opens nothing; the hourly command runs', function () use ($plan) {
    $this->actingAs($this->gm)->post(route('maintenance.plans.store'), $plan(['next_due_on' => today()->toDateString()]));
    $p = MaintenancePlan::sole();
    $this->actingAs($this->gm)->post(route('maintenance.plans.toggle', $p));
    expect($p->fresh()->active)->toBeFalse()->and(app(MaintenancePlanService::class)->openDue())->toBe(0);

    $this->actingAs($this->gm)->post(route('maintenance.plans.toggle', $p));
    $this->artisan('maintenance:plans')->assertSuccessful();
    expect(MaintenanceOrder::where('maintenance_plan_id', $p->id)->count())->toBe(1);
    $this->actingAs($this->gm)->get(route('maintenance.plans.index'))->assertOk()->assertSee(MaintenanceOrder::sole()->number);

    $this->actingAs($this->gm)->delete(route('maintenance.plans.destroy', $p))->assertRedirect();
    expect(MaintenancePlan::count())->toBe(0)->and(MaintenanceOrder::sole()->maintenance_plan_id)->toBeNull();
});
