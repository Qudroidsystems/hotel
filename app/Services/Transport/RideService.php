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
use App\Models\Transport\VehicleLog;
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
        if ($why = $this->clash($ride, $driver, $vehicle)) {
            throw new BookingException($why);
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
        $d = $this->candidates($ride)->first(fn (Driver $d) => $d->on_duty && $d->free && $d->vehicle && $this->clash($ride, $d, $d->vehicle) === null);
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

    /**
     * start (on my way), arrived, picked_up, complete, decline (give it back), no_show (waited, the guest never came).
     * $extra: km (the car's odometer: at the start, or at the end), cash (what a cash ride's guest paid),
     * at (when it really happened, for a press made with no connection and sent later).
     */
    public function driverAction(Ride $ride, User $user, string $action, ?string $note = null, array $extra = []): string
    {
        $driver = $this->driverFor($user);
        $dispatcher = $user->can('Manage transport');
        if (! $dispatcher && (! $driver || $ride->driver_id !== $driver->id)) {
            throw new AuthorizationException('This ride isn’t yours.');
        }
        $at = $this->happenedAt($extra['at'] ?? null);
        if ($action === 'no_show') {
            return $this->noShow($ride, $user, $note, $at);
        }
        [$from, $to, $stamp, $msg] = match ($action) {
            'start'     => [[Ride::ASSIGNED], Ride::ON_THE_WAY, 'started_at', 'On your way — the guest can see you coming.'],
            'arrived'   => [[Ride::ON_THE_WAY, Ride::ASSIGNED], Ride::ARRIVED, 'arrived_at', 'The guest knows you’re there.'],
            'picked_up' => [[Ride::ARRIVED, Ride::ON_THE_WAY], Ride::ON_TRIP, 'picked_up_at', 'Trip started. Safe driving.'],
            'complete'  => [[Ride::ON_TRIP], Ride::COMPLETED, 'completed_at', 'Ride finished.'],
            'decline'   => [[Ride::ASSIGNED], Ride::REQUESTED, null, 'Given back to the front desk.'],
            default     => throw new BookingException('Unknown action.'),
        };
        $km = isset($extra['km']) && $extra['km'] !== '' && $extra['km'] !== null ? (int) $extra['km'] : null;
        $cash = isset($extra['cash']) && $extra['cash'] !== '' && $extra['cash'] !== null ? round((float) $extra['cash'], 2) : null;
        if ($action === 'complete' && $ride->payment === 'cash' && $cash === null && $ride->fare === null) {
            throw new BookingException('How much cash did the guest pay?');
        }
        DB::transaction(function () use ($ride, $from, $to, $stamp, $action, $note, $user, $at, $km, $cash) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->first();
            if (! in_array($locked->status, $from, true)) {
                throw new BookingException("{$ride->number} has moved on. Pull down to refresh.");
            }
            $fill = ['status' => $to] + ($stamp ? [$stamp => $at] : []) + ($action === 'decline' ? ['driver_id' => null, 'vehicle_id' => null, 'assigned_at' => null] : []);
            if ($km !== null && $action === 'start') {
                $fill['start_km'] = $km;
            }
            if ($km !== null && $action === 'complete') {
                if ($locked->start_km !== null && $km < $locked->start_km) {
                    throw new BookingException("The kilometres at the end ({$km}) can’t be less than at the start ({$locked->start_km}).");
                }
                $fill['end_km'] = $km;
            }
            if ($action === 'complete' && $locked->payment === 'cash') {
                $fill['cash_collected'] = $cash ?? (float) $locked->fare;
            }
            $locked->forceFill($fill)->save();
            if ($action === 'complete') {
                $this->charge($locked);
            }
            $locked->reservation?->log('ride', "Car {$locked->number}: " . (Ride::STATUSES[$to][1] ?? $to) . ($note ? " — {$note}" : '') . " ({$user->name})");
            $ride->setRawAttributes($locked->getAttributes());
        });
        if ($km !== null && $ride->vehicle) {
            $this->odometer($ride->vehicle, $km);
        }
        if ($action === 'decline') {
            $this->tellDispatch($ride->fresh(), "Driver gave back {$ride->number}" . ($note ? ": {$note}" : ''), 'urgent');
        }
        if ($action === 'arrived' && $ride->toHotel() === false) {
            // Leaving the hotel: the front desk can tell the guest their car is at the door.
            $this->tellDispatch($ride->fresh(), "Car for {$ride->guest_name} is at the entrance", 'normal');
        }
        if ($action === 'complete' && $ride->payment === 'cash' && (float) $ride->cash_collected > 0) {
            $msg .= ' Hand the ' . $this->money((float) $ride->cash_collected) . ' cash in at the front desk.';
        }

        return $msg;
    }

    /** A time the phone says something happened: used when it is believable (the last 12 hours), else now. */
    public function happenedAt(mixed $at): Carbon
    {
        if (! $at) {
            return now();
        }
        try {
            $t = Carbon::parse($at)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return now();
        }

        return $t->gt(now()) || $t->lt(now()->subHours(12)) ? now() : $t;
    }

    /** The driver waited at the pick-up and the guest never came. */
    private function noShow(Ride $ride, User $user, ?string $note, Carbon $at): string
    {
        $wait = (int) config('hotel.transport.no_show_wait_minutes', 15);
        DB::transaction(function () use ($ride, $user, $note, $at, $wait) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->first();
            if ($locked->status !== Ride::ARRIVED) {
                throw new BookingException('Mark yourself as arrived at the pick-up first.');
            }
            $waited = (int) floor($locked->arrived_at ? abs($locked->arrived_at->diffInSeconds($at)) / 60 : 0);
            if ($waited < $wait && ! $user->can('Manage transport')) {
                throw new BookingException("Wait at least {$wait} minutes and call the guest first ({$waited} so far).");
            }
            $locked->forceFill(['status' => Ride::CANCELLED, 'cancelled_at' => $at, 'no_show' => true,
                'cancel_reason' => mb_substr('The guest didn’t come (driver waited ' . $waited . ' min)' . ($note ? ": {$note}" : ''), 0, 255)])->save();
            $fee = (float) config('hotel.transport.no_show_fee', 0);
            if ($fee > 0 && $locked->reservation && $locked->payment === 'room') {
                $this->folios->postCharge($this->folios->forReservation($locked->reservation), ChargeType::Service, mb_substr("No-show: car {$locked->number}", 0, 255), 1, $fee, today(),
                    ['source_type' => Ride::class, 'source_id' => $locked->id, 'idempotency_key' => "ride-noshow:{$locked->id}"]);
            }
            $locked->reservation?->log('ride', "Car {$locked->number}: guest didn’t come — {$locked->cancel_reason} ({$user->name})");
            Audit::record('transport.ride_no_show', "{$locked->number}: no-show after {$waited} min", $locked, by: $user);
            $ride->setRawAttributes($locked->getAttributes());
        });
        $this->tellDispatch($ride->fresh(), 'Guest didn’t come', 'normal');

        return 'Marked as a no-show. You’re free for the next ride.';
    }

    /**
     * Why this driver and car can't take this ride, or null when they can: papers run out, out of service,
     * not enough seats, or already on another ride around that time.
     */
    public function clash(Ride $ride, Driver $driver, Vehicle $vehicle): ?string
    {
        if ($driver->is_active === false) {
            return "{$driver->name} is no longer an active driver.";
        }
        if ($driver->licenceExpired()) {
            return "{$driver->name}’s driving licence ran out on " . $driver->licence_expires->format('j M Y') . '.';
        }
        if ($vehicle->is_active === false || ($vehicle->status ?? 'available') !== 'available') {
            return "{$vehicle->label} is out of service.";
        }
        if ($papers = $vehicle->expiredPapers()) {
            return "{$vehicle->label}: " . implode(' and ', $papers) . ' ran out. Renew it before the car carries guests.';
        }
        if ($vehicle->seats && $ride->passengers > $vehicle->seats) {
            return "{$vehicle->label} has {$vehicle->seats} seats; this ride is for {$ride->passengers}.";
        }
        $gap = (int) config('hotel.transport.clash_minutes', 90);
        $at = $ride->pickup_at ?? now();
        $busy = Ride::whereKeyNot($ride->id)->whereIn('status', Ride::ACTIVE)
            ->where(fn ($q) => $q->where('vehicle_id', $vehicle->id)->orWhere('driver_id', $driver->id))
            ->get()
            ->first(fn (Ride $o) => in_array($o->status, [Ride::ON_THE_WAY, Ride::ARRIVED, Ride::ON_TRIP], true)
                ? $at->lt(now()->addMinutes($gap))
                : abs(($o->pickup_at ?? $o->assigned_at ?? now())->diffInMinutes($at)) < $gap);
        if ($busy) {
            return ($busy->vehicle_id === $vehicle->id ? $vehicle->label : $driver->name) . " is on {$busy->number} ({$busy->when_label}) around then.";
        }

        return null;
    }

    /** Keep the car's kilometres (they only go up) and say when it is due a service. */
    public function odometer(Vehicle $vehicle, int $km): void
    {
        if ($km <= 0 || ($vehicle->odometer_km !== null && $km <= $vehicle->odometer_km) || $km > 2000000) {
            return;
        }
        $wasDue = $vehicle->serviceDue();
        $vehicle->forceFill(['odometer_km' => $km])->save();
        if (! $wasDue && ($due = $vehicle->serviceDue())) {
            app(StaffAlerts::class)->notify('Manage transport', [
                'title' => "{$vehicle->label}: {$due}", 'body' => 'Now at ' . number_format($km) . ' km. Book the service.',
                'group' => 'work', 'level' => 'normal', 'url' => 'transport', 'thread' => "vehicle:{$vehicle->id}",
            ]);
        }
    }

    /** Cash a driver took from a guest, handed in at the desk: it goes on the guest's bill as paid, in this person's till. */
    public function cashHandedIn(Ride $ride, User $by): string
    {
        if ((float) $ride->cash_collected <= 0 || $ride->cash_handed_at) {
            throw new BookingException("No cash is waiting to be handed in for {$ride->number}.");
        }
        DB::transaction(function () use ($ride, $by) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->first();
            if ($locked->cash_handed_at) {
                throw new BookingException("{$ride->number}’s cash was already handed in.");
            }
            $amount = (float) $locked->cash_collected;
            if ($locked->reservation && ! $locked->folio_charge_id) {
                $folio = $this->folios->forReservation($locked->reservation);
                $charge = $this->folios->postCharge($folio, ChargeType::Service, mb_substr("Car {$locked->number}: {$locked->route_label}", 0, 255), 1, $amount, today(),
                    ['source_type' => Ride::class, 'source_id' => $locked->id, 'idempotency_key' => "ride:{$locked->id}"]);
                $this->folios->receivePayment($folio, \App\Enums\PaymentKind::Payment, \App\Enums\PaymentMethod::Cash, $amount, $locked->number,
                    'Paid in cash to driver ' . ($locked->driver?->name ?? ''), ['idempotency_key' => "ride-cash:{$locked->id}"]);
                $locked->folio_charge_id = $charge->id;
            }
            $locked->forceFill(['cash_handed_at' => now(), 'cash_received_by' => $by->id])->save();
            Audit::record('transport.cash_handed_in', "Cash {$this->money($amount)} for {$locked->number} handed in by " . ($locked->driver?->name ?? 'the driver'), $locked, by: $by);
            $ride->setRawAttributes($locked->getAttributes());
        });

        return $this->money((float) $ride->cash_collected) . ' received for ' . $ride->number . '.';
    }

    /**
     * A driver's check of their car before a shift. $checks: [item => passed]. A car marked not safe is
     * taken out of service until a manager puts it back.
     */
    public function vehicleCheck(Driver $driver, Vehicle $vehicle, array $checks, ?int $km, bool $safe, ?string $notes, ?\Illuminate\Http\UploadedFile $photo = null, ?string $offlineId = null): VehicleLog
    {
        if ($offlineId && ($done = VehicleLog::where('offline_id', $offlineId)->first())) {
            return $done;
        }
        $list = collect(VehicleLog::CHECKS)->mapWithKeys(fn ($label, $key) => [$key => (bool) ($checks[$key] ?? false)])->all();
        $ok = $safe && ! in_array(false, $list, true);
        $log = VehicleLog::create([
            'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'user_id' => $driver->user_id, 'kind' => 'check',
            'odometer_km' => $km, 'checklist' => $list, 'ok' => $ok, 'notes' => $notes ? mb_substr($notes, 0, 500) : null,
            'photo_path' => $photo && $photo->isValid() ? $photo->store('transport', 'public') : null, 'offline_id' => $offlineId,
        ]);
        $vehicle->forceFill(['checked_at' => now()] + (! $safe ? ['status' => 'out_of_service'] : []))->save();
        if ($km) {
            $this->odometer($vehicle, $km);
        }
        if (! $ok) {
            app(StaffAlerts::class)->notify('Manage transport', [
                'title' => ($safe ? "{$vehicle->label}: check found problems" : "{$vehicle->label} taken off the road"),
                'body' => "{$driver->name}: " . (implode(', ', $log->failed) ?: 'not safe to drive') . ($notes ? " — {$notes}" : ''),
                'group' => 'work', 'level' => $safe ? 'normal' : 'urgent', 'url' => 'transport', 'thread' => "vehicle:{$vehicle->id}",
            ]);
        }
        Audit::record('transport.vehicle_check', "{$driver->name} checked {$vehicle->label}" . ($ok ? ': all good' : ': ' . (implode(', ', $log->failed) ?: 'not safe')), $vehicle);

        return $log;
    }

    /** Has this driver checked this car within the last 12 hours? */
    public function checkedRecently(Driver $driver, Vehicle $vehicle): bool
    {
        return VehicleLog::where('vehicle_id', $vehicle->id)->where('driver_id', $driver->id)->where('kind', 'check')->where('created_at', '>=', now()->subHours(12))->exists();
    }

    /** A fill-up, a service or a problem, from the driver's phone or the fleet page. */
    public function logVehicle(Vehicle $vehicle, string $kind, array $d, ?User $by, ?Driver $driver = null, ?\Illuminate\Http\UploadedFile $photo = null): VehicleLog
    {
        if (! empty($d['offline_id']) && ($done = VehicleLog::where('offline_id', $d['offline_id'])->first())) {
            return $done;
        }
        abort_unless(array_key_exists($kind, VehicleLog::KINDS) && $kind !== 'check', 422);
        $log = VehicleLog::create([
            'vehicle_id' => $vehicle->id, 'driver_id' => $driver?->id, 'user_id' => $by?->id, 'kind' => $kind,
            'odometer_km' => isset($d['km']) ? (int) $d['km'] : null, 'litres' => $d['litres'] ?? null, 'cost' => $d['cost'] ?? null,
            'ok' => $kind !== 'issue', 'notes' => isset($d['notes']) ? mb_substr((string) $d['notes'], 0, 500) : null,
            'photo_path' => $photo && $photo->isValid() ? $photo->store('transport', 'public') : null, 'offline_id' => $d['offline_id'] ?? null,
        ]);
        if ($kind === 'service') {
            $km = isset($d['km']) ? (int) $d['km'] : $vehicle->odometer_km;
            $vehicle->forceFill(['last_service_km' => $km, 'service_due_on' => ! empty($d['next_service_on']) ? Carbon::parse($d['next_service_on'])->toDateString() : null]
                + (! empty($d['back_in_service']) ? ['status' => 'available'] : []))->save();
        }
        if (! empty($d['km'])) {
            $this->odometer($vehicle->fresh(), (int) $d['km']);
        }
        if ($kind === 'issue') {
            app(StaffAlerts::class)->notify('Manage transport', [
                'title' => "{$vehicle->label}: problem reported", 'body' => ($driver?->name ?? $by?->name ?? 'Someone') . ': ' . ($d['notes'] ?? ''),
                'group' => 'work', 'level' => 'urgent', 'url' => 'transport', 'thread' => "vehicle:{$vehicle->id}",
            ]);
        }

        return $log;
    }

    /**
     * Every few minutes: a booked car nobody has been given to soon before the pick-up tells the
     * desk (and is given to a free driver when the hotel lets the system do that); a driver who
     * hasn't set off close to the time is reminded. Daily: papers about to run out.
     */
    public function reminders(?User $system = null): int
    {
        $n = 0;
        $lead = (int) config('hotel.transport.dispatch_lead_minutes', 60);
        Ride::where('status', Ride::REQUESTED)->whereNull('reminded_at')->whereNotNull('pickup_at')->where('pickup_at', '<=', now()->addMinutes($lead))
            ->get()->each(function (Ride $r) use (&$n, $system) {
                $who = $system ?? User::permission('Manage transport')->where('is_disabled', false)->first();
                $given = config('hotel.transport.auto_dispatch', false) && $who ? $this->autoAssign($r, $who) : null;
                $r->forceFill(['reminded_at' => now()])->save();
                $this->tellDispatch($r->fresh(), $given ? "Given to {$given->name} automatically" : 'No driver yet, pick-up ' . $r->pickup_at->format('g:i A'), $given ? 'normal' : 'urgent');
                $n++;
            });
        Ride::where('status', Ride::ASSIGNED)->whereNull('driver_reminded_at')->whereNotNull('pickup_at')->where('pickup_at', '<=', now()->addMinutes(30))
            ->with('driver')->get()->each(function (Ride $r) use (&$n) {
                $r->forceFill(['driver_reminded_at' => now()])->save();
                if ($r->driver?->user_id) {
                    $this->send(User::whereKey($r->driver->user_id)->get(), ['title' => "Time to go: {$r->number}", 'body' => $r->route_label . ' · pick-up ' . $r->pickup_at->format('g:i A'), 'level' => 'urgent'], $r);
                }
                $n++;
            });

        return $n;
    }

    /** Papers and services coming up (daily). Answers what was flagged. */
    public function fleetWarnings(): array
    {
        $out = [];
        foreach (Vehicle::where('is_active', true)->get() as $v) {
            $w = array_merge(array_map(fn ($p) => "{$p} ran out", $v->expiredPapers()), $v->papersEnding(), array_filter([$v->serviceDue()]));
            if ($w) {
                $out[] = $v->label . ': ' . implode(', ', $w);
            }
        }
        foreach (Driver::where('is_active', true)->whereNotNull('licence_expires')->where('licence_expires', '<=', today()->addDays(14))->get() as $d) {
            $out[] = "{$d->name}: licence " . ($d->licenceExpired() ? 'ran out ' : 'ends ') . $d->licence_expires->format('j M');
        }
        if ($out) {
            app(StaffAlerts::class)->notify('Manage transport', [
                'title' => 'Cars and drivers: ' . count($out) . ' thing(s) to sort', 'body' => implode(' · ', array_slice($out, 0, 6)),
                'group' => 'work', 'level' => 'normal', 'url' => 'transport', 'thread' => 'fleet:' . today()->toDateString(),
            ]);
        }

        return $out;
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
        // Cash is put on the bill (as paid) when the driver hands it in; room and card rides go on the bill now.
        if (! in_array($ride->payment, ['room', 'card'], true) || ! $r || (float) $ride->fare <= 0 || $ride->folio_charge_id) {
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
