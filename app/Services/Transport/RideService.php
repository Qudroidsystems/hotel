<?php

namespace App\Services\Transport;

use App\Enums\ChargeType;
use App\Enums\ReservationStatus as S;
use App\Exceptions\BookingException;
use App\Models\Reservation;
use App\Models\Transport\Driver;
use App\Models\Transport\Ride;
use App\Models\Transport\RidePlace;
use App\Models\Transport\Vehicle;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Booking\ArrivalService;
use App\Services\Mobile\StaffAlerts;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The hotel's car service. A guest books a ride (picked up anywhere and
 * brought to the hotel, or taken from the hotel somewhere), sees the price
 * up front when the hotel has one, and follows it live. The front desk
 * assigns a driver and car (or lets the system pick the nearest free one);
 * the driver works it from the staff app — on the way, here, picked up,
 * dropped off — sharing where they are, and the fare goes on the guest's bill.
 */
class RideService
{
    public const MAX_OPEN = 3;

    public function __construct(private ArrivalService $arrivals, private FolioService $folios)
    {
    }

    public function settings(): array
    {
        $t = (array) config('hotel.transport', []);

        return [
            'enabled' => (bool) ($t['enabled'] ?? true),
            'base_fare' => (float) ($t['base_fare'] ?? 2000), 'per_km' => (float) ($t['per_km'] ?? 400),
            'minimum_fare' => (float) ($t['minimum_fare'] ?? 3000), 'round_to' => max(1, (int) ($t['round_to'] ?? 500)),
            'min_notice' => (int) ($t['min_notice_minutes'] ?? 30), 'max_km' => (float) ($t['max_km'] ?? 150),
        ];
    }

    /** Guests can book: switched on and the hotel has at least one driver. */
    public function available(): bool
    {
        return $this->settings()['enabled'] && Driver::where('is_active', true)->exists();
    }

