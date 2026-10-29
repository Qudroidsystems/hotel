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
    // These, Twilio, Paystack, mail and the Firebase key can also be entered under Hotel Settings, which wins over .env.
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

    // Real-time updates (guest chat, calls, the dispatch board): a Pusher-protocol server — Laravel Reverb on this
    // server (php artisan reverb:start), or a hosted Pusher-compatible service. Without one, the apps poll.
    'realtime' => [
        'enabled'       => env('REALTIME_ENABLED', false),
        'app_id'        => env('REVERB_APP_ID'),
        'key'           => env('REVERB_APP_KEY'),
        'secret'        => env('REVERB_APP_SECRET'),
        // Where this server sends events (Reverb listening locally).
        'host'          => env('REVERB_SERVER_HOST', '127.0.0.1'),
        'port'          => env('REVERB_SERVER_PORT', 8080),
        'scheme'        => env('REVERB_SERVER_SCHEME', 'http'),
        // Where browsers and the apps connect (through Nginx, on the site's own address).
        'client_host'   => env('REVERB_HOST'),
        'client_port'   => env('REVERB_PORT', 443),
        'client_scheme' => env('REVERB_SCHEME', 'https'),
    ],

    // Voice calls between guests and the hotel (WebRTC): STUN finds the way, TURN relays when phones can't reach
    // each other directly (most mobile networks). coturn on this server with a shared secret, or a paid TURN service.
    'webrtc' => [
        'stun'        => env('WEBRTC_STUN', 'stun:stun.l.google.com:19302'),
        'turn_urls'   => env('WEBRTC_TURN_URLS'),          // "turn:turn.example.com:3478,turns:turn.example.com:5349"
        'turn_secret' => env('WEBRTC_TURN_SECRET'),        // coturn use-auth-secret (time-limited logins)
        'turn_user'   => env('WEBRTC_TURN_USER'),          // or a fixed login (a paid service)
        'turn_pass'   => env('WEBRTC_TURN_PASS'),
    ],

    // Maps & directions (Hotel Settings → Maps & Directions overrides these).
    'google_maps' => [
        'server_key' => env('GOOGLE_MAPS_SERVER_KEY'),
        'map_key'    => env('GOOGLE_MAPS_MAP_KEY'),
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
        'credentials_json' => null,   // the key itself, when pasted under Hotel Settings → Phone Notifications
    ],

];
