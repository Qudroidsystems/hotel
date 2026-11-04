<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The two Flutter apps read these keys from the API (lib/data/*.dart). Each must be present in the answer
 * (null is fine), so a renamed field on the server is caught here rather than as a blank screen in the app.
 */
function missingKeys(array $row, array $keys): array
{
    return array_values(array_filter($keys, fn ($k) => ! array_key_exists($k, $row)));
}

beforeEach(function () {
    if (! class_exists(\Laravel\Sanctum\Sanctum::class)) {
        $this->markTestSkipped('Laravel Sanctum is not installed.');
    }
    \Illuminate\Support\Facades\Mail::fake();
    \Illuminate\Support\Facades\Notification::fake();
    $this->seed(DemoSeeder::class);
    $this->withoutDefer();
    $this->admin = User::role('Super Admin')->first();
    $this->admin->forceFill(['must_change_password' => false])->save();
    $this->token = $this->admin->createToken('contract')->plainTextToken;
    $this->api = function (string $path) {
        app('auth')->forgetGuards();

        return $this->withToken($this->token)->getJson('/api/v1/' . $path)->assertOk();
    };
});

test('the staff app finds every field it reads', function () {
    $miss = [];
    $check = function (string $what, $row, array $keys) use (&$miss) {
        if (! is_array($row)) {
            $miss[] = "{$what}: no data";

            return;
        }
        if ($m = missingKeys($row, $keys)) {
            $miss[] = "{$what}: " . implode(', ', $m);
        }
    };

    $check('app/config (Branding)', ($this->api)('app/config')->json('data'), ['company', 'currency_symbol', 'logo_url', 'name', 'primary_color', 'support_email', 'support_phone', 'tagline', 'website']);
    $me = ($this->api)('auth/me')->json('data');
    $check('auth/me (AppUser)', $me, ['avatar_url', 'business_unit', 'email', 'first_name', 'hotel', 'hotels', 'id', 'initials', 'job_title', 'modules', 'multi_hotel', 'must_change_password', 'name', 'permissions', 'phone_number', 'roles', 'user_type', 'user_type_label', 'username']);

    $home = ($this->api)('home')->json('data');
    $check('home (HomeData)', $home, ['approvals', 'attendance', 'counts', 'date', 'features', 'greeting', 'today', 'work']);
    $check('home.today (TodayFigures)', $home['today'] ?? null, ['arrivals', 'arrived', 'collected_label', 'departures', 'dirty', 'in_house', 'it_down', 'maintenance', 'occupancy', 'occupied', 'room_status', 'rooms']);

    $own = ($this->api)('owner')->json('data');
    $check('owner (OwnerDashboard)', $own, ['alerts', 'ahead', 'can_group', 'daily', 'headline', 'hotels', 'month', 'period', 'scope', 'title', 'today']);
    $check('owner figure (OwnerFigure)', $own['headline'][1], ['delta', 'delta_unit', 'hint', 'key', 'label', 'value_label']);
    $check('owner day (OwnerDay)', $own['daily'][0], ['collected', 'date', 'label', 'occupancy', 'revenue']);
    $check('owner ahead (OwnerDay)', $own['ahead'][0], ['date', 'label', 'occupancy', 'rooms', 'sold']);
    $check('owner month', $own['month'], ['collected_label', 'compare', 'label', 'occupancy', 'occupancy_delta', 'revenue_delta', 'revenue_label']);
    $check('owner today', $own['today'], ['arrivals', 'arrived', 'collected_label', 'departures', 'in_house', 'occupancy', 'occupied', 'pos_label', 'rooms']);
    // amount / amount_label are only on approvals (money to approve); the app reads them as optional.
    $work = ($this->api)('work')->json('data');
    if ($work) {
        $check('work[0] (WorkItem)', $work[0], ['actions', 'badge', 'detail', 'group', 'id', 'key', 'kind', 'meta', 'status', 'status_label', 'subtitle', 'time', 'title', 'tone', 'urgent', 'url']);
    }
    $desk = ($this->api)('front-desk')->json('data');
    $row = ($desk['arrivals'][0] ?? null) ?? ($desk['departures'][0] ?? null);
    if ($row) {
        $check('front-desk (DeskBooking)', $row, ['adults', 'arrival_time', 'balance', 'company', 'folio_id', 'guest', 'id', 'nights', 'on_the_way', 'phone', 'reference', 'rooms', 'status', 'status_label', 'url', 'vip']);
    }
    $check('rooms[0] (RoomInfo)', ($this->api)('rooms')->json('data.0'), ['floor', 'id', 'number', 'status', 'status_label', 'type']);
    $check('me/preferences', ($this->api)('me/preferences')->json('data'), ['push_available', 'push_enabled', 'reminder_minutes', 'reminders_enabled']);
    $check('notifications/settings', ($this->api)('notifications/settings')->json('data'), ['digest', 'groups', 'in_quiet_hours', 'quiet_from', 'quiet_to']);

    $na = ($this->api)('night-audit')->json('data');
    $check('night-audit', $na, ['auto_close', 'business_date', 'business_date_label', 'can_close', 'checks', 'days_behind', 'recent', 'today']);
    if ($na['today'] ?? null) {
        $check('night-audit.today (DayFigures)', $na['today'], ['adr', 'arrivals', 'collected', 'departures', 'no_shows', 'occupancy', 'owed_after_stay', 'revenue', 'revpar', 'rooms_for_sale', 'rooms_sold']);
    }
    $events = ($this->api)('events?when=upcoming')->json('data');
    if ($events) {
        $check('events/{id} (HotelEvent)', ($this->api)('events/' . $events[0]['id'])->json('data'), ['actions', 'balance', 'contact', 'ends_at', 'guests', 'hall', 'hall_color', 'id', 'internal_notes', 'items', 'number', 'organiser', 'organiser_email', 'organiser_phone', 'package', 'requirements', 'setup', 'starts_at', 'status', 'status_label', 'title', 'total', 'type', 'url', 'when']);
    }
    $companies = ($this->api)('companies')->json('data');
    if ($companies) {
        $check('companies[0] (CompanyAccount)', $companies[0], ['account_number', 'available', 'credit_limit', 'id', 'name', 'owes']);
    }

    // Guest chat: a guest's message to Reception, as the team sees it.
    $inHouse = \App\Models\Reservation::where('status', 'checked_in')->first();
    $chat = app(\App\Services\Chat\ChatService::class);
    $reception = $chat->sections()->firstWhere('key', 'reception');
    $chat->guestSend($inHouse, $reception, 'Could I have two more towels?', null, (string) \Illuminate\Support\Str::uuid());
    $box = ($this->api)('chats')->json('data');
    $check('chats (ChatInbox)', $box, ['conversations', 'live', 'ringing', 'sections', 'unread']);
    $check('chats section (ChatSectionRow)', $box['sections'][0] ?? null, ['hours', 'icon', 'id', 'name', 'open', 'unread']);
    $conv = $box['conversations'][0] ?? null;
    $check('chats conversation (ChatConv)', $conv, ['assigned', 'guest', 'id', 'last', 'last_at', 'last_label', 'phone', 'reference', 'room', 'section', 'section_icon', 'section_id', 'stay', 'stay_status', 'status', 'typing', 'unread', 'vip']);
    if ($conv) {
        $th = ($this->api)('chats/' . $conv['id'])->json('data');
        $check('chats/{id} (StaffThread)', $th, ['call', 'can_call', 'conversation', 'guest_read_up_to', 'messages', 'quick_replies', 'typing']);
        $check('chat message (ChatMsg)', $th['messages'][0] ?? null, ['body', 'client_id', 'day', 'id', 'mine', 'name', 'photo', 'read', 'sender', 'time']);
    }
    $check('home counts', $home['counts'] ?? null, ['approvals', 'chats', 'unread_notifications', 'urgent', 'work']);

    // Energy Saver (lib/data/energy.dart).
    $en = ($this->api)('energy')->json('data');
    $check('energy (EnergyHome)', $en, ['actions', 'all_on_kw', 'at', 'can_manage', 'counts', 'forecast', 'now_kw', 'peak24_kw', 'power', 'switched_off', 'week']);
    $check('energy power (EnergyPower)', $en['power'] ?? null, ['choice', 'grid_on', 'line', 'litres_per_hour', 'plan', 'plan_litres', 'plan_saves_litres', 'sources', 'state', 'urgent']);
    $check('energy source (EnergySourceInfo)', $en['power']['sources'][0] ?? null, ['capacity_kw', 'fuel_litres', 'hours_left', 'id', 'kind', 'name', 'run_hours_today', 'running', 'since', 'tank_litres']);
    $check('energy action (EnergyActionItem)', $en['actions'][0] ?? null, ['action', 'done_by', 'id', 'kw', 'reason', 'saves', 'target', 'title', 'until']);
    $check('energy week', $en['week'] ?? null, ['label']);

    // The door (lib/data/tickets.dart): tonight's ticketed event and a scan.
    $ev = contractTicketedEvent();
    $door = ($this->api)('tickets/door')->json('data');
    $check('tickets/door (DoorEvent)', collect($door)->firstWhere('id', $ev->id), ['capacity', 'doors_at', 'doors_open', 'hall', 'id', 'in', 'people_in', 'sold', 'title', 'when']);
    $sold = app(\App\Services\Events\TicketService::class)->sell($ev, [$ev->ticketTypes()->first()->id => 1], ['name' => 'Door Test'], 'cash');
    app('auth')->forgetGuards();
    $scan = $this->withToken($this->token)->postJson('/api/v1/tickets/scan', ['code' => $sold->tickets()->first()->code])->assertOk()->json('data');
    $check('tickets/scan (ScanResult)', $scan, ['message', 'ok', 'state', 'ticket']);
    $check('scan ticket', $scan['ticket'] ?? null, ['admits', 'code', 'event', 'name', 'type', 'used_at']);

    // Driver mode, as the demo driver (a ride assigned to him).
    $musa = User::where('email', 'driver.musa@smarthotel.com')->first();
    if ($musa) {
        $musa->forceFill(['must_change_password' => false])->save();
        $driver = \App\Models\Transport\Driver::where('user_id', $musa->id)->first();
        $open = \App\Models\Transport\Ride::where('status', 'requested')->first();
        if ($driver && $open && ! $driver->activeRide()) {
            app(\App\Services\Transport\RideService::class)->assign($open, $driver, $driver->vehicle, $this->admin);
        }
        app('auth')->forgetGuards();
        \Laravel\Sanctum\Sanctum::actingAs($musa->fresh(), ['*']);
        $drv = $this->getJson('/api/v1/driver')->assertOk()->json('data');
        $check('driver (DriverHome)', $drv, ['car', 'licence_warning', 'name', 'on_duty', 'ping_seconds', 'ride', 'upcoming']);
        $ride = $drv['ride'] ?? ($drv['upcoming'][0] ?? null);
        $check('driver ride (DriverRide)', $ride, ['bags', 'direction', 'eta_minutes', 'fare_label', 'flight', 'guest', 'guest_phone', 'id', 'nav', 'notes', 'number', 'passengers', 'payment', 'place', 'room', 'route', 'status', 'when']);
    } else {
        $miss[] = 'no demo driver';
    }

    // My payslips (someone on last month's demo payroll).
    $slip = \App\Models\Payroll\Payslip::first();
    if ($slip) {
        app('auth')->forgetGuards();
        $slip->user->forceFill(['must_change_password' => false])->save();
        \Laravel\Sanctum\Sanctum::actingAs($slip->user->fresh(), ['*']);
        $ps = $this->getJson('/api/v1/me/payslips')->assertOk()->json('data.0');
        $check('my payslip (MyPayslip)', $ps, ['attendance', 'bank', 'deductions', 'deductions_label', 'earnings', 'gross_label', 'id', 'month', 'net_label', 'paid', 'url']);
        $check('payslip line', $ps['earnings'][0] ?? null, ['amount_label', 'name']);
    } else {
        $miss[] = 'no payslips in the demo data';
    }

    expect($miss)->toBe([]);
});

test('the guest app finds every field it reads', function () {
    $miss = [];
    $check = function (string $what, $row, array $keys) use (&$miss) {
        if (! is_array($row)) {
            $miss[] = "{$what}: no data";

            return;
        }
        if ($m = missingKeys($row, $keys)) {
            $miss[] = "{$what}: " . implode(', ', $m);
        }
    };
    auth()->logout();
    $get = fn ($p) => $this->getJson('/api/v1/' . $p)->assertOk();

    $check('app/config (Branding)', $get('app/config')->json('data'), ['group_name', 'logo_url', 'maps', 'multi_hotel', 'name', 'primary_color', 'support_email', 'support_phone', 'tagline', 'website']);
    $rules = $get('guest/booking')->json('data');
    $check('guest/booking (BookingRules)', $rules, ['advance_days', 'check_in_time', 'check_out_time', 'deposit_percent', 'enabled', 'hold_hours', 'max_nights', 'max_rooms', 'pay_online', 'policies', 'room_types']);
    $search = $get('guest/search?check_in=' . today()->addDays(40)->toDateString() . '&check_out=' . today()->addDays(42)->toDateString())->json('data');
    $check('guest/search[0] (RoomOffer)', $search[0] ?? null, ['amenities', 'available', 'bed', 'bookable', 'capacity', 'description', 'id', 'name', 'photos', 'rates', 'size_sqm', 'why_not']);
    $check('guest/search rate (RateOffer)', $search[0]['rates'][0] ?? null, ['deposit', 'deposit_label', 'inclusions', 'key', 'name', 'nightly', 'non_refundable', 'per_night', 'per_night_label', 'promo', 'rate_plan_id', 'total', 'total_label']);
    $check('guest/hotels[0] (HotelListing)', $get('guest/hotels')->json('data.0'), ['address', 'bookable', 'cover_url', 'description', 'from_label', 'id', 'location', 'name', 'nightly_from_label', 'nights', 'open', 'phone', 'problem', 'slug', 'stars']);

    $r = \App\Models\Reservation::whereIn('status', ['confirmed', 'checked_in'])->first();
    $token = app(\App\Services\Booking\OnlineBookingService::class)->ensureToken($r);
    $stay = $get('guest/stays/' . $token)->json('data');
    $check('guest/stays (GuestStay)', $stay, ['arrival', 'can_cancel', 'can_order', 'can_request', 'cancel_until', 'check_in', 'check_in_label', 'check_out', 'check_out_label', 'guest', 'guests_label', 'hold_until', 'hotel', 'money', 'nights', 'order_note', 'orders', 'page_url', 'pay_url', 'qr', 'receipt_url', 'reference', 'request_types', 'requests', 'room_types', 'rooms', 'special_requests', 'state', 'status', 'status_label', 'token']);
    if ($stay['guest']['loyalty'] ?? null) {
        $check('guest loyalty', $stay['guest']['loyalty'], ['discount_percent', 'group', 'number', 'points', 'tier', 'tier_label', 'value_label']);
    }

    $check('stay arrival (ArrivalInfo)', $stay['arrival'] ?? null, ['arrived', 'available', 'directions', 'distance_label', 'eta_label', 'eta_minutes', 'hotel_lat', 'hotel_lng', 'note', 'ping_seconds', 'progress', 'sharing']);

    // The weather at the hotel (Open-Meteo, faked here).
    \App\Models\Hotel::query()->update(['latitude' => 6.4281, 'longitude' => 3.4219]);
    $tz = 'Africa/Lagos';
    $day = now($tz)->toDateString();
    $hour = now($tz)->format('Y-m-d\\TH:00');
    \Illuminate\Support\Facades\Http::fake(['api.open-meteo.com/*' => \Illuminate\Support\Facades\Http::response([
        'timezone' => $tz,
        'current' => ['time' => $hour, 'temperature_2m' => 29, 'apparent_temperature' => 32, 'relative_humidity_2m' => 70, 'weather_code' => 61, 'wind_speed_10m' => 9, 'is_day' => 1],
        'hourly' => ['time' => [$hour], 'temperature_2m' => [29], 'precipitation_probability' => [70], 'weather_code' => [61], 'is_day' => [1]],
        'daily' => ['time' => [$day], 'weather_code' => [61], 'temperature_2m_max' => [31], 'temperature_2m_min' => [24], 'precipitation_probability_max' => [70], 'precipitation_sum' => [4], 'uv_index_max' => [7], 'wind_speed_10m_max' => [12], 'sunrise' => [$day . 'T06:45'], 'sunset' => [$day . 'T18:50']],
    ])]);
    $wx = $get('guest/stays/' . $token . '/weather')->json('data');
    $check('stay weather (WeatherInfo)', $wx, ['available', 'days', 'hours', 'note', 'now', 'source', 'source_url', 'stay_note', 'tips', 'unit', 'updated_label']);
    $check('weather now (WeatherNow)', $wx['now'] ?? null, ['feels_like', 'humidity', 'is_day', 'kind', 'summary', 'temp', 'wind_label']);
    $check('weather hour (WeatherHour)', $wx['hours'][0] ?? null, ['is_day', 'kind', 'label', 'rain_chance', 'summary', 'temp']);
    $check('weather day (WeatherDay)', $wx['days'][0] ?? null, ['arrival', 'date', 'departure', 'in_stay', 'kind', 'label', 'max', 'min', 'rain_chance', 'short', 'summary', 'sunrise', 'sunset', 'uv']);
    $check('weather tip (WeatherTip)', $wx['tips'][0] ?? null, ['icon', 'text', 'tone']);

    // Guest account (signed in with a code).
    \Illuminate\Support\Facades\Mail::shouldReceive('raw')->andReturnUsing(function ($text) use (&$mailed) { $mailed = $text; });
    $email = $r->guest->email ?: 'contract@example.com';
    $r->guest->forceFill(['email' => $email])->save();
    $this->postJson('/api/v1/guest/auth/code', ['contact' => $email])->assertOk();
    preg_match('/^(\d{6})/', (string) $mailed, $mm);
    $acct = $this->postJson('/api/v1/guest/auth/verify', ['contact' => $email, 'code' => $mm[1] ?? ''])->assertOk()->json('data');
    $check('guest/auth/verify', $acct, ['account', 'expires_at', 'token']);
    $me = $this->getJson('/api/v1/guest/me', ['X-Guest-Token' => $acct['token']])->assertOk()->json('data');
    $check('guest/me (GuestAccount)', $me, ['contact', 'counts', 'email', 'first_name', 'last_name', 'marketing_opt_in', 'memberships', 'name', 'nationality', 'phone', 'points_history', 'stays']);
    $check('guest/me stay (summary)', $me['stays'][0] ?? null, ['check_in', 'check_in_label', 'check_out', 'hotel', 'nights', 'reference', 'room_types', 'state', 'status', 'status_label', 'token']);
    if ($me['memberships']) {
        $check('membership', $me['memberships'][0], ['hotel', 'number', 'points', 'tier', 'tier_label', 'value_label']);
    }
    if ($me['points_history']) {
        $check('points line', $me['points_history'][0], ['date_label', 'hotel', 'note', 'points']);
    }

    // The car service and arrival sharing on a stay.
    $rideStay = \App\Models\Transport\Ride::whereNotNull('reservation_id')->latest('id')->first()?->reservation;
    if ($rideStay) {
        $rt = app(\App\Services\Booking\OnlineBookingService::class)->ensureToken($rideStay);
        $cars = $get("guest/stays/{$rt}/rides")->json('data');
        $check('rides (CarService)', $cars, ['available', 'min_notice', 'note', 'phone', 'places', 'priced_by_distance', 'search']);
        $check('ride (RideInfo)', $cars['rides'][0] ?? null, ['bags', 'can_cancel', 'cancel_reason', 'car', 'direction', 'driver', 'eta_minutes', 'fare_label', 'flight', 'hotel_lat', 'hotel_lng', 'id', 'live', 'number', 'open', 'passengers', 'path', 'place', 'place_lat', 'place_lng', 'route', 'status', 'status_label', 'step', 'when']);
        $st = $get("guest/stays/{$rt}")->json('data');
        $check('stay arrival (ArrivalInfo)', $st['arrival'] ?? null, ['arrived', 'available', 'directions', 'distance_label', 'eta_label', 'eta_minutes', 'hotel_lat', 'hotel_lng', 'note', 'ping_seconds', 'progress', 'sharing']);
        $check('stay cars', $st['cars'] ?? null, ['available', 'rides']);
    } else {
        $miss[] = 'no demo ride';
    }

    // Event tickets bought online (lib/data/explore_models.dart).
    $ev = contractTicketedEvent();
    $list = $get('guest/events')->json();
    $pe = collect($list['data'])->firstWhere('id', $ev->id);
    $check('guest/events (PublicEvent)', $pe, ['ends_at', 'from_label', 'hall', 'has_promos', 'id', 'info', 'starts_at', 'ticket_types', 'title', 'type', 'when']);
    $check('event ticket type (TicketType)', $pe['ticket_types'][0] ?? null, ['admits', 'available', 'description', 'early_until', 'full_price_label', 'id', 'left', 'max_per_order', 'name', 'note', 'price', 'price_label']);
    $check('guest/events meta', $list['meta'] ?? null, ['currency', 'pay_online']);
    $tq = $this->postJson("/api/v1/guest/events/{$ev->id}/quote", ['lines' => [['ticket_type_id' => $pe['ticket_types'][0]['id'], 'quantity' => 2]], 'promo' => 'CONTRACT'])->assertOk()->json('data');
    $check('ticket quote (TicketQuote)', $tq, ['count', 'discount_label', 'promo', 'rows', 'total', 'total_label']);
    $check('ticket quote row', $tq['rows'][0] ?? null, ['amount_label', 'early_bird', 'name', 'quantity']);
    $free = $ev->ticketTypes()->create(['name' => 'Guest list', 'price' => 0]);
    $to = $this->postJson("/api/v1/guest/events/{$ev->id}/orders", ['lines' => [['ticket_type_id' => $free->id, 'quantity' => 1]], 'name' => 'Ada', 'email' => 'ada@example.com'])->assertCreated()->json('data');
    $check('ticket order (TicketOrder)', $to, ['event', 'hall', 'held_until', 'number', 'pay_url', 'quantity', 'status', 'tickets', 'token', 'total_label', 'url', 'when']);
    $check('ticket order ticket (GuestTicket)', $get("guest/ticket-orders/{$to['token']}")->json('data.tickets.0'), ['code', 'status', 'type', 'url']);

    // Room orders: an in-house guest's menu, and an order on their stay.
    $in = \App\Models\Reservation::where('status', 'checked_in')->first();
    $t2 = app(\App\Services\Booking\OnlineBookingService::class)->ensureToken($in);
    $outlet = \App\Models\Pos\PosOutlet::where('guest_orders', true)->first();
    if ($outlet) {
        $outlet->update(['guest_hours' => null]);
        $menu = $get("guest/stays/{$t2}/menu")->json('data');
        $check('guest menu (RoomMenu)', $menu, ['blocker', 'can_order', 'currency', 'outlets']);
        $check('menu outlet (MenuOutlet)', $menu['outlets'][0] ?? null, ['categories', 'hours', 'id', 'minutes', 'name', 'open']);
        $check('menu item (MenuItem)', $menu['outlets'][0]['categories'][0]['items'][0] ?? null, ['description', 'id', 'name', 'price', 'price_label']);
        $item = $menu['outlets'][0]['categories'][0]['items'][0]['id'];
        $placed = $this->postJson("/api/v1/guest/stays/{$t2}/orders", ['outlet_id' => $outlet->id, 'lines' => [['item_id' => $item, 'quantity' => 1]]])->assertCreated()->json('data.orders.0');
        $check('stay order (GuestOrderInfo)', $placed, ['can_cancel', 'eta', 'id', 'live', 'note', 'number', 'outlet', 'status', 'status_label', 'summary', 'time', 'total_label']);
    } else {
        $miss[] = 'no outlet takes room orders in the demo data';
    }

    // Laundry: the price list and a bag.
    $ld = $get("guest/stays/{$t2}/laundry")->json('data');
    $check('laundry (LaundryInfo)', $ld, ['available', 'blocker', 'collecting_now', 'currency', 'express', 'groups', 'hours', 'note', 'orders', 'standard_hours']);
    $check('laundry express', $ld['express'] ?? null, ['hours', 'percent']);
    $check('laundry item (LaundryPriceItem)', $ld['groups'][0]['items'][0] ?? null, ['id', 'name', 'services']);
    $check('laundry service', $ld['groups'][0]['items'][0]['services'][0] ?? null, ['id', 'label', 'price', 'price_label']);
    \App\Models\Laundry\LaundryOrder::where('reservation_id', $in->id)->where('status', 'requested')->update(['status' => 'cancelled']);
    $bag = $this->postJson("/api/v1/guest/stays/{$t2}/laundry", ['express' => true])->assertCreated()->json('data.orders.0');
    $check('laundry bag (LaundryBag)', $bag, ['can_cancel', 'due', 'express', 'id', 'live', 'note', 'number', 'pieces', 'status', 'status_label', 'summary', 'time', 'total_label']);
    $check('stay laundry', $get("guest/stays/{$t2}")->json('data'), ['can_laundry', 'laundry']);

    // Spa, gym & pool.
    $in->update(['check_out_date' => today()->addDays(3)]);
    $wl = $get("guest/stays/{$t2}/wellness")->json('data');
    $check('wellness (WellnessInfo)', $wl, ['available', 'blocker', 'bookings', 'cancel_hours', 'currency', 'first_day', 'last_day', 'venues']);
    $check('wellness place (WellnessPlace)', $wl['venues'][0] ?? null, ['description', 'hours', 'id', 'kind', 'max_people', 'name', 'price', 'price_label', 'services', 'session_minutes', 'treatments']);
    $spa = collect($wl['venues'])->firstWhere('treatments', true);
    $check('wellness treatment (WellnessTreatment)', $spa['services'][0] ?? null, ['description', 'id', 'minutes', 'name', 'price', 'price_label']);
    $ws = $get("guest/stays/{$t2}/wellness/slots?venue_id={$spa['id']}&service_id={$spa['services'][0]['id']}&date=" . today()->addDay()->toDateString())->json('data');
    $check('wellness slots', $ws, ['slots', 'total_label']);
    $check('wellness slot (WellnessSlot)', $ws['slots'][0] ?? null, ['label', 'left', 'starts_at', 'time']);
    $wb = $this->postJson("/api/v1/guest/stays/{$t2}/wellness", ['venue_id' => $spa['id'], 'service_id' => $spa['services'][0]['id'], 'starts_at' => $ws['slots'][0]['starts_at'] ?? null])->assertCreated()->json('data.bookings.0');
    $check('wellness booking (WellnessBookingInfo)', $wb, ['can_cancel', 'id', 'kind', 'note', 'number', 'payment_label', 'status', 'status_label', 'therapist', 'total_label', 'upcoming', 'venue', 'what', 'when']);
    $check('stay wellness', $get("guest/stays/{$t2}")->json('data'), ['can_wellness', 'keys', 'wellness']);

    // Chat with the hotel's sections.
    $ch = $get("guest/stays/{$t2}/chat")->json('data');
    $check('chat home (ChatHome)', $ch, ['available', 'calls', 'live', 'note', 'sections']);
    $check('chat section (ChatSectionInfo)', $ch['sections'][0] ?? null, ['can_call', 'description', 'hours', 'icon', 'id', 'key', 'last', 'last_label', 'name', 'open', 'phone', 'typing', 'unread']);
    if ($ch['sections'][0] ?? null) {
        $this->postJson("/api/v1/guest/stays/{$t2}/chat/{$ch['sections'][0]['id']}", ['body' => 'Hello there', 'client_id' => (string) \Illuminate\Support\Str::uuid()])->assertCreated();
        $gt = $get("guest/stays/{$t2}/chat/{$ch['sections'][0]['id']}")->json('data');
        $check('chat thread (ChatThread)', $gt, ['call', 'messages', 'read_up_to', 'section', 'typing']);
        $check('chat thread section', $gt['section'] ?? null, ['can_call', 'hours', 'id', 'name', 'open', 'phone']);
        $check('chat message (ChatMsg)', $gt['messages'][0] ?? null, ['body', 'client_id', 'day', 'id', 'mine', 'name', 'photo', 'read', 'sender', 'time']);
    }
    $check('stay chat', $get("guest/stays/{$t2}")->json('data.chat'), ['available', 'unread']);

    expect($miss)->toBe([]);
});

/** A confirmed event tonight with tickets on sale (an early-bird type and a promo code), for the contract checks. */
function contractTicketedEvent(): \App\Models\Events\Event
{
    if ($e = \App\Models\Events\Event::where('title', 'Contract Night')->first()) {
        return $e;
    }
    $hall = \App\Models\Events\Hall::first() ?? \App\Models\Events\Hall::create(['name' => 'Contract Hall', 'capacity_theatre' => 100, 'capacity_banquet' => 80]);
    $e = \App\Models\Events\Event::create(['number' => 'EV-CONTRACT', 'title' => 'Contract Night', 'hall_id' => $hall->id, 'status' => 'confirmed', 'event_type' => 'other', 'setup' => 'theatre',
        'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(5), 'guests_expected' => 50, 'organiser_name' => 'Hotel', 'subtotal' => 0, 'total' => 0,
        'is_ticketed' => true, 'ticket_sales_open' => true]);
    $e->forceFill(['is_ticketed' => true, 'ticket_sales_open' => true])->save();
    $e->ticketTypes()->create(['name' => 'Regular', 'price' => 5000, 'early_price' => 4000, 'early_until' => now()->addDay()]);
    $e->ticketPromos()->create(['code' => 'CONTRACT', 'kind' => 'percent', 'value' => 10]);

    return $e;
}
