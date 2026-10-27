<?php

namespace App\Services\Maps;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Maps & directions (Hotel Settings → Maps & Directions).
 *
 * OpenStreetMap (the default) needs nothing: maps from OSM tiles, travel
 * times estimated from the straight-line distance. With Google and a server
 * key, routes and travel times come from Google (with live traffic), and
 * guests can search for an address. A separate "map key" lets the website
 * and the apps draw Google map pictures (Map Tiles API).
 *
 * Every Google call is cached briefly and fails soft: if Google can't be
 * reached the estimate is used, never an error for the guest.
 */
class MapsService
{
    public function google(): bool
    {
        return config('hotel.maps.provider') === 'google' && filled(config('services.google_maps.server_key'));
    }

    /**
     * Road distance and time between two points: [metres, seconds, encoded polyline|null, source].
     * Google when set up (with traffic for "now"), else straight line × 1.35 at town speeds.
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        if ($this->google()) {
            $key = 'maps:route:' . implode(',', array_map(fn ($v) => round($v, 3), [$fromLat, $fromLng, $toLat, $toLng]));
            $hit = Cache::remember($key, 120, function () use ($fromLat, $fromLng, $toLat, $toLng) {
                try {
                    $res = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/directions/json', [
                        'origin' => "{$fromLat},{$fromLng}", 'destination' => "{$toLat},{$toLng}",
                        'mode' => 'driving', 'departure_time' => 'now', 'key' => config('services.google_maps.server_key'),
                    ]);
                    $leg = $res->json('routes.0.legs.0');
                    if ($res->successful() && $res->json('status') === 'OK' && $leg) {
                        return [
                            (int) $leg['distance']['value'],
                            (int) ($leg['duration_in_traffic']['value'] ?? $leg['duration']['value']),
                            $res->json('routes.0.overview_polyline.points'),
                            'google',
                        ];
                    }
                    Log::notice('Google Directions: ' . $res->json('status') . ' ' . $res->json('error_message'));
                } catch (Throwable $e) {
                    Log::notice('Google Directions unreachable: ' . $e->getMessage());
                }

                return null;
            });
            if ($hit) {
                return $hit;
            }
        }
        $straight = self::metres($fromLat, $fromLng, $toLat, $toLng);
        $road = $straight * 1.35;
        $kmh = $road < 5000 ? 20 : ($road < 30000 ? 35 : 55);

        return [(int) round($road), (int) round($road / 1000 / $kmh * 3600), null, 'estimate'];
    }

    /** Minutes by road (rounded up), the guest's speed used when Google isn't. */
    public function minutes(float $fromLat, float $fromLng, float $toLat, float $toLng, ?float $speed = null): int
    {
        [$m, $s, , $source] = $this->route($fromLat, $fromLng, $toLat, $toLng);
        if ($source === 'estimate' && $speed !== null && $speed > 3) {
            $s = $m / min(25, max(4.2, $speed));   // the phone's own speed, between 15 and 90 km/h
        }

        return (int) max(0, ceil($s / 60));
    }

