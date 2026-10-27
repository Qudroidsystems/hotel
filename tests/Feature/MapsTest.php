<?php

use App\Enums\ReservationStatus;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Services\Booking\OnlineBookingService;
use App\Services\Maps\MapsService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReservationDemoSeeder;
use Database\Seeders\RoomDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, RoomDemoSeeder::class, ReservationDemoSeeder::class]);
    auth()->logout();
    Notification::fake();
    Hotel::query()->update(['latitude' => 6.4281, 'longitude' => 3.4219]);
});

function useGoogle(): void
{
    config(['hotel.maps.provider' => 'google', 'services.google_maps.server_key' => 'AIzaTESTSERVER', 'services.google_maps.map_key' => 'AIzaTESTMAP']);
}

test('without a key: OpenStreetMap maps and estimated times; no address search', function () {
    Http::fake();
    $maps = app(MapsService::class);
    expect($maps->google())->toBeFalse()
        ->and($maps->tiles()['provider'])->toBe('osm')
        ->and($maps->search('Murtala Muhammed Airport'))->toBe([])
        ->and($maps->route(6.6018, 3.3515, 6.4281, 3.4219)[3])->toBe('estimate');
    $this->getJson('/api/v1/app/config')->assertJsonPath('data.maps.provider', 'osm')->assertJsonPath('data.maps.search', false);
    Http::assertNothingSent();
});

test('with Google: road times with traffic drive arrival sharing, address search works, maps come from Google', function () {
    useGoogle();
    Http::fake([
        'maps.googleapis.com/maps/api/directions/*' => Http::response(['status' => 'OK', 'routes' => [['overview_polyline' => ['points' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@'], 'legs' => [['distance' => ['value' => 21500], 'duration' => ['value' => 2400], 'duration_in_traffic' => ['value' => 3300]]]]]]),
        'maps.googleapis.com/maps/api/place/autocomplete/*' => Http::response(['status' => 'OK', 'predictions' => [['place_id' => 'ChIJairport12345', 'description' => 'Murtala Muhammed International Airport, Ikeja', 'structured_formatting' => ['main_text' => 'Murtala Muhammed International Airport', 'secondary_text' => 'Ikeja, Lagos']]]]),
        'maps.googleapis.com/maps/api/place/details/*' => Http::response(['status' => 'OK', 'result' => ['name' => 'MM Airport', 'formatted_address' => 'Ikeja, Lagos', 'geometry' => ['location' => ['lat' => 6.5774, 'lng' => 3.3212]]]]),
        'tile.googleapis.com/v1/createSession*' => Http::response(['session' => 'SESSION123', 'expiry' => '9999999999']),
    ]);
    $maps = app(MapsService::class);
    expect($maps->tiles()['tiles'])->toContain('tile.googleapis.com')->toContain('session=SESSION123')
        ->and($maps->search('Murtala', 6.4, 3.4)[0]['id'])->toBe('ChIJairport12345')
        ->and($maps->place('ChIJairport12345')[0])->toBe(6.5774)
        ->and(MapsService::decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@'))->toBe([[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]]);

    // Arrival sharing takes Google's time in traffic (55 min), not the estimate.
    $r = Reservation::where('status', ReservationStatus::Confirmed->value)->first();
    $r->forceFill(['check_in_date' => today(), 'check_out_date' => today()->addDay()])->save();
    $token = app(OnlineBookingService::class)->ensureToken($r);
    $a = $this->postJson("/api/v1/guest/stays/{$token}/arrival", ['lat' => 6.6018, 'lng' => 3.3515])->assertOk()->json('data.arrival');
    expect($a['eta_minutes'])->toBe(55);

    // The guest app's search goes through the hotel (the key never leaves the server).
    $this->getJson("/api/v1/guest/stays/{$token}/places?q=Murtala")->assertOk()->assertJsonPath('data.0.text', 'Murtala Muhammed International Airport');
    $this->getJson("/api/v1/guest/stays/{$token}/places/ChIJairport12345")->assertOk()->assertJsonPath('data.lat', 6.5774);
    Http::assertSent(fn ($req) => str_contains($req->url(), 'key=AIzaTESTSERVER') && str_contains($req->url(), 'directions'));
});

test('when Google fails the estimate is used, never an error', function () {
    useGoogle();
    Http::fake(['*' => Http::response(['status' => 'REQUEST_DENIED', 'error_message' => 'API key invalid'], 200)]);
    $maps = app(MapsService::class);
    expect($maps->route(6.6018, 3.3515, 6.4281, 3.4219)[3])->toBe('estimate')
        ->and($maps->tiles()['provider'])->toBe('osm');
});
