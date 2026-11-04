<?php

namespace App\Services\Wellness;

use App\Enums\ChargeType;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Wellness\WellnessBooking;
use App\Models\Wellness\WellnessService;
use App\Models\Wellness\WellnessTherapist;
use App\Models\Wellness\WellnessVenue;
use App\Services\Billing\FolioService;
use App\Services\Booking\PaystackService;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Spa, gym and pool bookings.
 *
 * The spa books treatments: each needs a free therapist for its length (or, with no therapists set up, one of the
 * venue's treatment rooms). The gym and pool book sessions for a number of people, up to the venue's capacity at
 * once. In-house guests book from the guest app or their booking page and it goes on the room bill when they
 * arrive at the spa; visitors book on the website and pay online (held while they pay) or on arrival; staff book
 * at the desk. Then arrived, done or didn't come.
 */
class WellnessBookings
{
    public const HOLD_MINUTES = 20;
    public const MAX_PEOPLE = 10;

    public function __construct(private FolioService $folios, private PaystackService $paystack)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('hotel.wellness.enabled', true);
    }

    public function venues(bool $visitors = false): Collection
    {
        return WellnessVenue::active()->ordered()->when($visitors, fn ($q) => $q->where('visitors', true))
            ->with(['services' => fn ($q) => $q->where('is_active', true)])->get()
            ->filter(fn (WellnessVenue $v) => ! $v->hasTreatments() || $v->services->isNotEmpty())->values();
    }

    public function canManage(User $u): bool
    {
        return $u->can('Manage wellness bookings');
    }

    // ── Times ─────────────────────────────────────────────────────────

    /** How long a booking lasts. */
    public function minutes(WellnessVenue $v, ?WellnessService $s): int
    {
        return max(15, (int) ($v->hasTreatments() ? ($s?->minutes ?? 60) : $v->session_minutes));
    }

    /**
     * The times that can be booked on $date: [{time: "09:00", starts_at, label: "9:00 AM", left}]. For staff
     * ($desk) times already past are kept until the end of the hour, and no notice is needed.
     */
    public function slots(WellnessVenue $v, ?WellnessService $s, Carbon $date, int $people = 1, bool $desk = false): array
    {
        $len = $this->minutes($v, $s);
        $step = $v->hasTreatments() ? max(15, (int) config('hotel.wellness.step_minutes', 30)) : $len;
        $day = $date->copy()->startOfDay();
        $open = $day->copy()->setTimeFromTimeString($v->opens);
        $close = $day->copy()->setTimeFromTimeString($v->closes);
        if ($close->lte($open)) {
            $close->addDay();
        }
        $earliest = $desk ? now()->subMinutes(30) : now()->addMinutes((int) config('hotel.wellness.lead_minutes', 30));
        $taken = WellnessBooking::where('venue_id', $v->id)->holding()->overlapping($open, $close)->get(['starts_at', 'ends_at', 'people', 'therapist_id']);
        $therapists = $v->hasTreatments() ? WellnessTherapist::where('venue_id', $v->id)->where('is_active', true)->pluck('id') : collect();

        $out = [];
        for ($t = $open->copy(); $t->copy()->addMinutes($len)->lte($close); $t->addMinutes($step)) {
            if ($t->lt($earliest)) {
                continue;
            }
            $left = $this->left($v, $taken, $therapists, $t, $t->copy()->addMinutes($len));
            if ($left >= ($v->hasTreatments() ? 1 : $people)) {
                $out[] = ['time' => $t->format('H:i'), 'starts_at' => $t->toIso8601String(), 'label' => $t->format('g:i A'), 'left' => $left];
            }
        }

        return $out;
    }

    /** Places (sessions) or therapists (treatments) still free between $from and $to. */
    private function left(WellnessVenue $v, Collection $taken, Collection $therapists, Carbon $from, Carbon $to): int
    {
        $over = $taken->filter(fn ($b) => $b->starts_at->lt($to) && $b->ends_at->gt($from));
        if (! $v->hasTreatments()) {
            return max(0, $v->capacity - (int) $over->sum('people'));
        }
        if ($therapists->isEmpty()) {
            return max(0, $v->capacity - $over->count());
        }

        return $therapists->diff($over->pluck('therapist_id')->filter())->count();
    }

    // ── Prices ────────────────────────────────────────────────────────

    public function price(WellnessVenue $v, ?WellnessService $s, int $people, bool $visitor): float
    {
        return $v->hasTreatments() ? ($s?->priceFor($visitor) ?? 0.0) : round($v->sessionPrice($visitor) * $people, 2);
    }

    // ── Booking ───────────────────────────────────────────────────────

    /**
     * The one way a booking is made. $who: reservation, or name/phone/email; payment room|online|desk|free;
     * status booked or pending (paying online).
     */
    private function create(WellnessVenue $v, ?WellnessService $s, Carbon $start, int $people, array $who, string $payment, string $via, ?string $note, bool $desk = false, string $status = WellnessBooking::BOOKED): WellnessBooking
    {
        if (! $this->enabled() || ! $v->is_active) {
            throw new BookingException("{$v->name} isn’t taking bookings.");
        }
        if ($v->hasTreatments()) {
            if (! $s || $s->venue_id !== $v->id || ! $s->is_active) {
                throw new BookingException('Choose a treatment.');
            }
            $people = 1;
        } else {
            $s = null;
            $people = max(1, min(self::MAX_PEOPLE, $people));
        }
        $len = $this->minutes($v, $s);
        $end = $start->copy()->addMinutes($len);
        // On the venue's times (staff can book any time inside the hours).
        $slot = collect($this->slots($v, $s, $start, $people, $desk))->firstWhere('time', $start->format('H:i'));
        if (! $slot && ! ($desk && $this->insideHours($v, $start, $end))) {
            throw new BookingException('That time is no longer free. Choose another.');
        }
        if ($start->gt(now()->addDays((int) config('hotel.wellness.days_ahead', 30)))) {
            throw new BookingException('Bookings open ' . (int) config('hotel.wellness.days_ahead', 30) . ' days ahead.');
        }

        return DB::transaction(function () use ($v, $s, $start, $end, $people, $who, $payment, $via, $note, $status) {
            WellnessVenue::whereKey($v->id)->lockForUpdate()->first();
            $taken = WellnessBooking::where('venue_id', $v->id)->holding()->overlapping($start, $end)->get(['starts_at', 'ends_at', 'people', 'therapist_id']);
            $therapists = $v->hasTreatments() ? WellnessTherapist::where('venue_id', $v->id)->where('is_active', true)->pluck('id') : collect();
            if ($this->left($v, $taken, $therapists, $start, $end) < ($v->hasTreatments() ? 1 : $people)) {
                throw new BookingException($v->hasTreatments() ? 'Every therapist is busy then. Choose another time.' : "{$v->name} is full then. Choose another time.");
            }
            // The therapist with the fewest bookings that day.
            $therapistId = null;
            if ($therapists->isNotEmpty()) {
                $free = $therapists->diff($taken->pluck('therapist_id')->filter());
                $load = WellnessBooking::whereIn('therapist_id', $free)->holding()->whereDate('starts_at', $start->toDateString())->selectRaw('therapist_id, count(*) n')->groupBy('therapist_id')->pluck('n', 'therapist_id');
                $therapistId = $free->sortBy(fn ($id) => (int) ($load[$id] ?? 0))->first();
            }
            $r = $who['reservation'] ?? null;
            $total = $this->price($v, $s, $people, $r === null);

            $b = WellnessBooking::create([
                'venue_id' => $v->id, 'service_id' => $s?->id, 'therapist_id' => $therapistId, 'reservation_id' => $r?->id,
                'guest_name' => mb_substr(trim((string) ($r?->guest?->full_name ?? $who['name'] ?? 'Guest')), 0, 120),
                'phone' => $r?->guest?->phone ?? ($who['phone'] ?? null), 'email' => $r?->guest?->email ?? ($who['email'] ?? null),
                'people' => $people, 'starts_at' => $start, 'ends_at' => $end, 'total' => $total,
                'payment' => $total <= 0 ? 'free' : $payment, 'status' => $status, 'via' => $via,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null,
                'token' => Str::random(48),
                'expires_at' => $status === WellnessBooking::PENDING ? now()->addMinutes(self::HOLD_MINUTES) : null,
            ]);
            $r?->log('wellness', "Booked {$b->what} at {$v->name}, " . $start->format('D j M g:i A') . " ({$b->number}).");

            return $b->fresh(['venue', 'service', 'therapist']);
        });
    }

    private function insideHours(WellnessVenue $v, Carbon $start, Carbon $end): bool
    {
        $open = $start->copy()->setTimeFromTimeString($v->opens);
        $close = $start->copy()->setTimeFromTimeString($v->closes);
        if ($close->lte($open)) {
            $close->addDay();
        }

        return $start->gte($open) && $end->lte($close) && $start->gte(now()->subHour());
    }

    /** Why this stay can't book (null = it can). */
    public function blocker(Reservation $r): ?string
    {
        if (! $this->enabled() || $this->venues()->isEmpty()) {
            return 'The spa, gym and pool can’t be booked in the app at this hotel.';
        }
        if ($r->status === ReservationStatus::CheckedOut || $r->check_out_date->lt(today())) {
            return 'Your stay has ended. Visitors can book on our website.';
        }
        if (! in_array($r->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
            return 'You can book once your stay is confirmed.';
        }

        return null;
    }

    /** An in-house (or confirmed) guest books; it goes on the room bill when they arrive. */
    public function bookForStay(Reservation $r, WellnessVenue $v, ?WellnessService $s, Carbon $start, int $people = 1, ?string $note = null, string $via = 'app'): WellnessBooking
    {
        if ($why = $this->blocker($r)) {
            throw new BookingException($why);
        }
        if ($start->lt($r->check_in_date->copy()->startOfDay()) || $start->gte($r->check_out_date->copy()->endOfDay())) {
            throw new BookingException('Choose a day during your stay (' . $r->check_in_date->format('j M') . ' – ' . $r->check_out_date->format('j M') . ').');
        }
        if (WellnessBooking::where('reservation_id', $r->id)->holding()->where('starts_at', '>', now())->count() >= (int) config('hotel.wellness.max_per_stay', 6)) {
            throw new BookingException('You have several bookings coming up already. Call the spa to add more.');
        }
        $r->loadMissing('guest');

        return $this->create($v, $s, $start, $people, ['reservation' => $r], 'room', $via, $note);
    }

    /**
     * A visitor books on the website. Paid online when Paystack is on (held HOLD_MINUTES while they pay),
     * otherwise paid on arrival if the hotel allows it.
     *
     * @return array{booking: WellnessBooking, pay_url: ?string}
     */
    public function bookVisitor(WellnessVenue $v, ?WellnessService $s, Carbon $start, int $people, array $who, ?string $note, string $callbackUrl, string $via = 'web'): array
    {
        if (! $v->takesVisitors()) {
            throw new BookingException("{$v->name} is for hotel guests only.");
        }
        if (blank($who['name'] ?? null) || ! filter_var($who['email'] ?? '', FILTER_VALIDATE_EMAIL) || blank($who['phone'] ?? null)) {
            throw new BookingException('Enter your name, e-mail address and phone number.');
        }
        if (WellnessBooking::where('status', WellnessBooking::PENDING)->where('expires_at', '>', now())->where('email', $who['email'])->count() >= 2) {
            throw new BookingException('You already have bookings waiting for payment. Finish paying for those first, or wait a few minutes.');
        }
        $total = $this->price($v, $s, $people, true);
        $online = $total > 0 && $this->paystack->enabled();
        if ($total > 0 && ! $online && ! config('hotel.wellness.pay_on_arrival', true)) {
            throw new BookingException('Online payment isn’t available right now. Call us to book.');
        }
        $b = $this->create($v, $s, $start, $people, $who, $online ? 'online' : 'desk', $via, $note, false, $online ? WellnessBooking::PENDING : WellnessBooking::BOOKED);
        if (! $online) {
            $this->email($b);

            return ['booking' => $b, 'pay_url' => null];
        }
        $b->forceFill(['gateway_reference' => 'WB' . $b->id . '-' . Str::upper(Str::random(10))])->save();
        try {
            $url = $this->paystack->initialize($b->email, (float) $b->total, (string) config('hotel.currency', 'NGN'), $b->gateway_reference, $callbackUrl,
                ['wellness' => $b->number, 'hotel' => config('hotel.name')]);
        } catch (\Throwable $e) {
            $b->forceFill(['status' => WellnessBooking::EXPIRED])->save();
            throw $e instanceof BookingException ? $e : new BookingException('The payment page could not be opened. Please try again.');
        }

        return ['booking' => $b, 'pay_url' => $url];
    }

    public function payAgain(WellnessBooking $b, string $callbackUrl): string
    {
        if ($b->status !== WellnessBooking::PENDING || ! $b->expires_at || $b->expires_at->isPast()) {
            throw new BookingException('This booking is no longer waiting for payment. Book again.');
        }

        return $this->paystack->initialize($b->email, (float) $b->total, (string) config('hotel.currency', 'NGN'), $b->gateway_reference, $callbackUrl, ['wellness' => $b->number]);
    }

    /** Paystack says it was paid (callback or webhook; safe twice): confirm with Paystack, then the booking stands. */
    public function settle(string $reference): WellnessBooking
    {
        $b = WellnessBooking::where('gateway_reference', $reference)->firstOrFail();
        if ($b->paid_at) {
            return $b;
        }
        $v = $this->paystack->verify($reference);
        if ($v['status'] !== 'success' || $v['amount'] <= 0) {
            return $b;
        }
        $b = DB::transaction(function () use ($b, $v) {
            $b = WellnessBooking::whereKey($b->id)->lockForUpdate()->first();
            if ($b->paid_at) {
                return $b;
            }
            $late = $b->status !== WellnessBooking::PENDING;
            $b->forceFill(['status' => in_array($b->status, [WellnessBooking::PENDING, WellnessBooking::EXPIRED], true) ? WellnessBooking::BOOKED : $b->status,
                'paid_at' => $v['paid_at'] ? Carbon::parse($v['paid_at']) : now(), 'expires_at' => null])->save();
            if (strtoupper($v['currency']) !== strtoupper((string) config('hotel.currency', 'NGN')) || $v['amount'] + 0.001 < (float) $b->total) {
                Audit::record('wellness.payment_mismatch', "Paystack {$b->gateway_reference}: expected {$b->total}, received {$v['currency']} {$v['amount']}", $b, flagged: true);
            }
            if ($late) {
                Audit::record('wellness.paid_late', "Booking {$b->number} was paid after its hold ran out. Check the time is still free.", $b, flagged: true);
            }
            Audit::record('wellness.paid', "Visitor booking {$b->number} paid online (" . $this->money((float) $b->total) . ')', $b);

            return $b;
        });
        $this->email($b);

        return $b;
    }

    /** Visitors' bookings never paid let their time go. */
    public function expire(): int
    {
        return WellnessBooking::where('status', WellnessBooking::PENDING)->where('expires_at', '<=', now())->update(['status' => WellnessBooking::EXPIRED, 'updated_at' => now()]);
    }

    /** Staff book at the desk: for an in-house guest (room bill) or a walk-in (pays on arrival). */
    public function deskBook(User $by, WellnessVenue $v, ?WellnessService $s, Carbon $start, int $people, ?Reservation $r, array $who, ?string $note): WellnessBooking
    {
        $this->authorize($by);
        if ($r && $r->status !== ReservationStatus::CheckedIn && $r->status !== ReservationStatus::Confirmed) {
            throw new BookingException('That booking isn’t confirmed or in-house.');
        }
        if (! $r && blank($who['name'] ?? null)) {
            throw new BookingException('Give the visitor’s name.');
        }
        $r?->loadMissing('guest');
        $b = $this->create($v, $s, $start, $people, $r ? ['reservation' => $r] : $who, $r ? 'room' : 'desk', 'desk', $note, true);
        $b->forceFill(['handled_by' => $by->id])->save();
        Audit::record('wellness.booked', "Booked {$b->number}: {$b->what} at {$v->name} for {$b->guest_name}, " . $b->starts_at->format('D j M g:i A'), $b, by: $by);

        return $b;
    }

    // ── On the day ────────────────────────────────────────────────────

    /** The guest is here. In-house guests' bookings go on the room bill now. */
    public function arrive(WellnessBooking $b, User $by): WellnessBooking
    {
        $this->authorize($by);

        return DB::transaction(function () use ($b, $by) {
            $b = WellnessBooking::whereKey($b->id)->lockForUpdate()->firstOrFail();
            if ($b->status !== WellnessBooking::BOOKED) {
                throw new BookingException("{$b->number} is " . mb_strtolower($b->status_label) . '.');
            }
            if ($b->payment === 'room' && (float) $b->total > 0) {
                $r = $b->reservation;
                if (! $r || $r->status !== ReservationStatus::CheckedIn) {
                    throw new BookingException('The guest isn’t checked in, so this can’t go on a room bill. Take payment and mark it “pays on arrival” by booking again at the desk.');
                }
                $charge = $this->folios->postCharge($this->folios->forReservation($r), ChargeType::Service, mb_substr("{$b->venue->name}: {$b->what} ({$b->number})", 0, 255), 1, (float) $b->total, today(),
                    ['source_type' => WellnessBooking::class, 'source_id' => $b->id, 'idempotency_key' => "wellness:{$b->id}"]);
                $b->folio_charge_id = $charge->id;
            }
            $b->forceFill(['status' => WellnessBooking::ARRIVED, 'handled_by' => $by->id])->save();
            Audit::record('wellness.arrived', "{$b->guest_name} arrived for {$b->number}" . ($b->folio_charge_id ? ' — ' . $this->money((float) $b->total) . ' on the room bill' : ''), $b, by: $by);

            return $b;
        });
    }

    public function complete(WellnessBooking $b, User $by): void
    {
        $this->move($b, $by, [WellnessBooking::ARRIVED, WellnessBooking::BOOKED], WellnessBooking::COMPLETED);
    }

    public function noShow(WellnessBooking $b, User $by): void
    {
        if ($b->starts_at->isFuture()) {
            throw new BookingException('It hasn’t started yet.');
        }
        $this->move($b, $by, [WellnessBooking::BOOKED], WellnessBooking::NO_SHOW);
    }

    /** Staff cancel (any reason, shown to the guest). Paid-online bookings are refunded by hand in Paystack. */
    public function cancel(WellnessBooking $b, User $by, string $reason): void
    {
        $this->move($b, $by, [WellnessBooking::BOOKED, WellnessBooking::PENDING], WellnessBooking::CANCELLED, ['staff_note' => mb_substr($reason, 0, 255)]);
        if ($b->paid_at) {
            Audit::record('wellness.refund_due', "{$b->number} was paid online (" . $this->money((float) $b->total) . ') and has been cancelled: refund it in Paystack.', $b, by: $by, flagged: true);
        }
    }

    /** The guest cancels, up to cancel_hours before. */
    public function cancelByGuest(WellnessBooking $b): void
    {
        if (! $this->guestCanCancel($b)) {
            throw new BookingException('It’s too late to cancel online — please call the spa.');
        }
        WellnessBooking::whereKey($b->id)->whereIn('status', [WellnessBooking::BOOKED, WellnessBooking::PENDING])->update(['status' => WellnessBooking::CANCELLED, 'updated_at' => now()]);
        $b->reservation?->log('wellness', "Cancelled {$b->number} ({$b->what}).");
    }

    public function guestCanCancel(WellnessBooking $b): bool
    {
        return in_array($b->status, [WellnessBooking::BOOKED, WellnessBooking::PENDING], true) && ! $b->paid_at
            && $b->starts_at->gt(now()->addHours((int) config('hotel.wellness.cancel_hours', 2)));
    }

    private function move(WellnessBooking $b, User $by, array $from, string $to, array $extra = []): void
    {
        $this->authorize($by);
        if (! WellnessBooking::whereKey($b->id)->whereIn('status', $from)->update(['status' => $to, 'handled_by' => $by->id, 'updated_at' => now()] + $extra)) {
            throw new BookingException("{$b->number} has moved on. Refresh the page.");
        }
        $b->refresh();
    }

    private function authorize(User $by): void
    {
        if (! $this->canManage($by)) {
            throw new AuthorizationException('You are not allowed to manage spa, gym and pool bookings.');
        }
    }

    // ── For guests ────────────────────────────────────────────────────

    /** What can be booked, for the guest app and the website: venues with their treatments and prices. */
    public function catalogue(bool $visitor): array
    {
        return $this->venues($visitor)->map(fn (WellnessVenue $v) => [
            'id' => $v->id, 'name' => $v->name, 'kind' => $v->kind, 'description' => $v->description, 'hours' => $v->hours_label,
            'treatments' => $v->hasTreatments(), 'session_minutes' => $v->hasTreatments() ? null : $v->session_minutes,
            'max_people' => $v->hasTreatments() ? 1 : min(self::MAX_PEOPLE, $v->capacity),
            'price' => $v->hasTreatments() ? null : $v->sessionPrice($visitor),
            'price_label' => $v->hasTreatments() ? null : ($v->sessionPrice($visitor) > 0 ? $this->money($v->sessionPrice($visitor)) . ' per person' : 'Free'),
            'services' => $v->services->map(fn (WellnessService $s) => [
                'id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'minutes' => $s->minutes,
                'price' => $s->priceFor($visitor), 'price_label' => $this->money($s->priceFor($visitor)),
            ])->values()->all(),
        ])->all();
    }

    /** GET …/wellness for a stay: what can be booked, and the stay's bookings. */
    public function forGuestScreen(Reservation $r): array
    {
        return [
            'available'    => $this->blocker($r) === null,
            'blocker'      => $this->blocker($r),
            'currency'     => config('hotel.currency_symbol'),
            'first_day'    => max(today(), $r->check_in_date->copy()->startOfDay())->toDateString(),
            'last_day'     => $r->check_out_date->toDateString(),
            'cancel_hours' => (int) config('hotel.wellness.cancel_hours', 2),
            'venues'       => $this->catalogue(false),
            'bookings'     => $this->forGuest($r),
        ];
    }

    public function forGuest(Reservation $r): array
    {
        return WellnessBooking::where('reservation_id', $r->id)->whereNotIn('status', [WellnessBooking::EXPIRED])
            ->with('venue', 'service', 'therapist')->orderByDesc('starts_at')->limit(12)->get()->map(fn ($b) => $this->json($b))->all();
    }

    public function json(WellnessBooking $b): array
    {
        return [
            'id' => $b->id, 'number' => $b->number, 'venue' => $b->venue?->name, 'kind' => $b->venue?->kind, 'what' => $b->what,
            'people' => $b->people, 'therapist' => $b->therapist?->name,
            'starts_at' => $b->starts_at->toIso8601String(), 'when' => $this->when($b->starts_at) . ' – ' . $b->ends_at->format('g:i A'),
            'total_label' => (float) $b->total > 0 ? $this->money((float) $b->total) : 'Free', 'payment' => $b->payment, 'payment_label' => $b->payment_label,
            'status' => $b->status, 'status_label' => $b->status_label,
            'upcoming' => in_array($b->status, [WellnessBooking::BOOKED, WellnessBooking::PENDING, WellnessBooking::ARRIVED], true) && $b->ends_at->isFuture(),
            'note' => $b->staff_note, 'can_cancel' => $this->guestCanCancel($b),
        ];
    }

    public function when(Carbon $t): string
    {
        return $t->isToday() ? 'Today ' . $t->format('g:i A') : ($t->isTomorrow() ? 'Tomorrow ' . $t->format('g:i A') : $t->format('D j M, g:i A'));
    }

    /** The visitor's confirmation, with a link to their booking page. */
    public function email(WellnessBooking $b): bool
    {
        if (! $b->email || ! $b->isVisitor()) {
            return false;
        }
        $b->loadMissing('venue', 'service');
        try {
            Mail::send('wellness.email', ['b' => $b, 'svc' => $this], fn ($m) => $m->to($b->email, $b->guest_name)->subject("Booked: {$b->what}, " . $b->starts_at->format('D j M g:i A') . " ({$b->number})"));

            return true;
        } catch (\Throwable $e) {
            Log::warning("Wellness {$b->number} not e-mailed: " . $e->getMessage());

            return false;
        }
    }

    public function money(float $v): string
    {
        return config('hotel.currency_symbol') . number_format($v, 2);
    }
}
