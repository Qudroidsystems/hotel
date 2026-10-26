<?php

use App\Models\User;
use App\Services\NightAudit\NightAuditService;
use Database\Seeders\CommercialDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class, CommercialDemoSeeder::class]);
    $this->admin = User::role('Super Admin')->first();
    $this->actingAs($this->admin);
    $this->withoutDefer();
});

test('the sales reports show rates, companies, events and the daily figures, and download as CSV', function () {
    $this->travelTo(today()->setTime(23, 30));
    app(NightAuditService::class)->close(today());
    $range = ['from' => today()->subDays(10)->toDateString(), 'to' => today()->addDays(40)->toDateString()];

    $this->get(route('reports.advanced', ['report' => 'daily'] + $range))->assertOk()->assertSee('Day by day')->assertSee(today()->format('D j M'));
    $this->get(route('reports.advanced', ['report' => 'rates'] + $range))->assertOk()->assertSee('By rate plan')->assertSee('Standard rate');
    $this->get(route('reports.advanced', ['report' => 'companies'] + $range))->assertOk()->assertSee('Shell Nigeria');
    $this->get(route('reports.advanced', ['report' => 'events'] + $range))->assertOk()->assertSee('Grand Ballroom')->assertSee('Okafor–Adeyemi wedding reception');

    foreach (['daily', 'rates', 'companies', 'events'] as $r) {
        $res = $this->get(route('reports.advanced', ['report' => $r, 'export' => 'csv'] + $range));
        $res->assertOk();
        expect($res->streamedContent())->toContain(',');
    }

    $hk = User::create(['name' => 'HK', 'email' => 'hk@test.local', 'password' => bcrypt('Secret@123')]);
    $hk->assignRole('Housekeeping');
    $this->actingAs($hk)->get(route('reports.advanced', ['report' => 'rates']))->assertForbidden();
});
