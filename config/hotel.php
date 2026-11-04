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
    'name'       => env('HOTEL_NAME', env('APP_NAME', 'B Luxury')),

    // One hotel, or a group of hotels run from this system (Settings → Hotels).
    'multi_hotel' => (bool) env('HOTEL_MULTI', false),
    'group_name'  => env('HOTEL_GROUP_NAME'),   // the group booking site's name
    'commission_percent' => 0,                  // the group's usual commission on group-site bookings
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
    | Guest feedback after check-out (Front Desk → Guest Feedback). The survey
    | link goes out invite_after_hours after check-out and stays open for
    | window_days. A score at or below alert_below (of 5) alerts the managers.
    | Happy guests (4–5 stars) are offered review_url (Google, TripAdvisor…).
    */
    'feedback' => [
        'enabled'            => true,
        'invite'             => true,
        'invite_after_hours' => 3,
        'window_days'        => 30,
        'alert_below'        => 2,
        'review_url'         => env('HOTEL_REVIEW_URL'),
    ],

    /*
    | Report e-mails: the management report for last week (Mondays) and last
    | month (on the 1st), sent at send_at. The daily figures go out with the
    | night audit (night_audit.report_emails).
    */
    'reports' => [
        'weekly_emails'  => [],
        'monthly_emails' => [],
        'send_at'        => '07:00',
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

    /*
    | Online booking & guest portal (spec §4, §5, §12.2). deposit_percent of the
    | stay is asked for online (0 = pay at the hotel). Bookings waiting for a
    | deposit are released after hold_hours. Guests can cancel online up to
    | cancel_hours before the check-in time.
    */
    // The guest app: new guests may make an account with their e-mail before their first booking.
    'guest_app' => [
        'open_sign_up' => env('HOTEL_GUEST_OPEN_SIGN_UP', true),
    ],

    'booking' => [
        'enabled'         => (bool) env('HOTEL_BOOKING_ENABLED', true),
        'welcome'         => null,      // one line under the hotel name on the booking page
        'deposit_percent' => (float) env('HOTEL_BOOKING_DEPOSIT_PERCENT', 30),
        'hold_hours'      => (int) env('HOTEL_BOOKING_HOLD_HOURS', 24),
        'cancel_hours'    => (int) env('HOTEL_BOOKING_CANCEL_HOURS', 48),
        'max_nights'      => 30,
        'max_rooms'       => 4,
        'advance_days'    => 365,
        'check_in_time'   => '14:00',
        'check_out_time'  => '12:00',
        'contact_phone'   => env('HOTEL_PHONE'),
        'contact_email'   => env('HOTEL_EMAIL', env('MAIL_FROM_ADDRESS')),
        'address'         => env('HOTEL_ADDRESS'),
        'policies'        => [
            'Check-in from 2:00 PM, check-out by 12:00 noon.',
            'A valid photo ID is required at check-in.',
            'Smoking is not allowed in rooms.',
        ],
        'guest_requests'  => true,
        'guest_ordering'  => true,
        'arrival_sharing' => true,
        'weather'         => true,   // the weather forecast on the booking page and in the guest app
        'weather_fahrenheit' => (bool) env('HOTEL_WEATHER_FAHRENHEIT', false),   // °F and mph instead of °C and km/h
        'guest_chat'      => true,   // guests chat with the hotel's sections (Guest Chat → Sections)
        'luggage_list'    => true,      // guests can keep a list of what they bring, ticked off when they pack to leave
        'luggage_reminders' => true,    // and are reminded on check-out day (text and e-mail) when items aren't ticked off
        'luggage_reminder_time' => '07:30',
        'guest_calls'     => true,   // and call them in the app (needs a TURN relay across mobile networks)   // guests on the way can share their arrival (needs the hotel's map position)   // room orders from the guest app / room QR card (outlets choose in Menu & Prices)
    ],

    /*
    | Text messages to guests (SMS or WhatsApp through Twilio or Termii). The
    | account itself is in .env (TWILIO_SID, TWILIO_AUTH_TOKEN,
    | TWILIO_PHONE_NUMBER, TWILIO_WHATSAPP_FROM, or TERMII_API_KEY,
    | TERMII_SECRET_KEY, TERMII_SENDER_ID, TERMII_WHATSAPP_FROM). Templates may use
    | {first_name} {guest} {hotel} {reference} {check_in} {check_out} {nights}
    | {link} {amount} {points} {phone}.
    */
    'messaging' => [
        'enabled'        => (bool) env('HOTEL_MESSAGING_ENABLED', false),
        'provider'       => env('HOTEL_MESSAGING_PROVIDER', 'twilio'), // twilio | termii
        'channel'        => env('HOTEL_MESSAGING_CHANNEL', 'sms'),   // sms | whatsapp
        'termii_route'   => 'dnd',      // Termii SMS route: dnd (reaches DND numbers, transactional) | generic
        'country_code'   => '234',      // added to numbers written like 0803…
        'sender_name'    => null,       // SMS sender ID (letters), where the network allows it
        'reminder_time'  => '10:00',
        'send_confirmation' => true,
        'send_payment_link' => true,
        'send_reminder'     => true,
        'send_thank_you'    => true,
        'send_request_updates' => false,
        'send_luggage_reminder' => true,
        'send_feedback_request' => true,
        'templates' => [
            'booking_confirmed' => 'Hi {first_name}, your stay at {hotel} is confirmed: {check_in} to {check_out}, ref {reference}. Your booking page: {link}',
            'payment_link'      => 'Hi {first_name}, we are holding your room at {hotel} ({check_in}, ref {reference}). Pay the {amount} deposit to confirm: {link}',
            'arrival_reminder'  => 'Hi {first_name}, we look forward to welcoming you to {hotel} tomorrow. Check-in from {check_in_time}. Questions? Call {phone}. Ref {reference}.',
            'thank_you'         => 'Thank you for staying at {hotel}, {first_name}. You have {points} loyalty points. We hope to see you again soon.',
            'request_update'    => '{hotel}: {request} — {status}. {note}',
            'luggage_reminder'  => 'Hi {first_name}, you check out of {hotel} today by {check_out_time}. Before you go, check your luggage list ({items} item(s) not ticked off yet) so nothing is left behind: {link}',
            'feedback_request'  => 'Thank you for staying at {hotel}, {first_name}. How was it? Tell us in a minute: {survey}',
        ],
    ],

    /*
    | Guest loyalty: points earned on every settled stay (on the bill before
    | tax, including bar and restaurant charges to the room), spent on a later
    | bill, and tiers from nights stayed in the last 12 months. Members of a
    | tier get its discount on room rates booked online.
    */
    'maps' => [
        'provider' => env('MAPS_PROVIDER', 'osm'),   // osm or google
    ],

    /*
    | Energy Saver: what a guest room draws, how early to cool a room before a guest arrives, and the prices used
    | to show what switching off saves (Hotel Settings → Energy Saver).
    */
    'energy' => [
        'enabled'         => env('ENERGY_SAVER', true),
        'room_watts'      => 1800,    // AC, lights, TV, fridge, water heater (average while someone is in)
        'standby_watts'   => 120,     // a room switched off at the key-card switch: fridge and sockets only
        'precool_minutes' => 40,      // switch a room on this long before its guest is expected
        'hall_setup_hours' => 2,      // halls: on this long before an event for setting up, and an hour after
        'headroom'        => 80,      // run a generator at no more than this % of what it can give
        'grid_price'      => 225,     // per kWh
        'diesel_price'    => 1250,    // per litre
        'alerts'          => true,    // tell the control room about new suggestions
        'empty_minutes'   => 30,      // a room sensor says "empty" this long: suggest eco mode for an in-house room
    ],

    /*
    | Payroll (Hotel Settings → Payroll). Tax bands are the Nigeria Tax Act 2025 personal income tax bands (from
    | January 2026): each "width:rate%", the last "*" is everything above. Check them with your tax adviser.
    */
    'payroll' => [
        'tax_bands'           => '800000:0, 2200000:15, 9000000:18, 13000000:21, 25000000:23, *:25',
        'rent_relief_percent' => 20,
        'rent_relief_cap'     => 500000,
        'pension_employee'    => 8,
        'pension_employer'    => 10,
        'nhf'                 => 2.5,     // of basic salary
        'deduct_absence'      => true,    // monthly staff lose a day's pay for each day absent
        'deduct_unpaid_leave' => true,
        'late_fine'           => 0,       // taken off for each late day (0 = none)
        'pay_overtime'        => false,
        'overtime_multiplier' => 1.5,
        'hours_per_day'       => 8,
    ],

    /*
    | Spa, gym & pool bookings (Hotel Settings → Spa, Gym & Pool). Places, treatments and therapists are on the Spa, Gym & Pool page.
    */
    'wellness' => [
        'enabled'        => true,
        'step_minutes'   => 30,     // treatments start every half hour
        'lead_minutes'   => 30,     // earliest booking from now (guests and visitors)
        'days_ahead'     => 30,
        'cancel_hours'   => 2,      // guests cancel online up to this long before
        'max_per_stay'   => 6,      // upcoming bookings one stay can hold
        'pay_on_arrival' => true,   // visitors can book without paying online when Paystack is off
        'alerts'         => true,   // tell staff about bookings from the apps and the website
    ],

    /*
    | Guest laundry (Hotel Settings → Laundry). The price list itself is on the Laundry page.
    */
    'laundry' => [
        'enabled'          => true,
        'collection_hours' => '07:00-19:00',   // when bags are collected from rooms; empty = any time
        'standard_hours'   => 24,              // back this many hours after collection
        'express_hours'    => 4,
        'express_percent'  => 50,              // express costs this much more
        'guest_note'       => 'Leave your laundry bag on the door handle or with the room attendant. Please empty all pockets.',
    ],

    'transport' => [
        'enabled'            => true,   // guests can book the hotel's cars (once there is a driver)
        'base_fare'          => 2000,
        'per_km'             => 400,
        'minimum_fare'       => 3000,
        'round_to'           => 500,
        'min_notice_minutes' => 30,     // a booked time sooner than this counts as "as soon as possible"
        'max_km'             => 150,
    ],

    'revenue' => [
        'high_occupancy' => 80, 'high_change' => 15,
        'mid_occupancy'  => 60, 'mid_change'  => 5,
        'low_days'       => 7,  'low_occupancy' => 35, 'low_change' => 10,
        'max_change'     => 25,
        'round_to'       => 500,
        'auto'           => false,
    ],

    'loyalty' => [
        'enabled'         => (bool) env('HOTEL_LOYALTY_ENABLED', true),
        'prefix'          => 'SH',      // member numbers: SH000123
        'points_per_1000' => 10,        // points per 1,000 spent
        'point_value'     => 1.0,       // money value of one point when spent
        'min_redeem'      => 500,       // fewest points that can be spent at once
        'silver_nights'   => 5,
        'gold_nights'     => 15,
        'silver_discount' => 5.0,       // % off room rates booked online
        'gold_discount'   => 10.0,
    ],

    /*
    | Operating expenses (spec §9, §14). Expenses above approval_limit need
    | approval by someone other than the person who recorded them (0 = all).
    | Departments are the cost centres expenses are charged to.
    */
    'expenses' => [
        'approval_limit' => (float) env('HOTEL_EXPENSE_APPROVAL_LIMIT', 50000),
        'departments'    => ['Rooms / Front Office', 'Housekeeping', 'Food & Beverage', 'Kitchen', 'Maintenance & Engineering', 'Security', 'Sales & Marketing', 'Administration', 'IT'],
    ],

    /*
    | IT control room (spec §11). Service-desk targets by ticket priority:
    | first response within response_minutes, fixed within resolve_hours.
    | Tickets that miss them are escalated. New critical/high monitoring
    | alerts and escalations are emailed to alert_emails (comma-separated in
    | .env). Check history older than check_retention_days is removed nightly.
    */
    'it' => [
        'response_minutes' => ['critical' => 15, 'high' => 60, 'normal' => 240, 'low' => 1440],
        'resolve_hours'    => ['critical' => 4, 'high' => 8, 'normal' => 24, 'low' => 72],
        'alert_emails'     => array_values(array_filter(array_map('trim', explode(',', (string) env('HOTEL_IT_ALERT_EMAILS', ''))))),
        'check_retention_days' => (int) env('HOTEL_IT_CHECK_RETENTION_DAYS', 90),
    ],

    /*
    | Internet & guest Wi-Fi (IT Support → Internet & Wi-Fi). "driver" is how the
    | app talks to the router the internet lines (Starlink dishes…) plug into:
    | demo (made-up readings to try it out) or manual (nothing connected).
    */
    'network' => [
        'driver'                => env('HOTEL_NETWORK_DRIVER', 'demo'),
        'guest_ssid'            => env('HOTEL_GUEST_WIFI_NAME', 'Hotel Guest'),
        'auto_issue'            => (bool) env('HOTEL_WIFI_AUTO_ISSUE', true),   // a Wi-Fi code for every guest at check-in
        'grace_minutes'         => (int) env('HOTEL_WIFI_GRACE_MINUTES', 60),   // after check-out time
        'code_length'           => 8,
        'sample_retention_days' => (int) env('HOTEL_NETWORK_RETENTION_DAYS', 30),
    ],

    /*
    | Night audit: closing the business day (room charges, no-shows, the daily
    | report). It runs from Front Desk → Night Audit, or by itself.
    */
    'night_audit' => [
        'day_ends_at'   => '06:00',
        'auto_close'    => (bool) env('HOTEL_NIGHT_AUDIT_AUTO', false),
        'auto_close_at' => '05:00',
        'auto_no_shows' => true,
        'report_emails' => [],
    ],

    // Room calendars (iCal) for Airbnb, Vrbo and similar sites.
    'channels' => [
        'ical' => [
            'enabled'       => true,
            'every_minutes' => 15,
        ],
    ],

    'audit' => [
        'flag_amount'   => (float) env('HOTEL_AUDIT_FLAG_AMOUNT', 50000),
        'flag_failures' => (int) env('HOTEL_AUDIT_FLAG_FAILURES', 5),
    ],

    /*
    | The call console (IT Control Room → Console): operators pick up guest
    | calls nobody answers, put calls through to the right person and, with the
    | 'Listen to guest calls' permission, listen in. Every listen is in the
    | audit trail, and callers are told at the start of each call.
    */
    'call_console' => [
        'monitoring'        => (bool) env('HOTEL_CALL_MONITORING', true),
        'monitoring_notice' => env('HOTEL_CALL_MONITORING_NOTICE', 'Calls may be listened to by a supervisor for quality and security.'),
        'escalate_after'    => (int) env('HOTEL_CALL_ESCALATE_SECONDS', 20),   // unanswered this long: the console alarms
        'device_stale_minutes' => 15,                                          // a hub that stops reporting: "no signal"
    ],
];
