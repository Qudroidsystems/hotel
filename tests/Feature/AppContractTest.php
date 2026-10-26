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

    // amount / amount_label are only on approvals (money to approve); the app reads them as optional.
    $work = ($this->api)('work')->json('data');
    if ($work) {
        $check('work[0] (WorkItem)', $work[0], ['actions', 'badge', 'detail', 'group', 'id', 'key', 'kind', 'meta', 'status', 'status_label', 'subtitle', 'time', 'title', 'tone', 'urgent', 'url']);
    }
    $desk = ($this->api)('front-desk')->json('data');
    $row = ($desk['arrivals'][0] ?? null) ?? ($desk['departures'][0] ?? null);
    if ($row) {
        $check('front-desk (DeskBooking)', $row, ['adults', 'arrival_time', 'balance', 'company', 'folio_id', 'guest', 'id', 'nights', 'phone', 'reference', 'rooms', 'status', 'status_label', 'url', 'vip']);
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

    $check('app/config (Branding)', $get('app/config')->json('data'), ['group_name', 'logo_url', 'multi_hotel', 'name', 'primary_color', 'support_email', 'support_phone', 'tagline', 'website']);
    $rules = $get('guest/booking')->json('data');
    $check('guest/booking (BookingRules)', $rules, ['advance_days', 'check_in_time', 'check_out_time', 'deposit_percent', 'enabled', 'hold_hours', 'max_nights', 'max_rooms', 'pay_online', 'policies', 'room_types']);
    $search = $get('guest/search?check_in=' . today()->addDays(40)->toDateString() . '&check_out=' . today()->addDays(42)->toDateString())->json('data');
    $check('guest/search[0] (RoomOffer)', $search[0] ?? null, ['amenities', 'available', 'bed', 'bookable', 'capacity', 'description', 'id', 'name', 'photos', 'rates', 'size_sqm', 'why_not']);
    $check('guest/search rate (RateOffer)', $search[0]['rates'][0] ?? null, ['deposit', 'deposit_label', 'inclusions', 'key', 'name', 'nightly', 'non_refundable', 'per_night', 'per_night_label', 'promo', 'rate_plan_id', 'total', 'total_label']);
    $check('guest/hotels[0] (HotelListing)', $get('guest/hotels')->json('data.0'), ['address', 'bookable', 'cover_url', 'description', 'from_label', 'id', 'location', 'name', 'nightly_from_label', 'nights', 'open', 'phone', 'problem', 'slug', 'stars']);

    $r = \App\Models\Reservation::whereIn('status', ['confirmed', 'checked_in'])->first();
    $token = app(\App\Services\Booking\OnlineBookingService::class)->ensureToken($r);
    $stay = $get('guest/stays/' . $token)->json('data');
    $check('guest/stays (GuestStay)', $stay, ['can_cancel', 'can_request', 'cancel_until', 'check_in', 'check_in_label', 'check_out', 'check_out_label', 'guest', 'guests_label', 'hold_until', 'hotel', 'money', 'nights', 'page_url', 'pay_url', 'qr', 'receipt_url', 'reference', 'request_types', 'requests', 'room_types', 'rooms', 'special_requests', 'state', 'status', 'status_label', 'token']);
    if ($stay['guest']['loyalty'] ?? null) {
        $check('guest loyalty', $stay['guest']['loyalty'], ['discount_percent', 'number', 'points', 'tier', 'tier_label', 'value_label']);
    }

    expect($miss)->toBe([]);
});
