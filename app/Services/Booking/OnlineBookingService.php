<?php

namespace App\Services\Booking;

use App\Enums\BookingSource;
use App\Enums\ChargeType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Booking\OnlinePayment;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Billing\FolioService;
use App\Services\Reservations\AvailabilityService;
use App\Services\Reservations\ReservationService;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Online booking and the guest's own booking page (spec §4, §5). Uses the same
 * availability, rates and reservation rules as the front desk, so a room sold
 * online can't be sold twice. Bookings that need a deposit are held as
 * Pending until it is paid, then confirmed automatically.
 */
class OnlineBookingService
{
    public function __construct(
        private AvailabilityService $availability,
        private ReservationService $reservations,
        private FolioService $folios,
        private PaystackService $paystack,
    ) {
    }

    // ── Search & book ─────────────────────────────────────────────────

    /** Check search input; returns [in, out, adults, children, rooms] or throws. */
    public function criteria(array $q): array
    {
        try {
            $in = Carbon::parse($q['check_in'] ?? '')->startOfDay();
            $out = Carbon::parse($q['check_out'] ?? '')->startOfDay();
        } catch (Throwable) {
            throw new BookingException('Choose your check-in and check-out dates.');
        }
        $adults = max(1, min(20, (int) ($q['adults'] ?? 1)));
        $children = max(0, min(20, (int) ($q['children'] ?? 0)));
        $rooms = max(1, min((int) config('hotel.booking.max_rooms', 4), (int) ($q['rooms'] ?? 1)));
        $nights = $in->diffInDays($out, false);

        if ($in->lt(today())) {
            throw new BookingException('Check-in can’t be in the past.');
        }
        if ($nights < 1) {
            throw new BookingException('Check-out must be at least one night after check-in.');
        }
        if ($nights > (int) config('hotel.booking.max_nights', 30)) {
            throw new BookingException('Online bookings are for up to ' . config('hotel.booking.max_nights') . ' nights. Please contact us for longer stays.');
        }
        if ($in->gt(today()->addDays((int) config('hotel.booking.advance_days', 365)))) {
            throw new BookingException('We take online bookings up to ' . config('hotel.booking.advance_days') . ' days ahead.');
        }
        if ($adults < $rooms) {
            throw new BookingException('Each room needs at least one adult.');
        }

        return [$in, $out, $adults, $children, $rooms];
    }

    /**
     * Room types that can take this party for these dates, cheapest first. Each has its rates: the standard
     * rate and every rate plan sold online that fits the stay (a promo code unlocks its plan), cheapest first.
     */
    public function search(Carbon $in, Carbon $out, int $adults, int $children, int $rooms, ?string $promo = null): Collection
    {
        $perRoomAdults = (int) ceil($adults / $rooms);
        $perRoomChildren = (int) ceil($children / $rooms);
        $engine = app(\App\Services\Rates\RateService::class);

        return $this->availability->summary($in, $out)
            ->filter(fn ($row) => $row['type']->is_active)
            ->map(function ($row) use ($rooms, $perRoomAdults, $perRoomChildren, $engine, $in, $out, $promo) {
                $type = $row['type'];
                $fits = $perRoomAdults <= (int) $type->max_adults && $perRoomChildren <= (int) $type->max_children;
                $restriction = $engine->restriction($type, $in, $out);

                $rates = collect([(object) [
                    'plan' => null, 'key' => 'standard', 'name' => 'Room only', 'inclusions' => null, 'non_refundable' => false, 'promo' => false,
                    'nights' => $row['nights'], 'per_room' => (float) $row['amount'], 'total' => round((float) $row['amount'] * $rooms, 2),
                ]])->merge($engine->options($type, $in, $out, \App\Services\Rates\RateService::ONLINE, $promo)->map(fn ($o) => (object) [
                    'plan' => $o->plan, 'key' => (string) $o->plan->id, 'name' => $o->plan->name, 'inclusions' => $o->plan->inclusions,
                    'non_refundable' => $o->plan->non_refundable, 'promo' => (bool) $o->plan->promo_code,
                    'nights' => $o->nights, 'per_room' => $o->amount, 'total' => round($o->amount * $rooms, 2),
                ]))->sortBy('per_room')->values();
                $best = $rates->first();

                return (object) [
                    'type'        => $type,
                    'available'   => (int) $row['available'],
                    'bookable'    => $fits && $row['available'] >= $rooms && ! $restriction,
                    'fits'        => $fits,
                    'restriction' => $restriction,
                    'rates'       => $rates,
                    'nights'      => $best->nights,
                    'per_room'    => $best->per_room,
                    'total'       => $best->total,
                ];
            })
            ->sortBy([['bookable', 'desc'], ['per_room', 'asc']])->values();
    }

