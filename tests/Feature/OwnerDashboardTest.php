<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\MultiHotelDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DemoSeeder::class);
    $this->owner = User::role('Super Admin')->first();
    $this->owner->forceFill(['must_change_password' => false])->save();
});

test('the owner sees 14 days against the 14 before, the month, the week ahead and alerts; others do not', function () {
    $d = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/owner')->assertOk()->json('data');
    expect($d['scope'])->toBe('hotel')
        ->and(collect($d['headline'])->pluck('key')->all())->toBe(['revenue', 'occupancy', 'adr', 'revpar', 'collected', 'owed'])
        ->and($d['daily'])->toHaveCount(14)
        ->and($d['ahead'])->toHaveCount(7)
        ->and(collect($d['daily'])->sum('revenue'))->toBeGreaterThan(0);
    expect($this->getJson('/api/v1/auth/me')->json('data.modules'))->toContain('owner');

    $desk = User::factory()->create(['must_change_password' => false]);
    $desk->assignRole('Front Desk');
    $this->actingAs($desk, 'sanctum')->getJson('/api/v1/owner')->assertForbidden();
});

test('with several hotels the owner can see the whole group', function () {
    $this->seed(MultiHotelDemoSeeder::class);
    \App\Support\HotelSettings::apply();
    $d = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/owner?scope=group')->assertOk()->json('data');
    expect($d['scope'])->toBe('group')->and($d['can_group'])->toBeTrue()
        ->and(collect($d['hotels'])->pluck('name'))->toContain('B Luxury Abuja')
        ->and(count($d['hotels']))->toBe(2);
});
