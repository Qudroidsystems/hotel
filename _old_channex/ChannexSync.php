<?php

namespace App\Services\Channels;

use App\Enums\BookingSource;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Channels\ChannelBooking;
use App\Models\Channels\ChannelRatePlan;
use App\Models\Channels\ChannelRoomType;
use App\Models\Channels\ChannelSyncLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Services\Mobile\StaffAlerts;
use App\Services\Reservations\AvailabilityService;
use App\Services\Reservations\ReservationService;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Two-way sync with Channex: rooms left and prices go out; bookings, changes and cancellations made on
 * Booking.com, Expedia, Airbnb ... come in as reservations (source: online travel agency).
 */
class ChannexSync
{
    public function __construct(
        private ChannexClient $client,
        private ChannelInventory $inventory,
        private ReservationService $reservations,
        private AvailabilityService $availability,
    ) {
    }

    // ── Out: availability and rates ───────────────────────────────────

    /** Send rooms left for these nights. Answers how many updates went out. */
    public function pushAvailability(Carbon $from, Carbon $to): int
    {
        return $this->push('availability', fn () => $this->inventory->availabilityValues($this->clampFrom($from), $to, $this->client->propertyId()),
            fn ($chunk) => $this->client->availability($chunk), $from, $to);
    }

    /** Send prices and stay rules for these nights. */
    public function pushRates(Carbon $from, Carbon $to): int
    {
        return $this->push('rates', fn () => $this->inventory->rateValues($this->clampFrom($from), $to, $this->client->propertyId()),
            fn ($chunk) => $this->client->restrictions($chunk), $from, $to);
    }

    /** Everything, from today for the number of days set (a year by default). */
    public function pushAll(): array
    {
        $to = today()->addDays($this->daysAhead());

        return ['availability' => $this->pushAvailability(today(), $to), 'rates' => $this->pushRates(today(), $to)];
    }

    public function daysAhead(): int
    {
        return max(30, min(730, (int) config('hotel.channels.channex.days_ahead', 365)));
    }

    // ── In: bookings ──────────────────────────────────────────────────

    /** Fetch new bookings, changes and cancellations, save each, then acknowledge it. Answers how many. */
    public function pull(): int
    {
        if (! $this->client->configured()) {
            return 0;
        }
        try {
            $feed = $this->client->feed();
        } catch (ChannelException $e) {
            ChannelSyncLog::write('channex', 'bookings', false, 0, $e->getMessage());
            throw $e;
        }
        $done = 0;
        foreach ($feed as $revision) {
            $booking = $this->receive($revision);
            try {
                $this->client->ack((string) $revision['id']);
            } catch (ChannelException $e) {
                Log::warning("Channex ack failed for {$revision['id']}: " . $e->getMessage());
            }
            $done += $booking ? 1 : 0;
        }
        if ($done) {
            ChannelSyncLog::write('channex', 'bookings', true, $done, "{$done} booking change(s) received.");
        }

        return $done;
    }

    /** Save a booking revision as received, then book it in. Never throws: failures are kept to look at. */
    public function receive(array $revision): ?ChannelBooking
    {
        $a = $revision['attributes'] ?? $revision;
        $revisionId = (string) ($revision['id'] ?? $a['id'] ?? '');
        if ($revisionId !== '' && ChannelBooking::where('provider', 'channex')->where('revision_id', $revisionId)->exists()) {
            return null;   // already have it (a repeat delivery)
        }
        $booking = ChannelBooking::create([
            'provider' => 'channex', 'external_id' => (string) ($a['booking_id'] ?? $revisionId), 'revision_id' => $revisionId ?: null,
            'ota_name' => $a['ota_name'] ?? null, 'ota_code' => $a['ota_reservation_code'] ?? null,
            'status' => (string) ($a['status'] ?? 'new'), 'payload' => $a, 'received_at' => now(),
        ]);

        return $this->process($booking);
    }