    /** Why a promo code gives nothing for this search (null when it works or none was typed). */
    public function promoProblem(?string $promo, Collection $results, Carbon $in, Carbon $out): ?string
    {
        if (blank($promo)) {
            return null;
        }
        $engine = app(\App\Services\Rates\RateService::class);
        $plan = $engine->promo($promo);
        if (! $plan) {
            return 'The code ' . strtoupper(trim($promo)) . ' isn’t valid. Check it, or book at our standard rates below.';
        }
        if ($results->contains(fn ($r) => $r->rates->contains(fn ($o) => $o->plan?->id === $plan->id))) {
            return null;
        }
        $type = $results->first()?->type;

        return $type ? (($engine->problems($plan, $type, $in, $out, \App\Services\Rates\RateService::ONLINE, $promo)[0] ?? null) ?: 'The code doesn’t apply to these rooms or dates.') : null;
    }

    /** data: room_type_id, check_in, check_out, adults, children, rooms, first_name, last_name, email, phone, arrival_time?, special_requests?, rate_plan_id?, promo? */
    public function book(array $data): Reservation
    {
        if (! config('hotel.booking.enabled', true)) {
            throw new BookingException('Online booking is closed at the moment. Please contact the hotel.');
        }
        [$in, $out, $adults, $children, $rooms] = $this->criteria($data);
        $type = RoomType::where('is_active', true)->findOrFail($data['room_type_id']);
        if ((int) ceil($adults / $rooms) > (int) $type->max_adults || (int) ceil($children / $rooms) > (int) $type->max_children) {
            throw new BookingException("A {$type->name} takes up to {$type->capacity_label}. Choose more rooms or another room type.");
        }

        if ($why = app(\App\Services\Rates\RateService::class)->restriction($type, $in, $out)) {
            throw new BookingException($why);
        }
        $depositPct = max(0, min(100, (float) config('hotel.booking.deposit_percent', 30)));

        return DB::transaction(function () use ($data, $in, $out, $adults, $children, $rooms, $type, $depositPct) {
            $guest = $this->guest($data);

            // Spread the party over the rooms: 3 adults in 2 rooms → 2 + 1.
            $lines = [];
            for ($i = 0; $i < $rooms; $i++) {
                $lines[] = ['room_type_id' => $type->id, 'room_id' => null,
                    'adults' => intdiv($adults, $rooms) + ($i < $adults % $rooms ? 1 : 0),
                    'children' => intdiv($children, $rooms) + ($i < $children % $rooms ? 1 : 0)];
            }

            $reservation = $this->reservations->create($guest, [
                'check_in_date'         => $in->toDateString(),
                'check_out_date'        => $out->toDateString(),
                'source'                => BookingSource::Website->value,
                'status'                => ($depositPct > 0 ? ReservationStatus::Pending : ReservationStatus::Confirmed)->value,
                'expected_arrival_time' => $data['arrival_time'] ?? null,
                'special_requests'      => $data['special_requests'] ?? null,
                'notes'                 => 'Booked online by the guest.',
                'rate_plan_id'          => $data['rate_plan_id'] ?? null,
                'promo_code'            => $data['promo'] ?? null,
            ], $lines);

            // Returning guests in a loyalty tier get its discount on the standard room rate (not on top of a special rate).
            $loyalty = app(\App\Services\Loyalty\LoyaltyService::class);
            if (empty($data['rate_plan_id']) && ($pct = $loyalty->discountPercent($guest)) > 0) {
                $off = round((float) $reservation->room_total * $pct / 100, 2);
                $this->reservations->applyDiscount($reservation, $off, $guest->tier_label . ' member rate (' . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '% off)');
                $reservation->refresh();
            }

            $hold = null;
            if ($depositPct > 0) {
                // Released after hold_hours — or at check-in time if that comes first.
                $hold = now()->addHours(max(1, (int) config('hotel.booking.hold_hours', 24)))->min($this->checkInAt($reservation));
            }
            $reservation->forceFill(['guest_token' => Reservation::newGuestToken(), 'hold_expires_at' => $hold])->save();

            // Booked through the group site (several hotels): the hotel owes the group its commission on it.
            if (! empty($data['via_group']) && \App\Support\Tenancy\CurrentHotel::multi() && ($hotel = \App\Support\Tenancy\CurrentHotel::get()->hotel())) {
                $reservation->forceFill(['via_group' => true, 'commission_percent' => $hotel->commissionRate()])->save();
            }

            Audit::record('reservations.online_booking', "Online booking {$reservation->reference}: {$guest->full_name}, {$rooms} × {$type->name}, "
                . $in->format('j M') . ' – ' . $out->format('j M Y') . ' (' . $this->money($reservation->total_amount) . ')', $reservation);

            DB::afterCommit(fn () => $this->sendConfirmation($reservation->fresh(['guest', 'rooms.roomType'])));

            return $reservation->fresh(['guest', 'rooms.roomType']);
        });
    }

