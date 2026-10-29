<?php

use App\Enums\ReservationStatus;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Services\Booking\OnlineBookingService;
use App\Services\Weather\WeatherService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** An Open-Meteo answer: rain this afternoon, then [code, max, min, rain %] per day. */
function fakeForecast(array $days): array
{
    $tz = 'Africa/Lagos';
    $start = Carbon::now($tz)->startOfDay();
    $hourly = ['time' => [], 'temperature_2m' => [], 'precipitation_probability' => [], 'weather_code' => [], 'is_day' => []];
    for ($i = 0; $i < count($days) * 24; $i++) {
        $at = $start->copy()->addHours($i);
        $hourly['time'][] = $at->format('Y-m-d\TH:i');
        $hourly['temperature_2m'][] = 27 + ($i % 24 > 11 ? 3 : 0);
        $rainy = $at->gt(Carbon::now($tz)->addHours(2)) && $at->lt(Carbon::now($tz)->addHours(5));
        $hourly['precipitation_probability'][] = $rainy ? 80 : 10;
        $hourly['weather_code'][] = $rainy ? 63 : 2;
        $hourly['is_day'][] = $at->hour >= 7 && $at->hour < 19 ? 1 : 0;
    }
    $daily = ['time' => [], 'weather_code' => [], 'temperature_2m_max' => [], 'temperature_2m_min' => [], 'precipitation_probability_max' => [], 'precipitation_sum' => [], 'uv_index_max' => [], 'wind_speed_10m_max' => [], 'sunrise' => [], 'sunset' => []];
    foreach ($days as $i => [$code, $max, $min, $rain]) {
        $d = $start->copy()->addDays($i)->toDateString();
        $daily['time'][] = $d;
        $daily['weather_code'][] = $code;
        $daily['temperature_2m_max'][] = $max;
        $daily['temperature_2m_min'][] = $min;
        $daily['precipitation_probability_max'][] = $rain;
        $daily['precipitation_sum'][] = $rain > 50 ? 6.2 : 0;
        $daily['uv_index_max'][] = 9.1;
        $daily['wind_speed_10m_max'][] = 14;
        $daily['sunrise'][] = $d . 'T06:45';
        $daily['sunset'][] = $d . 'T18:50';
    }

    return [
        'timezone' => $tz,
        'current' => ['time' => Carbon::now($tz)->format('Y-m-d\TH:00'), 'temperature_2m' => 29.4, 'apparent_temperature' => 33.1, 'relative_humidity_2m' => 78, 'precipitation' => 0, 'weather_code' => 2, 'wind_speed_10m' => 11.6, 'is_day' => 1],
        'hourly' => $hourly,
        'daily' => $daily,
    ];
}

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    $this->withoutDefer();
    Hotel::query()->update(['latitude' => 6.4281, 'longitude' => 3.4219]);
    $this->r = Reservation::where('status', ReservationStatus::Confirmed->value)->first();
    $this->r->forceFill(['check_in_date' => today()->addDays(2), 'check_out_date' => today()->addDays(4)])->save();
    $this->token = app(OnlineBookingService::class)->ensureToken($this->r);
    $days = [[2, 31, 24, 80], [3, 30, 24, 20], [63, 28, 23, 85], [1, 32, 24, 5], [2, 31, 24, 10], [3, 30, 23, 30], [2, 30, 23, 20]];
    $this->down = false;
    Http::fake(['api.open-meteo.com/*' => fn () => $this->down ? Http::response('busy', 503) : Http::response(fakeForecast($days))]);
});

test('a guest sees the weather now, the next hours, the days of their stay and tips for getting around', function () {
    $w = $this->getJson("/api/v1/guest/stays/{$this->token}/weather")->assertOk()->json('data');

    expect($w['available'])->toBeTrue()
        ->and($w['unit'])->toBe('°C')
        ->and($w['now']['temp'])->toBe(29)
        ->and($w['now']['summary'])->toBe('Partly cloudy')
        ->and($w['now']['kind'])->toBe('partly')
        ->and($w['hours'])->not->toBeEmpty()
        ->and(count($w['days']))->toBe(7)
        ->and($w['days'][0]['label'])->toBe('Today')
        ->and($w['days'][2]['arrival'])->toBeTrue()
        ->and($w['days'][2]['in_stay'])->toBeTrue()
        ->and($w['days'][4]['departure'])->toBeTrue()
        ->and($w['days'][5]['in_stay'])->toBeFalse()
        ->and($w['stay_note'])->toBeNull();

    $tips = collect($w['tips'])->pluck('text')->implode(' | ');
    expect($tips)->toContain('Rain likely')->toContain('umbrella')
        ->and($tips)->toContain('Your arrival day')
        ->and($tips)->toContain('Strong sun');

    // Asked once, then kept for half an hour.
    $this->getJson("/api/v1/guest/stays/{$this->token}/weather")->assertOk();
    Http::assertSentCount(1);
    Http::assertSent(fn ($req) => str_contains($req->url(), 'latitude=6.4281') && str_contains($req->url(), 'timezone=auto'));
});

test('the booking page has the weather card and its own JSON', function () {
    $this->get(route('stay.show', $this->token))->assertOk()->assertSee('Weather at the hotel')->assertSee(route('stay.weather', $this->token), false);
    $this->getJson(route('stay.weather', $this->token))->assertOk()->assertJsonPath('weather.available', true)->assertJsonPath('weather.days.2.arrival', true);
});

test('a stay too far ahead shows the hotel weather with a note; a finished stay shows none', function () {
    $this->r->forceFill(['check_in_date' => today()->addDays(40), 'check_out_date' => today()->addDays(42)])->save();
    $w = $this->getJson("/api/v1/guest/stays/{$this->token}/weather")->json('data');
    expect($w['available'])->toBeTrue()->and($w['stay_note'])->toContain('about two weeks before you arrive')
        ->and(collect($w['days'])->where('in_stay', true))->toBeEmpty();

    $this->r->forceFill(['status' => ReservationStatus::CheckedOut])->save();
    expect($this->getJson("/api/v1/guest/stays/{$this->token}/weather")->json('data.available'))->toBeFalse();
    $this->get(route('stay.show', $this->token))->assertOk()->assertDontSee('Weather at the hotel');
});

test('turned off, or the hotel not on the map, or the service down: no forecast and no error', function () {
    config(['hotel.booking.weather' => false]);
    expect($this->getJson("/api/v1/guest/stays/{$this->token}/weather")->json('data'))->toMatchArray(['available' => false, 'note' => null]);
    $this->get(route('stay.show', $this->token))->assertDontSee('Weather at the hotel');

    config(['hotel.booking.weather' => true]);
    Hotel::query()->update(['latitude' => null, 'longitude' => null]);
    expect($this->getJson("/api/v1/guest/stays/{$this->token}/weather")->json('data.note'))->toContain('set its location');

    Hotel::query()->update(['latitude' => 9.0765, 'longitude' => 7.3986]);
    $this->down = true;
    $w = $this->getJson("/api/v1/guest/stays/{$this->token}/weather")->assertOk()->json('data');
    expect($w['available'])->toBeFalse()->and($w['note'])->toContain('isn’t available right now');
});

test('weather codes read in plain words', function () {
    expect(WeatherService::describe(0)['summary'])->toBe('Sunny')
        ->and(WeatherService::describe(0, false)['icon'])->toBe('ri-moon-clear-line')
        ->and(WeatherService::describe(95)['kind'])->toBe('storm')
        ->and(WeatherService::describe(65)['summary'])->toBe('Heavy rain')
        ->and(WeatherService::describe(45)['kind'])->toBe('fog');
});
