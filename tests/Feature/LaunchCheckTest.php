<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the launch check lists what to fix and fails until it is fixed', function () {
    $this->seed(DatabaseSeeder::class);
    config(['app.debug' => true]);
    $this->artisan('hotel:launch-check')
        ->expectsOutputToContain('APP_DEBUG is true')
        ->expectsOutputToContain('still has the starting password')
        ->expectsOutputToContain('No rooms yet')
        ->assertFailed();
});
