<?php

/*
|--------------------------------------------------------------------------
| Hotel settings — DEFAULTS
|--------------------------------------------------------------------------
| These values (and the HOTEL_* entries in .env) are the defaults. An
| administrator can change most of them under Settings → Hotel Settings;
| saved values are stored in the hotel_settings table and override what is
| here (see App\Support\HotelSettings). Logo paths are relative to /public.
*/

return [
    'name'       => env('HOTEL_NAME', env('APP_NAME', 'Smart Hotel')),
    'tagline'    => env('HOTEL_TAGLINE', 'Hotel Management & Security Platform'),
    'logo'       => env('HOTEL_LOGO', 'theme/layouts/assets/images/logo-dark.png'),
    'logo_light' => env('HOTEL_LOGO_LIGHT', 'theme/layouts/assets/images/logo-light.png'),
    'favicon'    => env('HOTEL_FAVICON', 'theme/layouts/assets/images/favicon.ico'),
    'vendor'     => 'Qudroid Systems',
    'vendor_url' => 'https://qudroids.com',

    // Currency used for room rates and charges.
    'currency'        => env('HOTEL_CURRENCY', 'NGN'),
    'currency_symbol' => env('HOTEL_CURRENCY_SYMBOL', '₦'),

    /*
    | Taxes & service charge on guest bills. Rates are percentages; set any to 0
    | to switch it off. When prices_include_tax is true, rates/prices already
    | include tax and the invoice just shows the tax portion. When false, tax is
    | added on top of each charge.
    */
    'prices_include_tax' => (bool) env('HOTEL_PRICES_INCLUDE_TAX', true),
    'tax_rates' => [
        'vat'            => (float) env('HOTEL_VAT_RATE', 7.5),
        'service_charge' => (float) env('HOTEL_SERVICE_CHARGE_RATE', 0),
        'consumption'    => (float) env('HOTEL_CONSUMPTION_TAX_RATE', 0),
    ],
    // Built from tax_rates (rates of 0 are left out). Rebuilt by
    // App\Support\HotelSettings when the rates are changed on the settings page.
    'taxes' => array_values(array_filter([
        ['name' => 'VAT',             'rate' => (float) env('HOTEL_VAT_RATE', 7.5)],
        ['name' => 'Service charge',  'rate' => (float) env('HOTEL_SERVICE_CHARGE_RATE', 0)],
        ['name' => 'Consumption tax', 'rate' => (float) env('HOTEL_CONSUMPTION_TAX_RATE', 0)],
    ], fn ($t) => $t['rate'] > 0)),

    // Amenities offered on room types (ticked in the room type form).
    'amenities' => [
        'Air conditioning', 'Wi-Fi', 'Smart TV', 'DSTV / cable', 'Mini fridge', 'Minibar',
        'Electric kettle', 'Work desk', 'Wardrobe', 'In-room safe', 'Hair dryer', 'Iron & board',
        'Bathtub', 'Walk-in shower', 'Toiletries', 'Bathrobe & slippers', 'Balcony',
        'Sitting area', 'Kitchenette', 'Intercom', '24h power backup', 'Room service',
    ],

    /*
    | Audit trail. Voids, refunds and discounts at or above this amount are
    | flagged for a manager's review. Failed sign-ins / QR scans from the same
    | person or device this many times in 15 minutes are flagged too.
    */
    /*
    | Housekeeping. When require_inspection is true, a cleaned room only becomes
    | available after a supervisor inspects it (spec §3.3).
    */
    'housekeeping' => [
        'require_inspection' => (bool) env('HOTEL_HOUSEKEEPING_INSPECTION', true),
    ],

    /*
    | Cashier shifts. A count that differs from the expected cash by more than
    | this needs an explanation at close and is flagged on the audit trail.
    | Stations are suggestions for the "where are you working" box.
    */
    'cash' => [
        'variance_tolerance' => (float) env('HOTEL_CASH_VARIANCE_TOLERANCE', 100),
        'stations'           => ['Front desk', 'Main bar', 'Restaurant', 'Pool bar', 'Room service'],
        'denominations'      => [1000, 500, 200, 100, 50, 20, 10, 5],   // notes counted at close (optional)
    ],

    /*
    | Procurement (spec §8.3). Purchase orders worth more than approval_limit
    | need approval by someone other than the person who raised them (0 means
    | every order). A price more than price_jump_percent above the last price
    | paid is highlighted. invoice_tolerance is how far an invoice may differ
    | from the value of goods received before it is flagged.
    */
    'procurement' => [
        'approval_limit'     => (float) env('HOTEL_PO_APPROVAL_LIMIT', 100000),
        'price_jump_percent' => (float) env('HOTEL_PRICE_JUMP_PERCENT', 15),
        'invoice_tolerance'  => (float) env('HOTEL_INVOICE_TOLERANCE', 500),
    ],

    /*
    | Security (spec §10). Visitor passes expire after pass_hours unless the
    | desk sets another time. Personal details (phone, ID number, plate) of
    | visitors who left more than retention_days ago are cleared nightly.
    */
    'security' => [
        'pass_hours'     => (int) env('HOTEL_VISITOR_PASS_HOURS', 4),
        'retention_days' => (int) env('HOTEL_VISITOR_RETENTION_DAYS', 180),
        'lost_item_days' => (int) env('HOTEL_LOST_ITEM_DAYS', 90),   // unclaimed after this → can be disposed
    ],

    /*
    | Stock analysis (advanced inventory reports): average usage is taken over
    | usage_days; suggested orders cover cover_days of usage; an item with no
    | use for slow_moving_days is called slow-moving.
    */
    'inventory' => [
        'usage_days'       => (int) env('HOTEL_STOCK_USAGE_DAYS', 30),
        'cover_days'       => (int) env('HOTEL_STOCK_COVER_DAYS', 14),
        'slow_moving_days' => (int) env('HOTEL_STOCK_SLOW_DAYS', 60),
    ],

    'audit' => [
        'flag_amount'   => (float) env('HOTEL_AUDIT_FLAG_AMOUNT', 50000),
        'flag_failures' => (int) env('HOTEL_AUDIT_FLAG_FAILURES', 5),
    ],
];
