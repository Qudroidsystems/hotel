<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::role('Super Admin')->first();
});

test('without an uploaded logo, pages show the hotel name, never the template logo', function () {
    config(['hotel.name' => 'B Luxury', 'hotel.logo' => 'theme/layouts/assets/images/logo-dark.png', 'hotel.has_logo' => false]);

    $this->get(route('login'))->assertOk()->assertSee('B Luxury')->assertDontSee('logo-dark.png')
        ->assertSee('Powered by', false)->assertSee('Qudroid Systems');
    $this->actingAs($this->admin)->get('/dashboard')->assertOk()
        ->assertSee('hotel-name-logo', false)->assertDontSee('images/logo-dark.png')->assertDontSee('images/logo-sm.png');
    $this->getJson('/api/v1/app/config')->assertJsonPath('data.logo_url', null)->assertJsonPath('data.name', 'B Luxury');
});

test('an uploaded logo is shown', function () {
    config(['hotel.logo' => 'uploads/branding/logo.png', 'hotel.has_logo' => true]);
    $this->get(route('login'))->assertSee('uploads/branding/logo.png');
});
