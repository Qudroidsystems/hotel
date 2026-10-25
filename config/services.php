<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    // Shared key the school's public website (separate project) sends as X-Website-Key.
    'website' => [
        'key' => env('WEBSITE_API_KEY'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'twilio' => [
    'sid' => env('TWILIO_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    'phone_number' => env('TWILIO_PHONE_NUMBER'),
    // Guest messages by WhatsApp: the approved WhatsApp sender number, e.g. +2348000000000.
    'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],
    // Termii (termii.com), the other SMS provider (Hotel Settings → Guest Messages → Send through).
    'termii' => [
        'api_key'       => env('TERMII_API_KEY'),
        'secret_key'    => env('TERMII_SECRET_KEY'),      // checks delivery reports (Termii signs them)
        'sender_id'     => env('TERMII_SENDER_ID'),       // approved sender ID, up to 11 characters
        'whatsapp_from' => env('TERMII_WHATSAPP_FROM'),   // WhatsApp device ID from the Termii dashboard
        'base_url'      => env('TERMII_BASE_URL', 'https://api.ng.termii.com'),
    ],
    'device' => [
        'key' => env('DEVICE_API_KEY'),
    ],

    'paystack' => [
        'active'          => env('PAYSTACK_ACTIVE', true),
        'mode'            => env('PAYSTACK_MODE', 'sandbox'),
        'secret_key'      => env('PAYSTACK_SECRET_KEY'),
        'public_key'      => env('PAYSTACK_PUBLIC_KEY'),
        'test_secret_key' => env('PAYSTACK_TEST_SECRET_KEY'),
        'test_public_key' => env('PAYSTACK_TEST_PUBLIC_KEY'),
        'live_secret_key' => env('PAYSTACK_LIVE_SECRET_KEY'),
        'live_public_key' => env('PAYSTACK_LIVE_PUBLIC_KEY'),
    ],

    // Push notifications for the staff mobile app: path to the Firebase service-account JSON key
    // (keep it outside public/, e.g. storage/app/private/firebase.json).
    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS'),
    ],

];
