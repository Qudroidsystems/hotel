<?php

namespace App\Services\Weather;

use App\Enums\ReservationStatus as S;
use App\Models\Reservation;
use App\Services\Booking\ArrivalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The weather at the hotel for its guests (Hotel Settings → Online Booking):
 * now, the next hours, and the days ahead with the days of the stay marked,
 * plus a few plain tips on getting around ("rain from about 3 PM — take an
 * umbrella or book the hotel's car").
 *
 * The forecast comes from Open-Meteo (open-meteo.com), which needs no key.
 * It is kept for half an hour per hotel and fails soft: if the service can't
 * be reached the guest sees "not available right now", never an error.
 */
class WeatherService
{
    public const CACHE_MINUTES = 30;
    public const DAYS = 14;
    public const URL = 'https://api.open-meteo.com/v1/forecast';   // Open-Meteo forecasts up to 16 days

    public function __construct(private ArrivalService $arrivals)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('hotel.booking.weather', true) && $this->arrivals->hotelPoint() !== null;
    }

    public function fahrenheit(): bool
    {
        return (bool) config('hotel.booking.weather_fahrenheit', false);
    }

    /**
     * Open-Meteo's answer for a point, or null when it can't be had.
     * A failure is remembered for a few minutes so a slow service isn't asked on every page.
     */
    public function raw(float $lat, float $lng): ?array
    {
        $key = 'weather:' . round($lat, 2) . ',' . round($lng, 2) . ':' . ($this->fahrenheit() ? 'f' : 'c');
        if ($hit = Cache::get($key)) {
            return $hit === 'down' ? null : $hit;
        }
        try {
            $res = Http::timeout(6)->acceptJson()->get(self::URL, [
                'latitude' => round($lat, 4), 'longitude' => round($lng, 4), 'timezone' => 'auto', 'forecast_days' => self::DAYS,
                'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation,weather_code,wind_speed_10m,is_day',
                'hourly' => 'temperature_2m,precipitation_probability,weather_code,is_day',
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,uv_index_max,wind_speed_10m_max,sunrise,sunset',
                'temperature_unit' => $this->fahrenheit() ? 'fahrenheit' : 'celsius',
                'wind_speed_unit' => $this->fahrenheit() ? 'mph' : 'kmh',
            ]);
            if ($res->successful() && is_array($res->json('daily.time'))) {
                $data = $res->json();
                Cache::put($key, $data, now()->addMinutes(self::CACHE_MINUTES));

                return $data;
            }
            Log::notice('Weather forecast: HTTP ' . $res->status() . ' ' . str($res->body())->limit(200));
        } catch (Throwable $e) {
            Log::notice('Weather forecast unreachable: ' . $e->getMessage());
        }
        Cache::put($key, 'down', now()->addMinutes(5));

        return null;
    }

    /** What the guest app and the booking page show for a stay. */
    public function forGuest(Reservation $r): array
    {
        $off = ['available' => false, 'note' => null, 'unit' => $this->fahrenheit() ? '°F' : '°C', 'now' => null, 'hours' => [], 'days' => [], 'tips' => [], 'stay_note' => null, 'source' => 'Open-Meteo', 'source_url' => 'https://open-meteo.com/'];
        if (! in_array($r->status, [S::Pending, S::Confirmed, S::CheckedIn], true)) {
            return $off;
        }
        if (! $this->enabled()) {
            return ['note' => config('hotel.booking.weather', true) ? 'The weather forecast appears here once the hotel has set its location.' : null] + $off;
        }
        [$lat, $lng] = $this->arrivals->hotelPoint();
        $w = $this->raw($lat, $lng);
        if (! $w) {
            return ['note' => 'The weather forecast isn’t available right now. Please try again later.'] + $off;
        }

        $tz = $w['timezone'] ?? config('app.timezone');
        $now = Carbon::now($tz);
        $in = $r->check_in_date->toDateString();
        $out = $r->check_out_date->toDateString();
        $c = $w['current'] ?? [];
        $speed = $this->fahrenheit() ? 'mph' : 'km/h';

        $nowRow = $c ? [
            'temp' => (int) round($c['temperature_2m'] ?? 0),
            'feels_like' => (int) round($c['apparent_temperature'] ?? $c['temperature_2m'] ?? 0),
            'humidity' => isset($c['relative_humidity_2m']) ? (int) $c['relative_humidity_2m'] : null,
            'wind' => isset($c['wind_speed_10m']) ? (int) round($c['wind_speed_10m']) : null,
            'wind_label' => isset($c['wind_speed_10m']) ? (int) round($c['wind_speed_10m']) . ' ' . $speed : null,
            'is_day' => (bool) ($c['is_day'] ?? true),
        ] + self::describe((int) ($c['weather_code'] ?? 0), (bool) ($c['is_day'] ?? true)) : null;

        // The next 24 hours, every 3 hours from the coming hour.
        $hours = [];
        $h = $w['hourly'] ?? [];
        foreach ($h['time'] ?? [] as $i => $t) {
            $at = Carbon::parse($t, $tz);
            if ($at->lt($now->copy()->startOfHour()) || $at->gt($now->copy()->addDay())) {
                continue;
            }
            $hours[] = [
                'time' => $at->format('Y-m-d\TH:i'),
                'label' => $at->isSameHour($now) ? 'Now' : $at->format('g A'),
                'temp' => (int) round($h['temperature_2m'][$i] ?? 0),
                'rain_chance' => isset($h['precipitation_probability'][$i]) ? (int) $h['precipitation_probability'][$i] : null,
                'is_day' => (bool) ($h['is_day'][$i] ?? true),
            ] + self::describe((int) ($h['weather_code'][$i] ?? 0), (bool) ($h['is_day'][$i] ?? true));
        }
        $next = array_values(array_filter($hours, fn ($x, $k) => $k % 3 === 0, ARRAY_FILTER_USE_BOTH));

        $days = [];
        $d = $w['daily'];
        foreach ($d['time'] as $i => $date) {
            $day = Carbon::parse($date, $tz);
            $days[] = [
                'date' => $date,
                'label' => $day->isSameDay($now) ? 'Today' : ($day->isSameDay($now->copy()->addDay()) ? 'Tomorrow' : $day->format('D j M')),
                'short' => $day->isSameDay($now) ? 'Today' : $day->format('D'),
                'max' => (int) round($d['temperature_2m_max'][$i] ?? 0),
                'min' => (int) round($d['temperature_2m_min'][$i] ?? 0),
                'rain_chance' => isset($d['precipitation_probability_max'][$i]) ? (int) $d['precipitation_probability_max'][$i] : null,
                'rain_mm' => isset($d['precipitation_sum'][$i]) ? round((float) $d['precipitation_sum'][$i], 1) : null,
                'uv' => isset($d['uv_index_max'][$i]) ? round((float) $d['uv_index_max'][$i], 1) : null,
                'wind_max' => isset($d['wind_speed_10m_max'][$i]) ? (int) round($d['wind_speed_10m_max'][$i]) : null,
                'sunrise' => isset($d['sunrise'][$i]) ? Carbon::parse($d['sunrise'][$i], $tz)->format('g:i A') : null,
                'sunset' => isset($d['sunset'][$i]) ? Carbon::parse($d['sunset'][$i], $tz)->format('g:i A') : null,
                'in_stay' => $date >= $in && $date <= $out,
                'arrival' => $date === $in,
                'departure' => $date === $out,
            ] + self::describe((int) ($d['weather_code'][$i] ?? 0), true);
        }

        $last = end($d['time']);
        $stayNote = match (true) {
            $in > $last => 'The forecast for your dates (' . $r->check_in_date->format('j M') . ' – ' . $r->check_out_date->format('j M') . ') appears here about two weeks before you arrive. For now, this is the weather at the hotel.',
            $out > $last => 'The forecast reaches ' . Carbon::parse($last)->format('D j M') . '; the rest of your stay appears nearer the time.',
            default => null,
        };

        return [
            'available' => true,
            'note' => null,
            'unit' => $this->fahrenheit() ? '°F' : '°C',
            'now' => $nowRow,
            'hours' => $next,
            'days' => $days,
            'tips' => $this->tips($r, $hours, $days, $now),
            'stay_note' => $stayNote,
            'updated_label' => $nowRow && isset($c['time']) ? 'Updated ' . Carbon::parse($c['time'], $tz)->format('g:i A') : null,
            'source' => 'Open-Meteo',
            'source_url' => 'https://open-meteo.com/',
        ];
    }

    /**
     * A few plain sentences on getting around: the next hours, then the guest's arrival
     * and departure days. Each tip is [icon, tone (info|warn|good), text].
     */
    public function tips(Reservation $r, array $hours, array $days, Carbon $now): array
    {
        $tips = [];
        $hot = $this->fahrenheit() ? 92 : 33;
        $car = config('hotel.transport.enabled', true) ? ' or book the hotel’s car' : '';
        $today = $days[0] ?? null;
        $soon = array_slice($hours, 0, 12);

        $storm = collect($soon)->first(fn ($x) => $x['kind'] === 'storm');
        $wet = collect($soon)->first(fn ($x) => in_array($x['kind'], ['rain', 'drizzle', 'storm'], true) || ($x['rain_chance'] ?? 0) >= 60);
        if ($storm) {
            $tips[] = ['icon' => 'storm', 'tone' => 'warn', 'text' => 'Thunderstorms likely ' . self::when($storm) . '. Best to plan indoor time then' . ($car ? $car . ' rather than walk' : '') . '.'];
        } elseif ($wet) {
            $tips[] = ['icon' => 'umbrella', 'tone' => 'warn', 'text' => 'Rain likely ' . self::when($wet) . '. Take an umbrella if you’re heading out' . $car . '.'];
        }
        if ($today && $today['max'] >= $hot) {
            $tips[] = ['icon' => 'hot', 'tone' => 'warn', 'text' => 'A hot day, up to ' . $today['max'] . '°. Carry water and keep to the shade around midday.'];
        }
        if ($today && ($today['uv'] ?? 0) >= 8) {
            $tips[] = ['icon' => 'uv', 'tone' => 'info', 'text' => 'Strong sun (UV ' . (int) round($today['uv']) . '). Sunscreen and a hat if you’re out between 11 AM and 3 PM.'];
        }
        if ($today && ($today['wind_max'] ?? 0) >= ($this->fahrenheit() ? 25 : 40)) {
            $tips[] = ['icon' => 'wind', 'tone' => 'warn', 'text' => 'Strong winds today (up to ' . $today['wind_max'] . ($this->fahrenheit() ? ' mph' : ' km/h') . ').'];
        }
        if (collect(array_slice($soon, 0, 6))->contains(fn ($x) => $x['kind'] === 'fog')) {
            $tips[] = ['icon' => 'fog', 'tone' => 'info', 'text' => 'Fog about. Allow extra time on the road.'];
        }

        // The guest's own travel days.
        $upcoming = in_array($r->status, [S::Pending, S::Confirmed], true);
        foreach ($days as $day) {
            if ($upcoming && $day['arrival']) {
                $tips[] = ['icon' => 'arrival', 'tone' => self::wetDay($day) ? 'warn' : 'info', 'text' => 'Your arrival day (' . $day['label'] . '): ' . lcfirst($day['summary']) . ', ' . $day['min'] . '–' . $day['max'] . '°' . (self::wetDay($day) ? '. Pack an umbrella and allow extra time for the journey.' : '.')];
            }
            if ($day['departure'] && ! $day['arrival']) {
                $tips[] = ['icon' => 'departure', 'tone' => self::wetDay($day) ? 'warn' : 'info', 'text' => 'Your check-out day (' . $day['label'] . '): ' . lcfirst($day['summary']) . ', ' . $day['min'] . '–' . $day['max'] . '°' . (self::wetDay($day) ? '. Leave a little earlier for the airport or your next stop.' : '.')];
            }
        }

        if (! $tips && $today && ! self::wetDay($today)) {
            $tips[] = ['icon' => 'good', 'tone' => 'good', 'text' => 'Dry today. A good day to get out and about.'];
        }

        return array_slice($tips, 0, 4);
    }

    private static function wetDay(array $day): bool
    {
        return in_array($day['kind'], ['rain', 'storm', 'drizzle', 'snow'], true) || ($day['rain_chance'] ?? 0) >= 60;
    }

    private static function when(array $hour): string
    {
        return $hour['label'] === 'Now' ? 'now' : 'from about ' . $hour['label'];
    }

    /**
     * A WMO weather code (what Open-Meteo answers) in words, as a kind the apps draw
     * (clear, partly, cloudy, fog, drizzle, rain, snow, storm) and a Remix icon for the website.
     */
    public static function describe(int $code, bool $day = true): array
    {
        [$summary, $kind] = match (true) {
            $code === 0 => [$day ? 'Sunny' : 'Clear', 'clear'],
            $code === 1 => [$day ? 'Mostly sunny' : 'Mostly clear', 'clear'],
            $code === 2 => ['Partly cloudy', 'partly'],
            $code === 3 => ['Cloudy', 'cloudy'],
            in_array($code, [45, 48], true) => ['Fog', 'fog'],
            $code >= 51 && $code <= 57 => ['Drizzle', 'drizzle'],
            in_array($code, [61, 80], true) => ['Light rain', 'rain'],
            in_array($code, [63, 81], true) => ['Rain', 'rain'],
            in_array($code, [65, 82], true) => ['Heavy rain', 'rain'],
            in_array($code, [66, 67], true) => ['Freezing rain', 'rain'],
            ($code >= 71 && $code <= 77) || in_array($code, [85, 86], true) => ['Snow', 'snow'],
            $code >= 95 => ['Thunderstorms', 'storm'],
            default => ['Cloudy', 'cloudy'],
        };
        $icon = match ($kind) {
            'clear' => $day ? 'ri-sun-line' : 'ri-moon-clear-line',
            'partly' => $day ? 'ri-sun-cloudy-line' : 'ri-moon-cloudy-line',
            'cloudy' => 'ri-cloudy-line',
            'fog' => 'ri-mist-line',
            'drizzle' => 'ri-drizzle-line',
            'rain' => $summary === 'Heavy rain' ? 'ri-heavy-showers-line' : 'ri-showers-line',
            'snow' => 'ri-snowy-line',
            'storm' => 'ri-thunderstorms-line',
        };

        return ['code' => $code, 'summary' => $summary, 'kind' => $kind, 'icon' => $icon];
    }
}
