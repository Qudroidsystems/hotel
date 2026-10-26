<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the privacy policy and booking terms are public', function () {
    $this->seed(DatabaseSeeder::class);
    $this->get('/privacy')->assertOk()->assertSee('Privacy policy')->assertSee('Paystack')->assertSee('Nigeria Data Protection Act');
    $this->get('/terms')->assertOk()->assertSee('Booking terms')->assertSee('hours before check-in');
    $this->get('/book')->assertSee(route('legal.privacy'), false);
});
