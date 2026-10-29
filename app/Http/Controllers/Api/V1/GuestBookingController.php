<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingException;
use App\Models\RoomType;
use App\Services\Booking\OnlineBookingService;
use App\Services\Booking\PaystackService;
use Illuminate\Http\Request;

/**
 * Booking from the guest app: the same search, rates, promo codes and booking as the website's /book pages.
 * Rates shown are the ones sold online (the standard rate, plus every rate plan open to the online channel
 * that fits the stay; a promo code unlocks its plan). No sign-in; rate-limited like the website.
 */
class GuestBookingController extends ApiController
{
    public function __construct(private OnlineBookingService $booking)
    {
    }

    /**
     * GET /guest/hotels?where&check_in&check_out&adults&rooms&promo — the group's hotels (several hotels),
     * with what each has free and its lowest price for the dates. Book one with ?hotel={slug} on the calls below.
     */
    public function hotels(Request $request, \App\Services\Marketplace\GroupSearch $search)
    {
        $q = $this->query($request);
        $dated = $request->filled('check_in') && $request->filled('check_out');
        $rows = $search->hotels((string) $request->query('where', ''), $dated ? $q : null);

        return $this->ok($rows->map(fn ($r) => [
            'id' => $r['hotel']->id, 'slug' => $r['hotel']->slug, 'name' => $r['hotel']->name,
            'city' => $r['hotel']->city, 'location' => $r['hotel']->location, 'address' => $r['hotel']->address,
            'stars' => $r['hotel']->stars, 'description' => $r['hotel']->description,
            'cover_url' => $r['hotel']->cover_url, 'logo_url' => $r['hotel']->logo_url,
            'phone' => $r['hotel']->phone, 'email' => $r['hotel']->email,
            'open' => $r['open'], 'bookable' => $r['bookable'], 'problem' => $r['problem'],
            'from' => $r['from'], 'from_label' => $r['from'] !== null ? $r['currency'] . number_format($r['from'], 0) : null, 'nights' => $r['nights'],
            'nightly_from' => $r['nightly_from'] ?: null, 'nightly_from_label' => $r['nightly_from'] ? $r['currency'] . number_format($r['nightly_from'], 0) : null,
        ])->values(), meta: ['cities' => $search->cities()->values(), 'dated' => $dated]);
    }

