<?php

return [
    /*
    | Two-step sign-in (spec §18). Anyone can turn it on for their own account
    | under Profile → Two-step sign-in. People holding one of these roles must:
    | after signing in they are sent to set it up before they can do anything else.
    | Comma-separated role names; leave empty to make it optional for everyone.
    */
    'required_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('HOTEL_2FA_ROLES', 'Super Admin,Hotel Owner,General Manager'))))),

    // How long the code step of a sign-in stays open after the password is accepted.
    'challenge_minutes' => 5,

    // One-off codes given at set-up, for when the phone is lost.
    'recovery_codes' => 8,
];
