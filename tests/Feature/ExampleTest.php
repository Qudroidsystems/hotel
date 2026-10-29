<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests see the hotel website at the main address', function () {
    $this->get('/')->assertOk()->assertSee(config('hotel.name'));
});

test('with the website switched off, the main address opens staff sign-in', function () {
    \App\Support\Website::save(['enabled' => false]);
    $this->get('/')->assertRedirect(route('login'));
});

test('the login page loads', function () {
    $this->get('/login')->assertOk();
});
