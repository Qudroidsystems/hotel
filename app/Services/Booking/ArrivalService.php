<?php

namespace App\Services\Booking;

use App\Enums\ReservationStatus as S;
use App\Exceptions\BookingException;
use App\Models\Booking\GuestArrival;
use App\Models\HousekeepingTask;
use App\Models\Reservation;
use App\Services\Mobile\StaffAlerts;
use App\Support\Tenancy\CurrentHotel;

/**
 * Arrival sharing. A guest on the way turns it on in the guest app: the app
 * opens directions to the hotel and sends where they are every minute or so.
 * Reception and housekeeping hear "on the way — about 25 min", again when the
 * guest is close, and when they reach the door, so the room is ready and
 * someone meets them. Only the latest position is kept; it is wiped when
 * sharing stops (by the guest, at check-in, on arrival, or after a while).
 */
class ArrivalService
{
    public const NEAR_MINUTES = 15;      // "almost here" alert
    public const NEAR_METRES = 3000;
    public const HERE_METRES = 250;      // at the hotel
    public const STALE_MINUTES = 30;     // no news from the phone: stop
    public const MAX_HOURS = 8;

    public function enabled(): bool
    {
        return (bool) config('hotel.booking.arrival_sharing', true) && $this->hotelPoint() !== null;
    }

    /** [lat, lng] of the current hotel, or null when it isn't set (Settings → Hotels). */
    public function hotelPoint(): ?array
    {
        $id = CurrentHotel::get()->id();
        $h = $id ? \App\Models\Hotel::whereKey($id)->first(['id', 'latitude', 'longitude']) : null;

        return $h && $h->latitude !== null && $h->longitude !== null && ($h->latitude != 0 || $h->longitude != 0) ? [(float) $h->latitude, (float) $h->longitude] : null;
    }

    /** Why this stay can't share its arrival now, or null when it can. */
    public function blocker(Reservation $r): ?string
    {
        if (! $this->enabled()) {
            return 'Arrival sharing isn’t available at this hotel.';
        }
        if (! in_array($r->status, [S::Pending, S::Confirmed], true)) {
            return $r->status === S::CheckedIn ? 'You’ve checked in — welcome!' : 'Only for a stay you’re on your way to.';
        }
        if ($r->check_in_date->gt(today()->addDay()) || $r->check_out_date->lte(today())) {
            return 'You can share your arrival from the day before you arrive.';
        }

        return null;
    }

    public function current(Reservation $r): ?GuestArrival
    {
        return GuestArrival::where('reservation_id', $r->id)->latest('id')->first();
    }

    /** Turn sharing on (or send a new position): answers the arrival. */
    public function update(Reservation $r, float $lat, float $lng, ?float $accuracy = null, ?float $speed = null): GuestArrival
    {
        if ($why = $this->blocker($r)) {
            throw new BookingException($why);
        }
        if (abs($lat) > 90 || abs($lng) > 180) {
            throw new BookingException('That location doesn’t look right.');
        }
        [$hLat, $hLng] = $this->hotelPoint();
        $distance = (int) round($this->metres($lat, $lng, $hLat, $hLng));
        // Google (when set up) times the real road with traffic; otherwise an estimate from the distance.
        $maps = app(\App\Services\Maps\MapsService::class);
        $eta = $maps->google() && $distance > self::HERE_METRES ? $maps->minutes($lat, $lng, $hLat, $hLng, $speed) : $this->eta($distance, $speed);

        $a = $this->current($r);
        $new = ! $a || ! $a->isSharing();
        if ($new) {
            $a = GuestArrival::create(['reservation_id' => $r->id, 'status' => 'sharing', 'started_at' => now(), 'start_distance_m' => max($distance, 1)]);
        }
        $a->fill([
            'latitude' => round($lat, 7), 'longitude' => round($lng, 7), 'accuracy_m' => $accuracy !== null ? (int) min(65000, max(0, $accuracy)) : null,
            'distance_m' => $distance, 'eta_minutes' => $eta, 'last_seen_at' => now(),
        ]);
        if ($distance > (int) $a->start_distance_m) {
            $a->start_distance_m = $distance;   // went the other way first: the progress bar starts from the furthest point
        }
        $a->save();

        $r->loadMissing('guest', 'rooms.room', 'rooms.roomType');
        if ($new) {
            $r->log('arrival', "Guest started sharing their arrival: {$a->distance_label} away, about {$a->eta_label}.");
            $this->tell($r, $a, 'is on the way', 'normal');
            $this->prepareRoom($r, $a);
        }
        if ($distance <= self::HERE_METRES) {
            if (! $a->here_alerted_at) {
                $a->forceFill(['here_alerted_at' => now()])->save();
                $this->tell($r, $a, 'is at the hotel', 'urgent');
                $r->log('arrival', 'Guest reached the hotel (arrival sharing).');
            }
            $this->stop($a, 'arrived');
        } elseif (! $a->near_alerted_at && ($eta <= self::NEAR_MINUTES || $distance <= self::NEAR_METRES) && ! $new) {
            $a->forceFill(['near_alerted_at' => now()])->save();
            $this->tell($r, $a, 'is almost here', 'urgent');
            $this->prepareRoom($r, $a);
        }

        return $a->fresh();
    }

    public function stopFor(Reservation $r, string $reason = 'guest'): void
    {
        if (($a = $this->current($r)) && $a->isSharing()) {
            $this->stop($a, $reason);
        }
    }

