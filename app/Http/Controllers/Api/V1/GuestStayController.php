<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReservationStatus as S;
use App\Exceptions\BookingException;
use App\Models\Booking\GuestRequest;
use App\Models\Reservation;
use App\Services\Booking\GuestRequestService;
use App\Services\Booking\OnlineBookingService;
use App\Services\Booking\PaystackService;
use App\Services\Loyalty\LoyaltyService;
use App\Services\Pos\GuestOrderService;
use App\Services\Booking\ArrivalService;
use App\Services\Transport\RideService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * The guest side of the mobile app: the same booking page guests open from
 * their confirmation e-mail (/stay/{token}), as JSON. A guest opens it with
 * their booking reference and the e-mail or phone they booked with, or with
 * the link from their confirmation; the stay's private token then works like
 * that link. No staff account is involved.
 */
class GuestStayController extends ApiController
{
    public function __construct(private OnlineBookingService $booking)
    {
    }

    /** POST /guest/find  {reference, contact} */
    public function find(Request $request)
    {
        $data = $request->validate(['reference' => 'required|string|max:20', 'contact' => 'required|string|max:150']);
        $r = $this->booking->find($data['reference'], $data['contact']);
        if (! $r) {
            Audit::record('guest.lookup_failed', 'Guest app booking lookup did not match (reference ' . str($data['reference'])->limit(20) . ')', new: ['ip' => $request->ip()]);

            return $this->fail('We couldn’t find a booking with those details. Check the reference in your confirmation and the e-mail or phone you booked with.', 404, 'not_found');
        }
        $this->booking->ensureToken($r);

        return $this->ok($this->stay($r->fresh()), 'Booking found.');
    }

    /** GET /guest/stays/{token} */
    public function show(string $token)
    {
        return $this->ok($this->stay($this->reservation($token)));
    }