    /**
     * Address search for pick-ups (Google Places autocomplete), near [$lat, $lng].
     * Answers [{id, text, secondary}] — empty without Google.
     */
    public function search(string $q, ?float $lat = null, ?float $lng = null, ?string $session = null): array
    {
        $q = trim($q);
        if (! $this->google() || mb_strlen($q) < 3) {
            return [];
        }

        return Cache::remember('maps:ac:' . md5($q . '|' . round((float) $lat, 1) . ',' . round((float) $lng, 1)), 600, function () use ($q, $lat, $lng, $session) {
            try {
                $res = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/place/autocomplete/json', array_filter([
                    'input' => $q, 'key' => config('services.google_maps.server_key'), 'sessiontoken' => $session,
                    'location' => $lat !== null && $lng !== null ? "{$lat},{$lng}" : null, 'radius' => $lat !== null ? 50000 : null,
                    'components' => ($cc = config('hotel.maps.country')) ? 'country:' . $cc : null,
                ]));

                return collect($res->json('predictions') ?? [])->take(6)->map(fn ($p) => [
                    'id' => $p['place_id'], 'text' => $p['structured_formatting']['main_text'] ?? $p['description'],
                    'secondary' => $p['structured_formatting']['secondary_text'] ?? null,
                ])->values()->all();
            } catch (Throwable $e) {
                Log::notice('Google Places unreachable: ' . $e->getMessage());

                return [];
            }
        });
    }

    /** A search result's position: [lat, lng, label] or null. */
    public function place(string $placeId, ?string $session = null): ?array
    {
        if (! $this->google() || ! preg_match('/^[A-Za-z0-9_-]{10,300}$/', $placeId)) {
            return null;
        }

        return Cache::remember('maps:place:' . $placeId, 86400, function () use ($placeId, $session) {
            try {
                $res = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/place/details/json', array_filter([
                    'place_id' => $placeId, 'fields' => 'geometry,name,formatted_address', 'key' => config('services.google_maps.server_key'), 'sessiontoken' => $session,
                ]));
                $loc = $res->json('result.geometry.location');

                return $loc ? [(float) $loc['lat'], (float) $loc['lng'], trim(($res->json('result.name') ?? '') . ', ' . ($res->json('result.formatted_address') ?? ''), ', ')] : null;
            } catch (Throwable $e) {
                return null;
            }
        });
    }

    /** A place for a typed address: [lat, lng] or null (Google Geocoding). */
    public function geocode(string $address): ?array
    {
        if (! $this->google() || mb_strlen(trim($address)) < 4) {
            return null;
        }

        return Cache::remember('maps:geo:' . md5($address), 86400, function () use ($address) {
            try {
                $loc = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/geocode/json', ['address' => $address, 'key' => config('services.google_maps.server_key')])
                    ->json('results.0.geometry.location');

                return $loc ? [(float) $loc['lat'], (float) $loc['lng']] : null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * How to draw maps (website and apps): {provider, tiles, attribution, max_zoom}.
     * Google pictures need a Map Tiles API session, made here and kept for a day.
     */
    public function tiles(): array
    {
        $osm = ['provider' => 'osm', 'tiles' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', 'attribution' => '© OpenStreetMap contributors', 'max_zoom' => 19];
        $key = config('services.google_maps.map_key');
        if (config('hotel.maps.provider') !== 'google' || blank($key)) {
            return $osm;
        }
        $session = Cache::remember('maps:tiles-session:' . md5($key), 86400, function () use ($key) {
            try {
                $res = Http::timeout(6)->post('https://tile.googleapis.com/v1/createSession?key=' . urlencode($key), ['mapType' => 'roadmap', 'language' => 'en-GB', 'region' => 'NG']);

                return $res->successful() ? $res->json('session') : null;
            } catch (Throwable) {
                return null;
            }
        });
        if (! $session) {
            Cache::forget('maps:tiles-session:' . md5($key));

            return $osm;
        }

        return ['provider' => 'google', 'tiles' => 'https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}?session=' . $session . '&key=' . $key, 'attribution' => 'Map data © Google', 'max_zoom' => 20];
    }

    /** Decode a Google encoded polyline into [[lat, lng], …] (for the apps and the website map). */
    public static function decode(?string $encoded): array
    {
        if (! $encoded) {
            return [];
        }
        $points = [];
        $i = 0;
        $lat = $lng = 0;
        $len = strlen($encoded);
        while ($i < $len) {
            foreach (['lat', 'lng'] as $axis) {
                $shift = $result = 0;
                do {
                    $b = ord($encoded[$i++]) - 63;
                    $result |= ($b & 0x1f) << $shift;
                    $shift += 5;
                } while ($b >= 0x20 && $i < $len);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                $$axis += $delta;
            }
            $points[] = [round($lat / 1e5, 5), round($lng / 1e5, 5)];
        }

        return $points;
    }

    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 6371000 * asin(min(1, sqrt($a)));
    }
}