    /** Stop and forget where they were. */
    public function stop(GuestArrival $a, string $reason): void
    {
        $a->forceFill([
            'status' => $reason === 'arrived' ? 'arrived' : 'stopped', 'stop_reason' => $reason, 'stopped_at' => now(),
            'latitude' => null, 'longitude' => null, 'accuracy_m' => null,
        ])->save();
    }

    /** Scheduler: phones that went quiet, or sharing left on too long. */
    public function expire(): int
    {
        $n = 0;
        GuestArrival::where('status', 'sharing')
            ->where(fn ($q) => $q->where('last_seen_at', '<', now()->subMinutes(self::STALE_MINUTES))->orWhere('started_at', '<', now()->subHours(self::MAX_HOURS)))
            ->get()->each(function (GuestArrival $a) use (&$n) {
                $this->stop($a, 'timed_out');
                $n++;
            });

        return $n;
    }

    /** What the guest app and the booking page show. */
    public function forGuest(Reservation $r): array
    {
        $a = $this->current($r);
        $point = $this->hotelPoint();
        $why = $this->blocker($r);
        $sharing = $a && $a->isSharing();

        return [
            'available'  => $why === null,
            'note'       => $why !== null && $point && in_array($r->status, [S::Pending, S::Confirmed], true) ? $why : null,
            'sharing'    => $sharing,
            'status'     => $a?->status,
            'eta_minutes' => $sharing ? $a->eta_minutes : null,
            'eta_label'  => $sharing ? $a->eta_label : null,
            'distance_label' => $sharing ? $a->distance_label : null,
            'progress'   => $sharing && $a->start_distance_m ? round(1 - min(1, $a->distance_m / max(1, $a->start_distance_m)), 3) : null,
            'arrived'    => $a?->status === 'arrived',
            'hotel_lat'  => $point[0] ?? null,
            'hotel_lng'  => $point[1] ?? null,
            'directions' => $point ? [
                'google' => 'https://www.google.com/maps/dir/?api=1&destination=' . $point[0] . ',' . $point[1] . '&travelmode=driving',
                'apple'  => 'https://maps.apple.com/?daddr=' . $point[0] . ',' . $point[1] . '&dirflg=d',
            ] : null,
            'ping_seconds' => 60,
        ];
    }

    /** For staff screens: the guest's way here, or null. */
    public function forStaff(Reservation $r): ?array
    {
        $a = $this->current($r);
        if (! $a || ! in_array($a->status, ['sharing', 'arrived'], true) || ($a->status === 'arrived' && $a->stopped_at?->lt(now()->subHours(2)))) {
            return null;
        }

        return $a->status === 'arrived'
            ? ['state' => 'here', 'label' => 'At the hotel', 'since' => $a->stopped_at?->format('g:i A')]
            : ['state' => 'coming', 'label' => 'On the way · ' . $a->eta_label, 'eta_minutes' => $a->eta_minutes, 'distance' => $a->distance_label, 'updated' => $a->last_seen_at?->diffForHumans(short: true)];
    }

    // ------------------------------------------------------------------

    /** Road minutes from straight-line metres: roads wind (×1.35), towns are slow, then a couple of minutes to park. */
    public function eta(int $metres, ?float $speed = null): int
    {
        $road = $metres * 1.35 / 1000;
        $kmh = $speed !== null && $speed > 3 ? min(90, max(15, $speed * 3.6)) : ($road < 5 ? 20 : ($road < 30 ? 35 : 55));

        return (int) max(0, round($road / $kmh * 60 + ($metres > self::HERE_METRES ? 2 : 0)));
    }

    private function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    private function tell(Reservation $r, GuestArrival $a, string $what, string $level): void
    {
        $rooms = $r->rooms->pluck('room.number')->filter()->implode(', ');
        $type = $r->rooms->pluck('roomType.name')->filter()->unique()->implode(', ');
        $vip = (bool) $r->guest?->is_vip;
        $title = ($r->guest?->full_name ?? 'A guest') . ($vip ? ' (VIP)' : '') . " {$what}";
        $body = ($what === 'is at the hotel' ? 'Arrived just now' : "About {$a->eta_label} away ({$a->distance_label})")
            . ' · ' . ($rooms ? "room {$rooms}" : "{$type} — no room assigned yet") . " · {$r->reference}";
        $data = ['title' => $title, 'body' => $body, 'group' => 'guests', 'level' => $vip ? 'urgent' : $level, 'url' => route('reservations.show', $r), 'thread' => "arrival:{$r->id}"];
        $alerts = app(StaffAlerts::class);
        $alerts->notify('Check in guests', $data);
        if ($rooms && $what !== 'is at the hotel') {
            $alerts->notify('Manage housekeeping', $data);
        }
    }

    /** The guest's room should be ready: their open clean goes to the top of the list. */
    private function prepareRoom(Reservation $r, GuestArrival $a): void
    {
        $ids = $r->rooms->pluck('room_id')->filter();
        if ($ids->isEmpty()) {
            return;
        }
        $at = now()->addMinutes((int) $a->eta_minutes)->format('g:i A');
        HousekeepingTask::open()->whereIn('room_id', $ids)->get()->each(function (HousekeepingTask $t) use ($at) {
            $note = "Guest arriving about {$at}";
            $t->forceFill(['urgent' => true, 'notes' => str_contains((string) $t->notes, 'Guest arriving') ? preg_replace('/Guest arriving about [^·]+/', $note, (string) $t->notes) : trim($note . ($t->notes ? ' · ' . $t->notes : ''))])->save();
        });
    }
}