    public function places(): Collection
    {
        return RidePlace::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    /** Why this stay can't book a car, or null. */
    public function blocker(Reservation $r): ?string
    {
        if (! $this->available()) {
            return 'The hotel’s car service isn’t available.';
        }
        if (! in_array($r->status, [S::Pending, S::Confirmed, S::CheckedIn], true) || $r->check_out_date->lt(today())) {
            return 'Cars can be booked for a stay that’s coming up or under way.';
        }

        return null;
    }

    /**
     * The price: a place's set fare, else distance × the per-km rate (with a
     * base and a minimum), or null when the hotel will confirm it.
     * Answers [fare, km].
     */
    public function quote(?RidePlace $place, ?float $lat = null, ?float $lng = null): array
    {
        $s = $this->settings();
        $lat ??= $place?->latitude;
        $lng ??= $place?->longitude;
        $hotel = $this->arrivals->hotelPoint();
        $maps = app(\App\Services\Maps\MapsService::class);
        $km = $hotel && $lat !== null && $lng !== null
            ? ($maps->google() ? round($maps->route($lat, $lng, $hotel[0], $hotel[1])[0] / 1000, 1) : round(self::metres($lat, $lng, $hotel[0], $hotel[1]) * 1.3 / 1000, 1))
            : null;
        if ($place && $place->fare !== null) {
            return [(float) $place->fare, $km];
        }
        if ($km === null) {
            return [null, null];
        }
        if ($km > $s['max_km']) {
            throw new BookingException('That’s further than the hotel’s cars go (' . (int) $s['max_km'] . ' km). Please call the hotel.');
        }
        $fare = max($s['minimum_fare'], $s['base_fare'] + $km * $s['per_km']);

        return [round($fare / $s['round_to']) * $s['round_to'], $km];
    }

    /**
     * A guest books: [direction, place_id | (label, lat, lng), pickup_at|null, passengers, bags, flight, notes, payment].
     */
    public function book(Reservation $r, array $d, string $via = 'app'): Ride
    {
        if ($why = $this->blocker($r)) {
            throw new BookingException($why);
        }
        if (Ride::where('reservation_id', $r->id)->open()->count() >= self::MAX_OPEN) {
            throw new BookingException('You already have ' . self::MAX_OPEN . ' cars booked. Call the hotel to change them.');
        }
        $place = ! empty($d['place_id']) ? RidePlace::where('is_active', true)->find($d['place_id']) : null;
        // An address found with Google's search, or typed: put it on the map so it can be priced and driven to.
        $maps = app(\App\Services\Maps\MapsService::class);
        if (! $place && ! isset($d['lat']) && ! empty($d['google_place_id']) && ($found = $maps->place((string) $d['google_place_id'], $d['session'] ?? null))) {
            [$d['lat'], $d['lng']] = $found;
            $d['label'] = trim((string) ($d['label'] ?? '')) ?: $found[2];
        } elseif (! $place && ! isset($d['lat']) && ! empty($d['label']) && ($found = $maps->geocode((string) $d['label']))) {
            [$d['lat'], $d['lng']] = $found;
        }
        $label = trim((string) ($place?->name ?? ($d['label'] ?? '')));
        if ($label === '') {
            throw new BookingException(($d['direction'] ?? 'to_hotel') === 'to_hotel' ? 'Where should the driver pick you up?' : 'Where are you going?');
        }
        $at = ! empty($d['pickup_at']) ? Carbon::parse($d['pickup_at'])->setTimezone(config('app.timezone')) : null;
        $s = $this->settings();
        if ($at && $at->lt(now()->addMinutes($s['min_notice'] - 1))) {
            $at = null;   // "as soon as possible"
        }
        if ($at && $at->gt(now()->addDays(60))) {
            throw new BookingException('Cars can be booked up to 60 days ahead.');
        }
        [$fare, $km] = $this->quote($place, isset($d['lat']) ? (float) $d['lat'] : null, isset($d['lng']) ? (float) $d['lng'] : null);
        $r->loadMissing('guest');

        $ride = Ride::create([
            'reservation_id' => $r->id, 'guest_id' => $r->guest_id, 'guest_name' => $r->guest?->full_name, 'guest_phone' => $d['phone'] ?? $r->guest?->phone,
            'direction' => ($d['direction'] ?? 'to_hotel') === 'from_hotel' ? 'from_hotel' : 'to_hotel',
            'place_id' => $place?->id, 'place_label' => mb_substr($label, 0, 255),
            'place_lat' => $d['lat'] ?? $place?->latitude, 'place_lng' => $d['lng'] ?? $place?->longitude,
            'pickup_at' => $at, 'passengers' => max(1, min(20, (int) ($d['passengers'] ?? 1))), 'bags' => max(0, min(30, (int) ($d['bags'] ?? 0))),
            'flight' => ! empty($d['flight']) ? mb_strtoupper(mb_substr(trim($d['flight']), 0, 20)) : null,
            'notes' => ! empty($d['notes']) ? mb_substr(trim($d['notes']), 0, 300) : null,
            'distance_km' => $km, 'fare' => $fare, 'payment' => in_array($d['payment'] ?? 'room', ['room', 'cash', 'card'], true) ? ($d['payment'] ?? 'room') : 'room',
            'status' => Ride::REQUESTED, 'via' => $via,
        ]);
        $r->log('ride', "Car {$ride->number} booked: {$ride->route_label}, {$ride->when_label}" . ($fare ? ', ' . $this->money($fare) : ''));
        $this->tellDispatch($ride->fresh(), 'New car booking', 'urgent');

        return $ride->fresh();
    }

    public function cancelByGuest(Ride $ride): void
    {
        if (! in_array($ride->status, [Ride::REQUESTED, Ride::ASSIGNED, Ride::ON_THE_WAY], true)) {
            throw new BookingException($ride->status === Ride::ARRIVED ? 'Your driver is already there — please call the hotel.' : 'This ride can no longer be cancelled.');
        }
        $this->cancel($ride, 'Cancelled by the guest');
    }

    public function cancel(Ride $ride, string $reason, ?User $by = null): void
    {
        if (! $ride->isOpen()) {
            throw new BookingException("{$ride->number} is already finished.");
        }
        $ride->forceFill(['status' => Ride::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => mb_substr($reason, 0, 255)])->save();
        $ride->reservation?->log('ride', "Car {$ride->number} cancelled: {$reason}");
        if ($ride->driver?->user_id) {
            $this->send(User::whereKey($ride->driver->user_id)->get(), ['title' => "{$ride->number} cancelled", 'body' => $ride->route_label . ' · ' . $reason, 'level' => 'urgent'], $ride);
        }
        if ($by) {
            Audit::record('transport.ride_cancelled', "Cancelled car {$ride->number}: {$reason}", $ride, by: $by);
        }
    }

    // ── Dispatch ──────────────────────────────────────────────────────

    /** Drivers for a ride, best first: on duty and free, then closest to the pick-up. */
    public function candidates(Ride $ride): Collection
    {
        [$lat, $lng] = $this->pickupPoint($ride);

        return Driver::where('is_active', true)->with('vehicle')->get()
            ->map(function (Driver $d) use ($lat, $lng) {
                $d->setAttribute('free', $d->isFree());
                $d->setAttribute('km_away', $lat !== null && $d->latitude !== null && $d->seen_at?->gt(now()->subMinutes(30))
                    ? round(self::metres($d->latitude, $d->longitude, $lat, $lng) / 1000, 1) : null);

                return $d;
            })
            ->sortBy(fn ($d) => [$d->on_duty ? 0 : 1, $d->free ? 0 : 1, $d->km_away ?? 9999, $d->name])->values();
    }

    public function assign(Ride $ride, Driver $driver, ?Vehicle $vehicle, User $by, ?float $fare = null): void
    {
        if (! in_array($ride->status, [Ride::REQUESTED, Ride::ASSIGNED], true)) {
            throw new BookingException("{$ride->number} is already under way.");
        }
        $vehicle ??= $driver->vehicle;
        if (! $vehicle) {
            throw new BookingException("Choose a car for {$driver->name}.");
        }
        $previous = $ride->driver;
        $ride->forceFill([
            'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'status' => Ride::ASSIGNED, 'assigned_by' => $by->id, 'assigned_at' => now(),
            'fare' => $fare !== null ? round($fare, 2) : $ride->fare,
        ])->save();
        $ride->reservation?->log('ride', "Car {$ride->number}: {$driver->name}, {$vehicle->label}.");
        Audit::record('transport.ride_assigned', "Car {$ride->number} given to {$driver->name} ({$vehicle->plate})", $ride, by: $by);
        if ($driver->user_id) {
            $this->send(User::whereKey($driver->user_id)->get(), [
                'title' => "New ride {$ride->number}: " . ($ride->toHotel() ? 'pick up ' . $ride->place_label : 'to ' . $ride->place_label),
                'body' => $ride->when_label . ' · ' . $ride->guest_name . ($ride->flight ? ' · flight ' . $ride->flight : '') . " · {$vehicle->plate}",
                'level' => 'urgent',
            ], $ride);
        }
        if ($previous && $previous->id !== $driver->id && $previous->user_id) {
            $this->send(User::whereKey($previous->user_id)->get(), ['title' => "{$ride->number} given to another driver", 'body' => $ride->route_label, 'level' => 'normal'], $ride);
        }
    }

    /** Give it to the best free driver who has a car. Answers the driver, or null when nobody is free. */
    public function autoAssign(Ride $ride, User $by): ?Driver
    {
        $d = $this->candidates($ride)->first(fn (Driver $d) => $d->on_duty && $d->free && $d->vehicle && $d->vehicle->status === 'available' && $d->vehicle->is_active);
        if ($d) {
            $this->assign($ride, $d, $d->vehicle, $by);
        }

        return $d;
    }

    // ── The driver ────────────────────────────────────────────────────

    public function driverFor(User $user): ?Driver
    {
        return Driver::where('user_id', $user->id)->where('is_active', true)->first();
    }

    /** start (on my way), arrived, picked_up, complete, decline (give it back). */
    public function driverAction(Ride $ride, User $user, string $action, ?string $note = null): string
    {
        $driver = $this->driverFor($user);
        $dispatcher = $user->can('Manage transport');
        if (! $dispatcher && (! $driver || $ride->driver_id !== $driver->id)) {
            throw new AuthorizationException('This ride isn’t yours.');
        }
        [$from, $to, $stamp, $msg] = match ($action) {
            'start'     => [[Ride::ASSIGNED], Ride::ON_THE_WAY, 'started_at', 'On your way — the guest can see you coming.'],
            'arrived'   => [[Ride::ON_THE_WAY, Ride::ASSIGNED], Ride::ARRIVED, 'arrived_at', 'The guest knows you’re there.'],
            'picked_up' => [[Ride::ARRIVED, Ride::ON_THE_WAY], Ride::ON_TRIP, 'picked_up_at', 'Trip started. Safe driving.'],
            'complete'  => [[Ride::ON_TRIP], Ride::COMPLETED, 'completed_at', 'Ride finished.'],
            'decline'   => [[Ride::ASSIGNED], Ride::REQUESTED, null, 'Given back to the front desk.'],
            default     => throw new BookingException('Unknown action.'),
        };
        DB::transaction(function () use ($ride, $from, $to, $stamp, $action, $note, $user) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->first();
            if (! in_array($locked->status, $from, true)) {
                throw new BookingException("{$ride->number} has moved on. Pull down to refresh.");
            }
            $locked->forceFill(['status' => $to] + ($stamp ? [$stamp => now()] : []) + ($action === 'decline' ? ['driver_id' => null, 'vehicle_id' => null, 'assigned_at' => null] : []))->save();
            if ($action === 'complete') {
                $this->charge($locked);
            }
            $locked->reservation?->log('ride', "Car {$locked->number}: " . (Ride::STATUSES[$to][1] ?? $to) . ($note ? " — {$note}" : '') . " ({$user->name})");
            $ride->setRawAttributes($locked->getAttributes());
        });
        if ($action === 'decline') {
            $this->tellDispatch($ride->fresh(), "Driver gave back {$ride->number}" . ($note ? ": {$note}" : ''), 'urgent');
        }
        if ($action === 'arrived' && $ride->toHotel() === false) {
            // Leaving the hotel: the front desk can tell the guest their car is at the door.
            $this->tellDispatch($ride->fresh(), "Car for {$ride->guest_name} is at the entrance", 'normal');
        }

        return $msg;
    }

