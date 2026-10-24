<?php

use App\Enums\ChargeType;
use App\Models\Folio;
use App\Models\FolioCharge;
use App\Models\Room;
use App\Models\User;
use App\Services\Reports\ManagementReportService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PosDemoSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class, PosDemoSeeder::class]);
    auth()->logout();
});

test('occupancy, ADR and RevPAR come from posted room nights', function () {
    $from = today()->subDays(6);
    $to = today();
    $r = app(ManagementReportService::class)->build($from, $to);

    $nights = FolioCharge::whereNull('voided_at')->where('type', ChargeType::Room->value)
        ->whereBetween('service_date', [$from->toDateString(), $to->toDateString()])->get();
    $available = Room::active()->count() * 7;

    expect($r['kpi']['sold'])->toBe($nights->count())
        ->and($r['kpi']['available'])->toBe($available)
        ->and($r['kpi']['room_revenue'])->toEqualWithDelta((float) $nights->sum('total'), 0.01)
        ->and($r['kpi']['adr'])->toEqualWithDelta($nights->count() ? $nights->sum('total') / $nights->count() : 0, 0.01)
        ->and($r['kpi']['revpar'])->toEqualWithDelta($nights->sum('total') / $available, 0.01)
        ->and($r['daily'])->toHaveCount(7)
        ->and(collect($r['daily'])->sum('sold'))->toBe($nights->count());
});

test('bar sales charged to rooms are not counted twice', function () {
    $r = app(ManagementReportService::class)->build(today()->subDays(30), today());

    $posOnBills = FolioCharge::whereNull('voided_at')->where('source_type', App\Models\Pos\PosOrder::class)->sum('total');
    $billRevenue = FolioCharge::whereNull('voided_at')->whereBetween('service_date', [today()->subDays(30)->toDateString(), today()->toDateString()])->sum('total');

    expect($r['departments']->sum('amount'))->toEqualWithDelta($billRevenue - $posOnBills, 0.01)
        ->and($r['kpi']['revenue'])->toEqualWithDelta($r['departments']->sum('amount') + $r['outlets']->sum('amount'), 0.01);
});

test('managers can open the report and export CSV; front desk cannot', function () {
    $gm = User::create(['name' => 'GM', 'email' => 'gm@test.local', 'password' => bcrypt('Secret@123')]);
    $gm->assignRole('General Manager');

    $this->actingAs($gm)->get(route('reports.index'))->assertOk()->assertSee('RevPAR')->assertSee('Revenue by department');
    $this->get(route('reports.index', ['period' => 'last_month']))->assertOk();
    $csv = $this->get(route('reports.index', ['export' => 'daily']))->assertOk()->streamedContent();
    expect($csv)->toContain('Rooms sold');

    $fd = User::create(['name' => 'FD', 'email' => 'fd@test.local', 'password' => bcrypt('Secret@123')]);
    $fd->assignRole('Front Desk');
    $this->actingAs($fd)->get(route('reports.index'))->assertForbidden();
});