    /** Book in (or change, or cancel) the reservation for a received booking. Used again by "Try again". */
    public function process(ChannelBooking $booking): ChannelBooking
    {
        $a = $booking->payload ?? [];
        $existing = Reservation::where('channel_booking_id', $booking->external_id)->first();
        // Nights whose stock may change: the old dates and the new ones.
        $span = array_values(array_filter([$existing?->check_in_date, $existing?->check_out_date, ...$this->dates($a)]));
        try {
            [$result, $reservation, $overbooked] = match (true) {
                $booking->status === 'cancelled' => $this->cancel($existing, $a),
                $existing !== null               => $this->modify($existing, $a),
                default                          => $this->create($a, $booking->external_id),
            };
            $booking->forceFill(['result' => $result, 'reservation_id' => $reservation?->id, 'overbooked' => $overbooked, 'error' => null, 'processed_at' => now()])->save();
        } catch (Throwable $e) {
            $booking->forceFill(['result' => 'failed', 'reservation_id' => $existing?->id, 'error' => $e->getMessage(), 'processed_at' => now()])->save();
            $reservation = $existing;
            if (! $e instanceof BookingException) {
                Log::error("Channel booking {$booking->id} failed: " . $e->getMessage());
            }
        }
        $this->alert($booking->fresh('reservation.guest'));

        // The stock changed: tell every channel straight away.
        if ($span && $this->client->configured()) {
            try {
                $this->pushAvailability(collect($span)->min()->copy(), collect($span)->max()->copy());
            } catch (Throwable $e) {
                Log::warning('Channex availability push after a booking failed: ' . $e->getMessage());
            }
        }

        return $booking;
    }

    // ------------------------------------------------------------------

    private function create(array $a, string $bookingId): array
    {
        [$in, $out] = $this->dates($a);
        $lines = $this->lines($a);
        $ota = $a['ota_name'] ?? 'Channel';
        $overbooked = $this->overbooks($lines, $in, $out);

        $reservation = DB::transaction(function () use ($a, $in, $out, $lines, $ota, $bookingId) {
            $r = $this->reservations->create($this->guest($a), [
                'check_in_date' => $in->toDateString(), 'check_out_date' => $out->toDateString(),
                'source' => BookingSource::Ota->value, 'status' => ReservationStatus::Confirmed->value,
                'expected_arrival_time' => $this->hour($a['arrival_hour'] ?? null),
                'special_requests' => $a['notes'] ?? null,
                'notes' => $this->note($a),
                'rate_plan_id' => $lines[0]['rate_plan_id'] ?? null,
                'channel' => $ota, 'channel_reference' => $a['ota_reservation_code'] ?? null, 'channel_booking_id' => $bookingId,
                'from_channel' => true,
            ], array_map(fn ($l) => array_intersect_key($l, array_flip(['room_type_id', 'adults', 'children'])), $lines));
            $this->applyAmounts($r, $lines);
            $r->log('channel', "Booked on {$ota}" . (! empty($a['ota_reservation_code']) ? " ({$a['ota_reservation_code']})" : '') . '.');
            Audit::record('channels.booking', "{$ota} booking {$r->reference} for {$r->guest?->full_name}, " . $in->format('j M') . ' – ' . $out->format('j M Y'), $r);

            return $r;
        });

        return ['imported', $reservation, $overbooked];
    }