    /** GET /guest/booking — whether online booking is open, its limits, and the room types to show before a search. */
    public function options()
    {
        $sym = config('hotel.currency_symbol');
        $types = RoomType::where('is_active', true)->with('photos')->withCount(['rooms' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('base_rate')->get()->filter(fn ($t) => $t->rooms_count > 0)->values();

        $hotel = \App\Support\Tenancy\CurrentHotel::get()->hotel();

        return $this->ok([
            'hotel'           => $hotel ? ['id' => $hotel->id, 'slug' => $hotel->slug, 'name' => config('hotel.name'), 'location' => $hotel->location, 'cover_url' => $hotel->cover_url] : null,
            'enabled'         => (bool) config('hotel.booking.enabled', true),
            'max_rooms'       => (int) config('hotel.booking.max_rooms', 4),
            'max_nights'      => (int) config('hotel.booking.max_nights', 30),
            'advance_days'    => (int) config('hotel.booking.advance_days', 365),
            'deposit_percent' => (float) config('hotel.booking.deposit_percent', 30),
            'hold_hours'      => (int) config('hotel.booking.hold_hours', 24),
            'pay_online'      => app(PaystackService::class)->enabled(),
            'currency_symbol' => $sym,
            'check_in_time'   => config('hotel.booking.check_in_time'),
            'check_out_time'  => config('hotel.booking.check_out_time'),
            'policies'        => array_values((array) config('hotel.booking.policies', [])),
            'luggage_list'    => app(\App\Services\Booking\LuggageService::class)->enabled(),   // offer the optional luggage list on the booking form
            'room_types'      => $types->map(fn (RoomType $t) => $this->type($t) + [
                'from' => (float) $t->base_rate, 'from_label' => $sym . number_format((float) $t->base_rate, 2),
            ])->values(),
        ]);
    }

    /** GET /guest/search?check_in&check_out&adults&children&rooms&promo */
    public function search(Request $request)
    {
        $q = $this->query($request);
        try {
            [$in, $out, $adults, $children, $rooms] = $this->booking->criteria($q);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422, 'invalid_search');
        }
        $results = $this->booking->search($in, $out, $adults, $children, $rooms, $q['promo']);
        $results->each(fn ($r) => $r->type->loadMissing('photos'));
        $sym = config('hotel.currency_symbol');
        $money = fn ($v) => $sym . number_format((float) $v, 2);
        $depositPct = (float) config('hotel.booking.deposit_percent', 30);

        $count = fn ($o) => max(1, count((array) $o->nights));

        return $this->ok($results->map(fn ($r) => $this->type($r->type) + [
            'available'   => $r->available,
            'bookable'    => $r->bookable,
            'why_not'     => $r->bookable ? null : ($r->restriction ?: (! $r->fits ? "Takes up to {$r->type->capacity_label} per room. Choose more rooms." : 'Not enough rooms left for these dates.')),
            'rates'       => $r->rates->map(fn ($o) => [
                'key'            => $o->key,
                'rate_plan_id'   => $o->plan?->id,
                'name'           => $o->name,
                'inclusions'     => $o->inclusions,
                'non_refundable' => (bool) $o->non_refundable,
                'promo'          => (bool) $o->promo,
                'nightly'        => collect((array) $o->nights)->map(fn ($v, $d) => ['date' => $d, 'amount' => round((float) $v, 2)])->values(),
                'per_room'       => $o->per_room,
                'per_night'      => round($o->per_room / $count($o), 2),
                'per_night_label' => $money($o->per_room / $count($o)),
                'total'          => $o->total,
                'total_label'    => $money($o->total),
                'deposit'        => round($o->total * $depositPct / 100, 2),
                'deposit_label'  => $money(round($o->total * $depositPct / 100, 2)),
            ])->values(),
        ])->values(), meta: [
            'check_in' => $in->toDateString(), 'check_out' => $out->toDateString(), 'nights' => (int) $in->diffInDays($out),
            'adults' => $adults, 'children' => $children, 'rooms' => $rooms,
            'promo' => $q['promo'] ?: null,
            'promo_problem' => $this->booking->promoProblem($q['promo'], $results, $in, $out),
            'promo_applied' => $q['promo'] !== '' && $results->contains(fn ($r) => $r->rates->contains(fn ($o) => $o->promo)),
        ]);
    }

    /** POST /guest/bookings — book, answers the stay token (open it with GET /guest/stays/{token}) and where to pay. */
    public function store(Request $request, PaystackService $paystack)
    {
        $data = $request->validate([
            'room_type_id'     => 'required|integer',
            'check_in'         => 'required|date',
            'check_out'        => 'required|date',
            'adults'           => 'required|integer|min:1|max:20',
            'children'         => 'nullable|integer|min:0|max:20',
            'rooms'            => 'required|integer|min:1|max:20',
            'first_name'       => 'required|string|max:60',
            'last_name'        => 'required|string|max:60',
            'email'            => 'required|email:rfc|max:150',
            'phone'            => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'arrival_time'     => 'nullable|date_format:H:i',
            'special_requests' => 'nullable|string|max:1000',
            'luggage'          => 'nullable|string|max:5000',   // optional luggage list, one item per line
            'accept'           => 'accepted',
            'rate_plan_id'     => 'nullable|integer',
            'promo'            => 'nullable|string|max:30',
            'via'              => 'nullable|in:group',   // chosen from the group's hotel list
        ], ['accept.accepted' => 'Please accept the hotel policies to continue.', 'phone.regex' => 'Enter a valid phone number.']);
        $data['promo'] = $this->promo($data['promo'] ?? '') ?: null;

        // The rate must still be on offer for this search (a plan can close, or the promo stop working).
        if (! empty($data['rate_plan_id'])) {
            try {
                [$in, $out, $adults, $children, $rooms] = $this->booking->criteria($data);
            } catch (BookingException $e) {
                return $this->fail($e->getMessage(), 422);
            }
            $option = $this->booking->search($in, $out, $adults, $children, $rooms, $data['promo'])->first(fn ($r) => $r->type->id === (int) $data['room_type_id']);
            if (! $option?->rates->contains(fn ($o) => $o->plan?->id === (int) $data['rate_plan_id'])) {
                return $this->fail('That rate is no longer available for these dates. Please search again.', 422, 'rate_gone');
            }
        }

        try {
            $data['via_group'] = ($data['via'] ?? null) === 'group';
            $r = $this->booking->book($data);
            $this->addLuggage($r, $data['luggage'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        $due = $this->booking->depositDue($r);

        return $this->ok([
            'token'       => $r->guest_token,
            'reference'   => $r->reference,
            'deposit_due' => $due,
            'pay_url'     => $due > 0 && $paystack->enabled() ? route('book.pay', $r->guest_token) : null,
            'hold_until'  => $r->hold_expires_at?->format('D j M, g:i A'),
        ], 'Booking received! A confirmation has been sent to ' . $r->guest->email . '.', status: 201);
    }

    // ------------------------------------------------------------------

    private function type(RoomType $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'description' => $t->description,
            'capacity' => $t->capacity_label, 'max_adults' => (int) $t->max_adults, 'max_children' => (int) $t->max_children,
            'bed' => $t->bed_type, 'size_sqm' => $t->size_sqm ? (float) $t->size_sqm : null,
            'amenities' => array_values((array) $t->amenities),
            'photos' => $t->photos->map(fn ($p) => $p->url)->values(),
            'color' => $t->color,
        ];
    }

    private function query(Request $request): array
    {
        return [
            'check_in'  => (string) $request->query('check_in', today()->toDateString()),
            'check_out' => (string) $request->query('check_out', today()->addDay()->toDateString()),
            'adults'    => (int) $request->query('adults', 2),
            'children'  => (int) $request->query('children', 0),
            'rooms'     => (int) $request->query('rooms', 1),
            'promo'     => $this->promo((string) $request->query('promo', '')),
        ];
    }

    private function promo(string $code): string
    {
        return mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $code)), 0, 30);
    }

    /** The optional luggage list written on the booking form: never stops the booking. */
    private function addLuggage(\App\Models\Reservation $r, ?string $text): void
    {
        $luggage = app(\App\Services\Booking\LuggageService::class);
        if (blank($text) || ! $luggage->enabled()) {
            return;
        }
        try {
            $luggage->add($r, array_slice($luggage->parse($text), 0, \App\Services\Booking\LuggageService::MAX_ITEMS));
        } catch (\App\Exceptions\BookingException) {
            // The booking stands; the guest can fix the list on their booking page.
        }
    }
}