    // ── Money ─────────────────────────────────────────────────────────

    /** Deposit still needed to confirm (0 when none is asked or it is paid). */
    public function depositDue(Reservation $r): float
    {
        $pct = max(0, min(100, (float) config('hotel.booking.deposit_percent', 30)));
        $required = round((float) $r->total_amount * $pct / 100, 2);

        return max(0, round($required - $this->paid($r), 2));
    }

    public function paid(Reservation $r): float
    {
        return $r->folio ? $r->folio->paidTotal() : 0.0;
    }

    /** What the guest is expected to owe in all: the booked room total plus any extras on the bill, less payments. */
    public function balanceDue(Reservation $r): float
    {
        $folio = $r->folio;
        if (! $folio) {
            return (float) $r->total_amount;
        }
        $charges = $folio->charges()->whereNull('voided_at');
        $extras = (float) (clone $charges)->where('type', '!=', ChargeType::Room->value)->sum('total');
        $roomsPosted = (float) (clone $charges)->where('type', ChargeType::Room->value)->sum('total');

        return max(0, round(max((float) $r->total_amount, $roomsPosted) + $extras - $folio->paidTotal(), 2));
    }

    /** Start a Paystack payment for the deposit (pending booking) or the balance. Returns the payment page URL. */
    public function startPayment(Reservation $r, string $callbackUrl): string
    {
        if (! $this->paystack->enabled()) {
            throw new BookingException('Online payment is not available. Please pay by bank transfer or at the hotel.');
        }
        if (! in_array($r->status, [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
            throw new BookingException('This booking is ' . strtolower($r->status->label()) . ' and can’t be paid online.');
        }
        $purpose = $r->status === ReservationStatus::Pending ? 'deposit' : 'balance';
        $amount = $purpose === 'deposit' ? $this->depositDue($r) : $this->balanceDue($r);
        if ($amount < 1) {
            throw new BookingException('Nothing is due on this booking right now.');
        }
        $email = $r->guest?->email ?: throw new BookingException('Add an email address to pay online.');

        $op = OnlinePayment::create([
            'reservation_id' => $r->id, 'provider' => 'paystack', 'purpose' => $purpose,
            'reference' => 'SH' . $r->id . '-' . Str::upper(Str::random(10)),
            'amount' => $amount, 'currency' => config('hotel.currency', 'NGN'), 'email' => $email,
        ]);

        return $this->paystack->initialize($email, $amount, $op->currency, $op->reference, $callbackUrl,
            ['reservation' => $r->reference, 'purpose' => $purpose, 'hotel' => config('hotel.name')]);
    }

    /**
     * Confirm a Paystack payment with Paystack and book it to the guest's bill.
     * Safe to call more than once (callback and webhook both arrive).
     */
    public function settle(string $reference): OnlinePayment
    {
        $op = OnlinePayment::where('reference', $reference)->firstOrFail();
        if ($op->status === 'success') {
            return $op;
        }
        $v = $this->paystack->verify($reference);

        return DB::transaction(function () use ($op, $v) {
            $op = OnlinePayment::whereKey($op->id)->lockForUpdate()->first();
            if ($op->status === 'success') {
                return $op;
            }
            $op->forceFill(['channel' => $v['channel'], 'gateway_id' => $v['id'], 'message' => str((string) $v['message'])->limit(250)->toString(),
                'meta' => array_intersect_key($v, array_flip(['status', 'amount', 'currency', 'channel', 'paid_at']))]);

            if ($v['status'] === 'success' && $v['amount'] <= 0) {
                $v['status'] = 'failed';                    // nothing actually arrived
            }
            if ($v['status'] !== 'success') {
                $op->forceFill(['status' => in_array($v['status'], ['failed', 'abandoned'], true) ? $v['status'] : 'initialized'])->save();

                return $op;
            }
            if (strtoupper($v['currency']) !== strtoupper($op->currency) || $v['amount'] + 0.001 < (float) $op->amount) {
                // Paid, but not what we asked for: record what arrived and let staff look.
                Audit::record('reservations.online_payment_mismatch', "Paystack {$op->reference}: expected {$op->currency} {$op->amount}, received {$v['currency']} {$v['amount']}", $op->reservation, flagged: true);
            }

            $r = $op->reservation()->with('folio')->first();
            $folio = $r->folio ?? $this->folios->forReservation($r);
            $payment = $this->folios->receivePayment($folio,
                $r->status === ReservationStatus::CheckedIn ? PaymentKind::Payment : PaymentKind::Deposit,
                PaymentMethod::Online, (float) $v['amount'], $v['id'] ?: $op->reference,
                'Paystack' . ($v['channel'] ? ' · ' . $v['channel'] : '') . ' · ' . $op->reference,
                ['idempotency_key' => 'paystack:' . $op->reference, 'received_at' => $v['paid_at'] ? Carbon::parse($v['paid_at']) : now()]);
            $op->forceFill(['status' => 'success', 'payment_id' => $payment->id, 'paid_at' => $payment->received_at])->save();

            if ($r->status === ReservationStatus::Pending && $this->depositDue($r->fresh('folio')) < 0.01) {
                $this->reservations->confirm($r);
                $r->forceFill(['hold_expires_at' => null])->save();
                $r->log('online_confirmed', 'Confirmed automatically — online deposit received.');
            } elseif (in_array($r->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)) {
                Audit::record('reservations.paid_after_cancel', "Online payment {$this->money($v['amount'])} arrived for {$r->reference}, which is {$r->status->label()} — refund or reinstate it.", $r, flagged: true);
            }

            return $op;
        });
    }

    // ── Guest actions ─────────────────────────────────────────────────

    public function checkInAt(Reservation $r): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', (string) config('hotel.booking.check_in_time', '14:00')) + [1 => 0]);

        return $r->check_in_date->copy()->setTime($h, $m);
    }

    public function canCancel(Reservation $r): bool
    {
        return in_array($r->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true)
            && now()->lt($this->checkInAt($r)->subHours(max(0, (int) config('hotel.booking.cancel_hours', 48))));
    }

    public function cancelByGuest(Reservation $r): void
    {
        if (! $this->canCancel($r)) {
            throw new BookingException('This booking can no longer be cancelled online. Please contact the hotel.');
        }
        $paid = $this->paid($r);
        $this->reservations->cancel($r, 'Cancelled by the guest online.');
        $r->forceFill(['hold_expires_at' => null])->save();
        Audit::record('reservations.cancelled_online', "Guest cancelled {$r->reference} online" . ($paid > 0 ? ' — ' . $this->money($paid) . ' was paid: refund per policy' : ''), $r, flagged: $paid > 0);
    }

    /** Release online bookings whose deposit didn't arrive in time (scheduler, every 15 minutes). */
    public function expireHolds(): int
    {
        $n = 0;
        $due = Reservation::where('status', ReservationStatus::Pending->value)->whereNotNull('hold_expires_at')->where('hold_expires_at', '<=', now())->get();
        foreach ($due as $r) {
            try {
                if ($this->depositDue($r) < 0.01) {
                    $this->reservations->confirm($r);
                    $r->forceFill(['hold_expires_at' => null])->save();
                    continue;
                }
                $this->reservations->cancel($r, 'Deposit not received in time — online hold released.');
                $r->forceFill(['hold_expires_at' => null])->save();
                $n++;
            } catch (Throwable $e) {
                Log::warning("Could not release hold on {$r->reference}: " . $e->getMessage());
            }
        }

        return $n;
    }

    /** Find a booking from its reference plus the guest's email or phone. */
    public function find(string $reference, string $contact): ?Reservation
    {
        // References are unique across every hotel; carry on in the booking's own hotel.
        $r = Reservation::withoutGlobalScope('hotel')->where('reference', strtoupper(trim($reference)))->first();
        \App\Support\Tenancy\CurrentHotel::get()->adopt($r);
        $r?->load('guest');
        if (! $r || ! $r->guest) {
            return null;
        }
        $contact = mb_strtolower(trim($contact));
        $digits = fn ($p) => substr(preg_replace('/\D/', '', (string) $p), -9);   // last 9 digits: ignores +234 / 0 prefixes
        $match = ($r->guest->email && hash_equals(mb_strtolower($r->guest->email), $contact))
            || ($r->guest->phone && strlen($digits($contact)) >= 7 && hash_equals($digits($r->guest->phone), $digits($contact)));

        return $match ? $r : null;
    }

    public function ensureToken(Reservation $r): string
    {
        if (! $r->guest_token) {
            $r->forceFill(['guest_token' => Reservation::newGuestToken()])->save();
        }

        return $r->guest_token;
    }

    // ------------------------------------------------------------------

    /** The same person booking again (by email) keeps one guest profile. */
    private function guest(array $data): Guest
    {
        $email = mb_strtolower(trim((string) $data['email']));
        $guest = Guest::whereRaw('lower(email) = ?', [$email])->first();
        if ($guest) {
            $guest->fill(array_filter(['phone' => $guest->phone ? null : $data['phone']]))->save();

            return $guest;
        }

        return Guest::create([
            'first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name']),
            'email' => $email, 'phone' => trim((string) $data['phone']),
        ]);
    }

    private function sendConfirmation(Reservation $r): void
    {
        if (! $r->guest?->email) {
            return;
        }
        try {
            Mail::send('booking.emails.confirmation', ['r' => $r, 'depositDue' => $this->depositDue($r), 'url' => route('stay.show', $r->guest_token)],
                fn ($m) => $m->to($r->guest->email, $r->guest->full_name)->subject(config('hotel.name') . ' — booking ' . $r->reference));
        } catch (Throwable $e) {
            Log::warning('Booking confirmation email failed: ' . $e->getMessage());
        }
    }

    private function money($v): string
    {
        return config('hotel.currency_symbol') . number_format((float) $v, 2);
    }
}
