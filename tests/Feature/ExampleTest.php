<?php

test('guests are sent to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

test('the login page loads', function () {
    $this->get('/login')->assertOk();
});