    /** Where the driver is (sent by the staff app while on duty). */
    public function driverLocation(Driver $driver, float $lat, float $lng): ?Ride
    {
        $driver->forceFill(['latitude' => round($lat, 7), 'longitude' => round($lng, 7), 'seen_at' => now()])->save();
        $ride = $driver->activeRide();
        if (! $ride || ! in_array($ride->status, [Ride::ON_THE_WAY, Ride::ARRIVED, Ride::ON_TRIP], true)) {
            return $ride;
        }
        [$tLat, $tLng] = $ride->status === Ride::ON_TRIP ? $this->dropoffPoint($ride) : $this->pickupPoint($ride);
        if ($tLat !== null) {
            $maps = app(\App\Services\Maps\MapsService::class);
            $ride->forceFill(['eta_minutes' => $maps->google() ? $maps->minutes($lat, $lng, $tLat, $tLng) : $this->arrivals->eta((int) round(self::metres($lat, $lng, $tLat, $tLng)))])->save();
        }
        // Bringing the guest to the hotel: the hotel hears they're coming, like the guest's own arrival sharing.
        if ($ride->status === Ride::ON_TRIP && $ride->toHotel() && $ride->reservation && in_array($ride->reservation->status, [S::Pending, S::Confirmed], true)) {
            try {
                $this->arrivals->update($ride->reservation, $lat, $lng);
            } catch (Throwable) {
            }
        }

        return $ride;
    }