    /** POST /guest/stays/{token}/requests  {type, details} */
    public function request(Request $request, string $token, GuestRequestService $service)
    {
        $r = $this->reservation($token);
        $data = $request->validate(['type' => 'required|in:' . implode(',', array_keys(GuestRequest::TYPES)), 'details' => 'nullable|string|max:500']);
        try {
            $req = $service->create($r, $data['type'], $data['details'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->stay($r->fresh()), "Thanks — your request for “{$req->type_label}” has been sent.", status: 201);
    }

    /** GET /guest/stays/{token}/weather — the forecast at the hotel, with the stay's days marked. */
    public function weather(string $token, \App\Services\Weather\WeatherService $weather)
    {
        return $this->ok($weather->forGuest($this->reservation($token)));
    }

    /** GET /guest/stays/{token}/menu — what the guest can order to their room. */
    public function menu(string $token, GuestOrderService $orders)
    {
        $r = $this->reservation($token);

        return $this->ok([
            'can_order' => $orders->canOrder($r),
            'blocker'   => $orders->blocker($r),
            'outlets'   => $orders->menu(),
            'currency'  => config('hotel.currency_symbol'),
        ]);
    }

    /** GET /guest/stays/{token}/events — events at the hotel with tickets on sale, and the guest's tickets. */
    public function events(string $token, \App\Services\Events\TicketService $tickets)
    {
        return $this->ok($tickets->forGuest($this->reservation($token)));
    }

    /** POST /guest/stays/{token}/tickets  {event_id, lines: [{ticket_type_id, quantity}], key} — charged to the room. */
    public function buyTickets(Request $request, string $token, \App\Services\Events\TicketService $tickets)
    {
        $r = $this->reservation($token);
        $data = $request->validate([
            'event_id'               => 'required|integer',
            'lines'                  => 'required|array|min:1|max:10',
            'lines.*.ticket_type_id' => 'required|integer',
            'lines.*.quantity'       => 'required|integer|min:1|max:50',
            'key'                    => 'nullable|string|max:64',
        ]);
        $event = \App\Models\Events\Event::find($data['event_id']);
        if (! $event) {
            return $this->fail('That event isn’t on sale.');
        }
        $lines = [];
        foreach ($data['lines'] as $l) {
            $lines[$l['ticket_type_id']] = ($lines[$l['ticket_type_id']] ?? 0) + $l['quantity'];
        }
        $r->loadMissing('guest');
        try {
            $order = $tickets->sell($event, $lines, ['name' => $r->guest?->full_name ?? 'Guest', 'phone' => $r->guest?->phone, 'email' => $r->guest?->email],
                \App\Models\Events\EventTicketOrder::ROOM, null, null, $r, 'app', ! empty($data['key']) ? 'app:' . $r->id . ':' . $data['key'] : null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }
        Audit::record('guest.tickets', "Guest bought tickets {$order->number} in the app", $r);

        return $this->ok($tickets->forGuest($r->fresh()), "{$order->quantity} " . str('ticket')->plural($order->quantity) . " for {$event->title}, charged to your room. Show the QR codes at the door.", status: 201);
    }

    /** POST /guest/stays/{token}/orders  {outlet_id, lines: [{item_id, quantity, note}], note} */
    public function order(Request $request, string $token, GuestOrderService $orders)
    {
        $r = $this->reservation($token);
        $data = $request->validate([
            'outlet_id'        => 'required|integer',
            'lines'            => 'required|array|min:1|max:' . GuestOrderService::MAX_LINES,
            'lines.*.item_id'  => 'required|integer',
            'lines.*.quantity' => 'required|integer|min:1|max:' . GuestOrderService::MAX_QUANTITY,
            'lines.*.note'     => 'nullable|string|max:120',
            'note'             => 'nullable|string|max:300',
        ]);
        try {
            $order = $orders->place($r, (int) $data['outlet_id'], $data['lines'], $data['note'] ?? null, 'app');
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->stay($r->fresh()), "Order {$order->number} sent. We’ll let you know here when the kitchen has it.", status: 201);
    }

    /** POST /guest/stays/{token}/orders/{order}/cancel — only while the kitchen hasn't accepted it. */
    public function cancelOrder(string $token, int $order, GuestOrderService $orders)
    {
        $r = $this->reservation($token);
        $o = \App\Models\Pos\GuestOrder::where('reservation_id', $r->id)->findOrFail($order);
        try {
            $orders->cancelByGuest($o);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->stay($r->fresh()), "Order {$o->number} cancelled.");
    }

    /** POST /guest/stays/{token}/arrival {lat, lng, accuracy, speed} — start sharing, or where the guest is now. */
    public function arrival(Request $request, string $token, ArrivalService $arrivals)
    {
        $r = $this->reservation($token);
        $data = $request->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180', 'accuracy' => 'nullable|numeric|min:0', 'speed' => 'nullable|numeric|min:0|max:120']);
        $was = $arrivals->current($r)?->isSharing();
        try {
            $arrivals->update($r, (float) $data['lat'], (float) $data['lng'], isset($data['accuracy']) ? (float) $data['accuracy'] : null, isset($data['speed']) ? (float) $data['speed'] : null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok(['arrival' => $arrivals->forGuest($r->fresh())], $was ? null : 'We’ve let the hotel know you’re on your way.');
    }

    /** POST /guest/stays/{token}/arrival/stop */
    public function stopArrival(string $token, ArrivalService $arrivals)
    {
        $r = $this->reservation($token);
        $arrivals->stopFor($r);

        return $this->ok(['arrival' => $arrivals->forGuest($r)], 'Stopped sharing. The hotel can no longer see where you are.');
    }

    /** GET /guest/stays/{token}/rides — the car service: places with set fares, and this stay's rides. */
    public function rides(string $token, RideService $rides)
    {
        $r = $this->reservation($token);

        return $this->ok($rides->forGuest($r) + [
            'places' => $rides->places()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'fare_label' => $p->fare !== null ? $rides->money((float) $p->fare) : null])->values(),
            'priced_by_distance' => $rides->settings()['per_km'] > 0,
            'min_notice' => $rides->settings()['min_notice'],
            'phone' => $r->guest?->phone,
            'search' => app(\App\Services\Maps\MapsService::class)->google(),   // address search (Google) is on
        ]);
    }

    /** GET /guest/stays/{token}/places?q=&lat=&lng=&session= — address search for a pick-up (Google only). */
    public function places(Request $request, string $token, \App\Services\Maps\MapsService $maps)
    {
        $this->reservation($token);
        $d = $request->validate(['q' => 'required|string|max:120', 'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180', 'session' => 'nullable|string|max:64']);

        return $this->ok($maps->search($d['q'], isset($d['lat']) ? (float) $d['lat'] : null, isset($d['lng']) ? (float) $d['lng'] : null, $d['session'] ?? null));
    }

    /** GET /guest/stays/{token}/places/{id} — a search result's position, for the price. */
    public function place(Request $request, string $token, string $id, \App\Services\Maps\MapsService $maps)
    {
        $this->reservation($token);
        $p = $maps->place($id, $request->query('session'));
        abort_unless($p, 404, 'That place couldn’t be found. Try another search.');

        return $this->ok(['lat' => $p[0], 'lng' => $p[1], 'label' => $p[2]]);
    }

    /** POST /guest/stays/{token}/rides/quote {place_id | lat, lng} */
    public function rideQuote(Request $request, string $token, RideService $rides)
    {
        $this->reservation($token);
        $d = $request->validate(['place_id' => 'nullable|integer', 'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180']);
        try {
            [$fare, $km] = $rides->quote(! empty($d['place_id']) ? \App\Models\Transport\RidePlace::find($d['place_id']) : null, $d['lat'] ?? null, $d['lng'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok(['fare' => $fare, 'fare_label' => $fare !== null ? $rides->money($fare) : 'The hotel will confirm the price', 'km' => $km]);
    }

    /** POST /guest/stays/{token}/rides */
    public function bookRide(Request $request, string $token, RideService $rides)
    {
        $r = $this->reservation($token);
        $d = $request->validate([
            'direction' => 'required|in:to_hotel,from_hotel', 'place_id' => 'nullable|integer', 'label' => 'nullable|string|max:255',
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180', 'pickup_at' => 'nullable|date',
            'passengers' => 'nullable|integer|min:1|max:20', 'bags' => 'nullable|integer|min:0|max:30', 'flight' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:300', 'payment' => 'nullable|in:room,cash,card', 'phone' => 'nullable|string|max:30',
            'google_place_id' => 'nullable|string|max:300', 'session' => 'nullable|string|max:64',
        ]);
        try {
            $ride = $rides->book($r, $d, 'app');
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->stay($r->fresh()), "Car {$ride->number} booked. We’ll show your driver here as soon as one is assigned.", status: 201);
    }

    /** POST /guest/stays/{token}/rides/{ride}/cancel */
    public function cancelRide(string $token, int $ride, RideService $rides)
    {
        $r = $this->reservation($token);
        $x = \App\Models\Transport\Ride::where('reservation_id', $r->id)->findOrFail($ride);
        try {
            $rides->cancelByGuest($x);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->stay($r->fresh()), "Car {$x->number} cancelled.");
    }

    /** POST /guest/stays/{token}/cancel */
    public function cancel(string $token)
    {
        $r = $this->reservation($token);
        try {
            $this->booking->cancelByGuest($r);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->stay($r->fresh()), 'Your booking has been cancelled.' . ($this->booking->paid($r) > 0 ? ' The hotel will contact you about the refund of your payment.' : ''));
    }

    // ------------------------------------------------------------------

    private function reservation(string $token): Reservation
    {
        abort_unless(strlen($token) === 48, 404);

        $r = Reservation::withoutGlobalScope('hotel')->where('guest_token', $token)->firstOrFail();
        \App\Support\Tenancy\CurrentHotel::get()->adopt($r);

        return $r;
    }

    private function stay(Reservation $r): array
    {
        $r->load(['guest', 'rooms.roomType', 'rooms.room', 'folio', 'guestRequests' => fn ($q) => $q->latest()->with(['housekeepingTask', 'maintenanceOrder'])]);
        app(GuestRequestService::class)->refresh($r->guestRequests);
        $sym = config('hotel.currency_symbol');
        $money = fn ($v) => $sym . number_format((float) $v, 2);
        $paystack = app(PaystackService::class);
        $loyalty = app(LoyaltyService::class);
        $orderSvc = app(GuestOrderService::class);
        $g = $r->guest;

        $depositDue = $this->booking->depositDue($r);
        $balanceDue = $this->booking->balanceDue($r);
        $open = in_array($r->status, [S::Pending, S::Confirmed, S::CheckedIn], true);
        [$state, $label] = match ($r->status) {
            S::Pending    => ['warn', 'Waiting for your deposit'],
            S::Confirmed  => ['ok', 'Confirmed'],
            S::CheckedIn  => ['ok', 'You’re staying with us'],
            S::CheckedOut => ['done', 'Checked out — thank you for staying'],
            S::Cancelled  => ['bad', 'Cancelled'],
            S::NoShow     => ['bad', 'Marked as no-show'],
        };
        $canRequest = config('hotel.booking.guest_requests', true) && $r->status === S::CheckedIn;
        $types = $r->rooms->groupBy('room_type_id')->map(fn ($l) => ($l->count() > 1 ? $l->count() . ' × ' : '') . $l->first()->roomType?->name)->implode(', ');

        return [
            'token'        => $r->guest_token,
            'reference'    => $r->reference,
            'status'       => $r->status->value,
            'status_label' => $label,
            'state'        => $state,
            'hold_until'   => $r->status === S::Pending && $r->hold_expires_at ? $r->hold_expires_at->format('D j M, g:i A') : null,
            'check_in'     => $r->check_in_date->toDateString(),
            'check_out'    => $r->check_out_date->toDateString(),
            'check_in_label'  => $r->check_in_date->format('D j M Y'),
            'check_out_label' => $r->check_out_date->format('D j M Y'),
            'nights'       => $r->nights,
            'guests_label' => $r->adults . ' adult' . ($r->adults == 1 ? '' : 's') . ($r->children ? ', ' . $r->children . ' child' . ($r->children == 1 ? '' : 'ren') : ''),
            'room_types'   => $types,
            'rooms'        => $r->rooms->pluck('room.number')->filter()->values(),
            'special_requests' => $r->special_requests,
            'guest' => [
                'first_name' => $g?->first_name,
                'name'       => $g?->full_name,
                'loyalty'    => $loyalty->enabled() && $g ? [
                    'number'     => $g->loyalty_number,
                    'tier'       => $g->loyalty_tier,
                    'tier_label' => $g->tier_label,
                    'points'     => (int) $g->loyalty_points,
                    'value_label' => $money($loyalty->pointsValue((int) $g->loyalty_points)),
                    'discount_percent' => $loyalty->discountPercent($g),
                    'group'      => $loyalty->grouped() ? (config('hotel.group_name') ?: 'the group') : null,   // points work at every hotel of it
                ] : null,
            ],
            'money' => [
                'total_label'       => $money(max((float) $r->total_amount, (float) ($r->folio?->chargesTotal() ?? 0))),
                'discount_label'    => $r->discount_amount > 0 ? $money($r->discount_amount) : null,
                'discount_reason'   => $r->discount_reason,
                'paid_label'        => $money($this->booking->paid($r)),
                'balance'           => $balanceDue,
                'balance_label'     => $money(max(0, $balanceDue)),
                'deposit_due'       => $depositDue,
                'deposit_due_label' => $depositDue > 0 ? $money($depositDue) : null,
            ],
            'pay_url'      => $open && $paystack->enabled() && $balanceDue > 0.004 ? route('book.pay', $r->guest_token) : null,
            'can_cancel'   => $this->booking->canCancel($r),
            'cancel_until' => $open ? $this->booking->checkInAt($r)->subHours((int) config('hotel.booking.cancel_hours', 48))->format('D j M, g:i A') : null,
            'qr'           => in_array($r->status, [S::Pending, S::Confirmed], true) ? route('reservations.qr', $r->qr_token) : null,
            'page_url'     => route('stay.show', $r->guest_token),
            'receipt_url'  => $r->folio && in_array($r->status, [S::CheckedIn, S::CheckedOut], true) ? route('stay.receipt', $r->guest_token) : null,   // the bill, to print or save as PDF
            'can_request'  => $canRequest,
            'request_types' => $canRequest ? collect(GuestRequest::TYPES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0]])->values() : [],
            'arrival'      => app(ArrivalService::class)->forGuest($r),
            // Chat and calls with the hotel's sections (GET …/chat for the list).
            'chat'         => ['available' => app(\App\Services\Chat\ChatService::class)->blocker($r) === null, 'unread' => (int) \App\Models\Chat\ChatConversation::where('reservation_id', $r->id)->sum('guest_unread')],
            'cars'         => app(RideService::class)->forGuest($r),
            'can_order'    => $orderSvc->canOrder($r),
            'order_note'   => $r->status === S::CheckedIn && ! $orderSvc->canOrder($r) && $orderSvc->outlets()->isNotEmpty() ? $orderSvc->blocker($r) : null,
            'orders'       => $orderSvc->forGuest($r),
            'luggage'      => app(\App\Services\Booking\LuggageService::class)->forGuest($r),   // the optional luggage list (…/luggage to change it)
            'wifi'         => app(\App\Services\Network\NetworkService::class)->forGuest($r),   // the guest's Wi-Fi code while checked in, else null
            'requests'     => $r->guestRequests->map(fn (GuestRequest $q) => [
                'id'           => $q->id,
                'type'         => $q->type,
                'label'        => $q->type_label,
                'details'      => $q->details,
                'status'       => $q->status,
                'status_label' => $q->status_label,
                'note'         => $q->staff_note,
                'time'         => $q->created_at->diffForHumans(),
            ])->values(),
            'hotel' => [
                'name'      => config('hotel.name'),
                'latitude'  => app(ArrivalService::class)->hotelPoint()[0] ?? null,
                'longitude' => app(ArrivalService::class)->hotelPoint()[1] ?? null,
                'phone'     => config('hotel.booking.contact_phone'),
                'email'     => config('hotel.booking.contact_email'),
                'address'   => config('hotel.booking.address'),
                'check_in_time'  => config('hotel.booking.check_in_time'),
                'check_out_time' => config('hotel.booking.check_out_time'),
                'policies'  => array_values((array) config('hotel.booking.policies', [])),
            ],
        ];
    }
}