    private function modify(Reservation $r, array $a): array
    {
        [$in, $out] = $this->dates($a);
        $lines = $this->lines($a);
        $ota = $a['ota_name'] ?? $r->channel ?? 'the channel';
        if (! in_array($r->status, [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
            throw new BookingException("{$ota} changed this booking, but it is already {$r->status->label()} here. Check it with the guest.");
        }

        $overbooked = false;
        DB::transaction(function () use ($r, $a, $in, $out, $lines, $ota, &$overbooked) {
            $r->load('rooms');
            $wanted = collect($lines)->pluck('room_type_id')->sort()->values()->all();
            $have = $r->rooms->pluck('room_type_id')->map(fn ($v) => (int) $v)->sort()->values()->all();

            if ($wanted !== $have) {
                if ($r->status === ReservationStatus::CheckedIn) {
                    throw new BookingException("{$ota} changed the rooms on a booking that is already checked in. Change it at the front desk.");
                }
                $overbooked = $this->overbooks($lines, $in, $out, $r->id);
                $r->rooms()->delete();
                foreach ($lines as $l) {
                    $r->rooms()->create(['room_type_id' => $l['room_type_id'], 'adults' => $l['adults'], 'children' => $l['children'], 'nightly_rates' => [], 'amount' => 0]);
                }
                $r->forceFill(['check_in_date' => $in, 'check_out_date' => $out])->save();
                $r->log('channel', "Rooms changed on {$ota}. Assign rooms again.");
            } elseif (! $r->check_in_date->eq($in) || ! $r->check_out_date->eq($out)) {
                $overbooked = $this->overbooks($lines, $in, $out, $r->id);
                if ($overbooked) {
                    // The channel has already sold it: take the new dates even if it overbooks.
                    $old = $r->check_in_date->format('j M') . ' – ' . $r->check_out_date->format('j M');
                    $r->forceFill(['check_in_date' => $in, 'check_out_date' => $out])->save();
                    $r->log('dates_changed', "Dates changed on {$ota} from {$old} to " . $in->format('j M') . ' – ' . $out->format('j M Y') . '. This overbooks the hotel.');
                } else {
                    $this->reservations->changeDates($r, $in, $out, "Changed on {$ota}");
                }
            }
            $r->forceFill([
                'adults' => collect($lines)->sum('adults'), 'children' => collect($lines)->sum('children'),
                'special_requests' => $a['notes'] ?? $r->special_requests, 'channel_reference' => $a['ota_reservation_code'] ?? $r->channel_reference,
            ])->save();
            $this->applyAmounts($r->fresh('rooms'), $lines);
            $r->log('channel', "Updated from {$ota}.");
        });

        return ['updated', $r->fresh(), $overbooked];
    }

    private function cancel(?Reservation $r, array $a): array
    {
        $ota = $a['ota_name'] ?? $r?->channel ?? 'the channel';
        if (! $r) {
            return ['skipped', null, false];   // cancelled before we ever had it
        }
        if (in_array($r->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)) {
            return ['skipped', $r, false];
        }
        if ($r->status === ReservationStatus::CheckedIn || $r->status === ReservationStatus::CheckedOut) {
            throw new BookingException("{$ota} cancelled this booking, but the guest has already checked in. Check it with the guest.");
        }
        $this->reservations->cancel($r, "Cancelled on {$ota}.");

        return ['cancelled', $r->fresh(), false];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function dates(array $a): array
    {
        $rooms = $a['rooms'] ?? [];
        $in = $a['arrival_date'] ?? collect($rooms)->min('checkin_date');
        $out = $a['departure_date'] ?? collect($rooms)->max('checkout_date');
        if (! $in || ! $out) {
            return [];
        }

        return [Carbon::parse($in)->startOfDay(), Carbon::parse($out)->startOfDay()];
    }

    /** Our room types and rates for the booking's rooms. */
    private function lines(array $a): array
    {
        $rooms = $a['rooms'] ?? [];
        if (! $rooms) {
            throw new BookingException('The booking has no rooms.');
        }
        $types = ChannelRoomType::whereNotNull('external_id')->pluck('room_type_id', 'external_id');
        $plans = ChannelRatePlan::get()->keyBy('external_id');

        return array_map(function ($room) use ($types, $plans, $a) {
            $typeId = $types[(string) ($room['room_type_id'] ?? '')] ?? null;
            if (! $typeId) {
                throw new BookingException(($a['ota_name'] ?? 'The channel') . ' sold a room type that isn’t linked here (Channex room type ' . ($room['room_type_id'] ?? '?') . '). Link it under Channel Manager → Rooms & rates, then try again.');
            }
            $plan = $plans[(string) ($room['rate_plan_id'] ?? '')] ?? null;

            return [
                'room_type_id' => (int) $typeId,
                'rate_plan_id' => $plan?->rate_plan_id,
                'adults'       => max(1, (int) data_get($room, 'occupancy.adults', 1)),
                'children'     => (int) data_get($room, 'occupancy.children', 0) + (int) data_get($room, 'occupancy.infants', 0),
                'amount'       => (float) ($room['amount'] ?? 0),
                'days'         => array_map('floatval', (array) ($room['days'] ?? [])),
            ];
        }, $rooms);
    }

    /** The price the channel sold, night by night, in place of our own quote. */
    private function applyAmounts(Reservation $r, array $lines): void
    {
        $r->loadMissing('rooms');
        foreach ($r->rooms->values() as $i => $line) {
            $l = $lines[$i] ?? null;
            if (! $l) {
                continue;
            }
            $nights = Reservation::nightsBetween($r->check_in_date, $r->check_out_date);
            $days = $l['days'] ? array_intersect_key($l['days'], array_flip($nights)) : [];
            if (! $days && $l['amount'] > 0 && $nights) {
                $each = round($l['amount'] / count($nights), 2);
                $days = array_fill_keys($nights, $each);
            }
            if ($days) {
                ksort($days);
                $line->update(['nightly_rates' => $days, 'amount' => round(array_sum($days), 2)]);
            }
        }
        $r->recalculateTotals();
    }

    /** True when there aren't enough rooms left for these lines (the channel sold more than we have). */
    private function overbooks(array $lines, Carbon $in, Carbon $out, ?int $exclude = null): bool
    {
        foreach (collect($lines)->countBy('room_type_id') as $typeId => $n) {
            if ($this->availability->availableForType((int) $typeId, $in, $out, $exclude) < $n) {
                return true;
            }
        }

        return false;
    }

    private function guest(array $a): Guest
    {
        $c = $a['customer'] ?? [];
        $email = mb_strtolower(trim((string) ($c['mail'] ?? '')));
        $phone = trim((string) ($c['phone'] ?? ''));
        $guest = $email !== '' ? Guest::whereRaw('lower(email) = ?', [$email])->first() : null;
        if ($guest) {
            if (! $guest->phone && $phone !== '') {
                $guest->forceFill(['phone' => $phone])->save();
            }

            return $guest;
        }
        $first = trim((string) ($c['name'] ?? '')) ?: 'Guest';
        $last = trim((string) ($c['surname'] ?? '')) ?: '(' . ($a['ota_name'] ?? 'channel') . ')';

        return Guest::create(['first_name' => mb_substr($first, 0, 60), 'last_name' => mb_substr($last, 0, 60), 'email' => $email ?: null, 'phone' => $phone ?: null,
            'nationality' => $c['country'] ?? null]);
    }

    private function note(array $a): string
    {
        $bits = ['Booked on ' . ($a['ota_name'] ?? 'a channel') . '.'];
        if (! empty($a['amount'])) {
            $bits[] = 'Channel total ' . ($a['currency'] ?? '') . ' ' . $a['amount'] . '.';
        }
        if (($a['currency'] ?? null) && strtoupper($a['currency']) !== strtoupper((string) config('hotel.currency'))) {
            $bits[] = 'Sold in ' . strtoupper($a['currency']) . ': check the price.';
        }
        if (! empty($a['payment_collect'])) {
            $bits[] = $a['payment_collect'] === 'ota' ? 'Paid to the channel (collect from them).' : 'The guest pays the hotel.';
        }
        if (! empty($a['ota_commission'])) {
            $bits[] = 'Commission ' . $a['ota_commission'] . '.';
        }

        return implode(' ', $bits);
    }

    private function hour(?string $h): ?string
    {
        return $h && preg_match('/^\d{1,2}:\d{2}/', $h) ? substr(str_pad($h, 5, '0', STR_PAD_LEFT), 0, 5) : null;
    }

    private function alert(ChannelBooking $b): void
    {
        $r = $b->reservation;
        $ota = $b->ota_name ?? 'A channel';
        [$title, $level] = match (true) {
            $b->result === 'failed'    => ["{$ota} booking needs attention", 'urgent'],
            $b->overbooked             => ["Overbooked: {$ota} booking " . ($r?->reference ?? ''), 'urgent'],
            $b->result === 'cancelled' => ["{$ota} cancellation: " . ($r?->guest?->full_name ?? $r?->reference), 'high'],
            $b->result === 'updated'   => ["{$ota} booking changed: " . ($r?->guest?->full_name ?? $r?->reference), 'normal'],
            $b->result === 'imported'  => ["New {$ota} booking: " . ($r?->guest?->full_name ?? $r?->reference), 'normal'],
            default                    => [null, null],
        };
        if (! $title) {
            return;
        }
        app(StaffAlerts::class)->notify('View reservations', [
            'title' => $title,
            'body'  => $b->result === 'failed' ? (string) str($b->error)->limit(180)
                : ($r ? $r->check_in_date->format('D j M') . ' – ' . $r->check_out_date->format('D j M') . ' · ' . $r->rooms()->count() . ' room(s)' . ($b->overbooked ? ' · not enough rooms left' : '') : ''),
            'url'   => $r && $b->result !== 'failed' ? route('reservations.show', $r) : route('channels.index'),
            'thread' => "channel-booking:{$b->external_id}", 'level' => $level, 'group' => 'guests',
        ]);
    }

    private function push(string $action, callable $values, callable $send, Carbon $from, Carbon $to): int
    {
        if (! $this->client->configured() || $to->lte(today())) {
            return 0;
        }
        try {
            $all = $values();
            foreach (array_chunk($all, 500) as $chunk) {
                $send($chunk);
            }
            if ($all) {
                ChannelSyncLog::write('channex', $action, true, count($all), ucfirst($action) . ' sent for ' . $this->clampFrom($from)->format('j M') . ' – ' . $to->copy()->subDay()->format('j M Y') . '.');
            }

            return count($all);
        } catch (Throwable $e) {
            ChannelSyncLog::write('channex', $action, false, 0, $e->getMessage());
            throw $e instanceof ChannelException ? $e : new ChannelException($e->getMessage(), 0, $e);
        }
    }

    private function clampFrom(Carbon $from): Carbon
    {
        return $from->copy()->startOfDay()->max(today());   // Channex rejects past dates
    }
}