    private function charge(Ride $ride): void
    {
        $r = $ride->reservation;
        if ($ride->payment !== 'room' || ! $r || (float) $ride->fare <= 0 || $ride->folio_charge_id) {
            return;
        }
        $charge = $this->folios->postCharge($this->folios->forReservation($r), ChargeType::Service, mb_substr("Car {$ride->number}: {$ride->route_label}", 0, 255), 1, (float) $ride->fare, today(),
            ['source_type' => Ride::class, 'source_id' => $ride->id, 'idempotency_key' => "ride:{$ride->id}"]);
        $ride->forceFill(['folio_charge_id' => $charge->id])->save();
    }

    // ── For the guest ─────────────────────────────────────────────────

    public function forGuest(Reservation $r): array
    {
        $hotel = $this->arrivals->hotelPoint();

        return [
            'available' => $this->blocker($r) === null,
            'note'      => $this->available() ? $this->blocker($r) : null,
            'rides'     => Ride::where('reservation_id', $r->id)->with('driver', 'vehicle')->latest('id')->limit(10)->get()->map(fn (Ride $x) => $this->rideJson($x, $hotel))->values(),
        ];
    }

    public function rideJson(Ride $x, ?array $hotel = null): array
    {
        $hotel ??= $this->arrivals->hotelPoint();
        $live = in_array($x->status, [Ride::ON_THE_WAY, Ride::ARRIVED, Ride::ON_TRIP], true);
        $steps = [Ride::REQUESTED => 0, Ride::ASSIGNED => 1, Ride::ON_THE_WAY => 2, Ride::ARRIVED => 3, Ride::ON_TRIP => 4, Ride::COMPLETED => 5];

        return [
            'id' => $x->id, 'number' => $x->number, 'direction' => $x->direction, 'route' => $x->route_label, 'place' => $x->place_label,
            'when' => $x->when_label, 'pickup_at' => $x->pickup_at?->toIso8601String(),
            'passengers' => $x->passengers, 'bags' => $x->bags, 'flight' => $x->flight, 'notes' => $x->notes,
            'fare_label' => $x->fare !== null ? $this->money((float) $x->fare) : 'Price confirmed by the hotel',
            'payment' => $x->payment,
            'status' => $x->status, 'status_label' => $x->guest_label, 'step' => $steps[$x->status] ?? -1,
            'open' => $x->isOpen(), 'live' => $live,
            'can_cancel' => in_array($x->status, [Ride::REQUESTED, Ride::ASSIGNED, Ride::ON_THE_WAY], true),
            'eta_minutes' => $live ? $x->eta_minutes : null,
            'driver' => $x->driver ? [
                'name' => $x->driver->name, 'initials' => $x->driver->initials, 'phone' => in_array($x->status, Ride::ACTIVE, true) ? $x->driver->phone : null,
                'lat' => $live && $x->driver->seen_at?->gt(now()->subMinutes(10)) ? $x->driver->latitude : null,
                'lng' => $live && $x->driver->seen_at?->gt(now()->subMinutes(10)) ? $x->driver->longitude : null,
                'seen' => $x->driver->seen_at?->diffForHumans(short: true),
            ] : null,
            'car' => $x->vehicle ? ['name' => $x->vehicle->name, 'plate' => $x->vehicle->plate, 'color' => $x->vehicle->color, 'label' => $x->vehicle->label] : null,
            'place_lat' => $x->place_lat, 'place_lng' => $x->place_lng,
            'hotel_lat' => $hotel[0] ?? null, 'hotel_lng' => $hotel[1] ?? null,
            'cancel_reason' => $x->status === Ride::CANCELLED ? $x->cancel_reason : null,
            'path' => $live ? $this->path($x) : [],
        ];
    }

    /** The road the driver will take next (Google only): [[lat, lng], …], else empty and the apps draw a straight line. */
    private function path(Ride $x): array
    {
        $maps = app(\App\Services\Maps\MapsService::class);
        $d = $x->driver;
        if (! $maps->google() || ! $d || $d->latitude === null || ! $d->seen_at?->gt(now()->subMinutes(10))) {
            return [];
        }
        [$tLat, $tLng] = $x->status === Ride::ON_TRIP ? $this->dropoffPoint($x) : $this->pickupPoint($x);

        return $tLat === null ? [] : \App\Services\Maps\MapsService::decode($maps->route((float) $d->latitude, (float) $d->longitude, (float) $tLat, (float) $tLng)[2]);
    }

    // ------------------------------------------------------------------

    /** Directions for the driver: to the pick-up, or once the guest is in, to the drop-off. */
    public function navUrl(Ride $ride): ?string
    {
        [$lat, $lng] = $ride->status === Ride::ON_TRIP ? $this->dropoffPoint($ride) : $this->pickupPoint($ride);
        if ($lat !== null) {
            return "https://www.google.com/maps/dir/?api=1&destination={$lat},{$lng}&travelmode=driving";
        }
        $where = $ride->status === Ride::ON_TRIP && $ride->toHotel() ? config('hotel.name') . ' ' . config('hotel.booking.address') : $ride->place_label;

        return 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode((string) $where) . '&travelmode=driving';
    }

    /** Where the driver collects the guest. */
    public function pickupPoint(Ride $ride): array
    {
        $h = $this->arrivals->hotelPoint();

        return $ride->toHotel() ? [$ride->place_lat, $ride->place_lng] : [$h[0] ?? null, $h[1] ?? null];
    }

    public function dropoffPoint(Ride $ride): array
    {
        $h = $this->arrivals->hotelPoint();

        return $ride->toHotel() ? [$h[0] ?? null, $h[1] ?? null] : [$ride->place_lat, $ride->place_lng];
    }

    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 6371000 * asin(min(1, sqrt($a)));
    }

    public function money(float $v): string
    {
        return config('hotel.currency_symbol') . number_format($v, 0);
    }

    private function tellDispatch(Ride $ride, string $what, string $level): void
    {
        app(StaffAlerts::class)->notify('Manage transport', [
            'title' => "{$what}: {$ride->number}",
            'body'  => "{$ride->route_label} · {$ride->when_label} · {$ride->guest_name}" . ($ride->flight ? " · flight {$ride->flight}" : ''),
            'group' => 'guests', 'level' => $level, 'url' => 'work', 'thread' => "ride:{$ride->id}", 'kind' => 'ride', 'item_id' => $ride->id,
        ]);
    }

    private function send(Collection $users, array $data, Ride $ride): void
    {
        // Straight to the driver: their own phone, even though others can't see it.
        foreach ($users as $u) {
            try {
                $u->notify(new \App\Notifications\StaffAlert($data + ['group' => 'work', 'url' => 'work', 'thread' => "ride:{$ride->id}", 'kind' => 'ride', 'item_id' => $ride->id]));
            } catch (Throwable) {
            }
        }
    }
}
