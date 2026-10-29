<?php

namespace App\Http\Controllers\Booking;

use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking\GuestRequest;
use App\Models\HotelBankAccount;
use App\Models\Reservation;
use App\Services\Booking\GuestRequestService;
use App\Services\Booking\OnlineBookingService;
use App\Services\Booking\PaystackService;
use App\Services\Pos\GuestOrderService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * The guest's own booking page (spec §5 self-service). Reached by the private
 * link in the confirmation email, or by reference + email/phone. Shows the
 * check-in QR, what's paid and owed, and lets the guest pay, cancel within
 * the policy, and send requests during the stay.
 */
class GuestPortalController extends Controller
{
    public function __construct(private OnlineBookingService $booking)
    {
    }

    public function find()
    {
        return view('booking.find');
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['reference' => 'required|string|max:20', 'contact' => 'required|string|max:150']);
        $r = $this->booking->find($data['reference'], $data['contact']);
        if (! $r) {
            Audit::record('guest.lookup_failed', 'Guest booking lookup did not match (reference ' . str($data['reference'])->limit(20) . ')', new: ['ip' => $request->ip()]);

            return back()->withInput()->with('error', 'We couldn’t find a booking with those details. Check the reference in your confirmation and the email or phone you booked with.');
        }

        return redirect()->route('stay.show', $this->booking->ensureToken($r));
    }

    public function show(string $token, PaystackService $paystack, GuestRequestService $requests)
    {
        $r = $this->reservation($token);
        $r->load(['guest', 'rooms.roomType.photos', 'rooms.room', 'folio.charges' => fn ($q) => $q->whereNull('voided_at')->orderBy('service_date'), 'folio.payments' => fn ($q) => $q->whereNull('voided_at')->orderBy('received_at'),
            'guestRequests' => fn ($q) => $q->latest()->with(['housekeepingTask', 'maintenanceOrder'])]);
        $requests->refresh($r->guestRequests);

        $data = [
            'r'          => $r,
            'depositDue' => $this->booking->depositDue($r),
            'balanceDue' => $this->booking->balanceDue($r),
            'paid'       => $this->booking->paid($r),
            'canCancel'  => $this->booking->canCancel($r),
            'cancelBy'   => $this->booking->checkInAt($r)->subHours((int) config('hotel.booking.cancel_hours', 48)),
            'canPay'     => $paystack->enabled() && in_array($r->status, [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true),
            'testMode'   => $paystack->isTestMode(),
            'accounts'   => HotelBankAccount::ordered()->where('is_active', true)->where('show_on_invoice', true)->get(),
            'canRequest' => config('hotel.booking.guest_requests', true) && $r->status === ReservationStatus::CheckedIn,
            'canOrder'   => app(GuestOrderService::class)->canOrder($r),
            'chatOn'     => app(\App\Services\Chat\ChatService::class)->blocker($r) === null,
            'chatUnread' => (int) \App\Models\Chat\ChatConversation::where('reservation_id', $r->id)->sum('guest_unread'),
            'arrival'    => app(\App\Services\Booking\ArrivalService::class)->forGuest($r),
            'cars'       => app(\App\Services\Transport\RideService::class)->forGuest($r),
            'carPlaces'  => app(\App\Services\Transport\RideService::class)->places(),
            'luggage'    => app(\App\Services\Booking\LuggageService::class)->forGuest($r),
            'weatherOn'  => config('hotel.booking.weather', true) && in_array($r->status, [ReservationStatus::Pending, ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true),
        ];

        return view('booking.stay', $data);
    }

    /** The weather card on the booking page (loaded after the page, so a slow forecast never holds it up). */
    public function weather(string $token, \App\Services\Weather\WeatherService $weather)
    {
        return response()->json(['weather' => $weather->forGuest($this->reservation($token))]);
    }

    /** The booking page's "Share my arrival" (while the page is open): JSON. */
    public function arrival(Request $request, string $token, \App\Services\Booking\ArrivalService $arrivals)
    {
        $r = $this->reservation($token);
        if ($request->boolean('stop')) {
            $arrivals->stopFor($r);

            return response()->json(['arrival' => $arrivals->forGuest($r)]);
        }
        $data = $request->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180', 'accuracy' => 'nullable|numeric|min:0', 'speed' => 'nullable|numeric|min:0|max:120']);
        try {
            $arrivals->update($r, (float) $data['lat'], (float) $data['lng'], $data['accuracy'] ?? null, $data['speed'] ?? null);
        } catch (BookingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['arrival' => $arrivals->forGuest($r->fresh())]);
    }

    /** Book one of the hotel's cars from the booking page. */
    public function bookRide(Request $request, string $token, \App\Services\Transport\RideService $rides)
    {
        $r = $this->reservation($token);
        $d = $request->validate([
            'direction' => 'required|in:to_hotel,from_hotel', 'place_id' => 'nullable|integer', 'label' => 'nullable|string|max:255',
            'pickup_at' => 'nullable|date', 'passengers' => 'nullable|integer|min:1|max:20', 'bags' => 'nullable|integer|min:0|max:30',
            'flight' => 'nullable|string|max:20', 'notes' => 'nullable|string|max:300',
        ]);
        try {
            $ride = $rides->book($r, $d, 'web');
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(route('stay.show', $token) . '#cars')->with('success', "Car {$ride->number} booked. Your driver’s name and car show here once one is assigned.");
    }

    public function cancelRide(string $token, int $ride, \App\Services\Transport\RideService $rides)
    {
        $r = $this->reservation($token);
        $x = \App\Models\Transport\Ride::where('reservation_id', $r->id)->findOrFail($ride);
        try {
            $rides->cancelByGuest($x);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Car {$x->number} cancelled.");
    }

    /** Chat with (and call) the hotel's sections from the booking page. */
    public function chat(string $token, \App\Services\Chat\ChatService $chat)
    {
        $r = $this->reservation($token);
        $r->load('guest');

        return view('booking.chat', ['r' => $r, 'home' => $chat->forGuest($r)]);
    }

    /** Order food and drinks to the room (checked-in guests). */
    public function order(string $token, GuestOrderService $orders)
    {
        $r = $this->reservation($token);
        $r->load('guest', 'rooms.room');

        return view('booking.order', [
            'r'       => $r,
            'blocker' => $orders->blocker($r),
            'menu'    => $orders->menu(),
            'orders'  => $orders->forGuest($r),
        ]);
    }

    public function placeOrder(Request $request, string $token, GuestOrderService $orders)
    {
        $r = $this->reservation($token);
        $data = $request->validate([
            'outlet_id' => 'required|integer',
            'qty'       => 'required|array',
            'qty.*'     => 'nullable|integer|min:0|max:' . GuestOrderService::MAX_QUANTITY,
            'item_note' => 'nullable|array',
            'item_note.*' => 'nullable|string|max:120',
            'note'      => 'nullable|string|max:300',
        ]);
        $lines = collect($data['qty'])->filter(fn ($q) => (int) $q > 0)
            ->map(fn ($q, $id) => ['item_id' => (int) $id, 'quantity' => (int) $q, 'note' => $data['item_note'][$id] ?? null])->values()->all();
        try {
            $order = $orders->place($r, (int) $data['outlet_id'], $lines, $data['note'] ?? null, session('room_qr_last') === $token ? 'room' : 'web');
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('stay.order', $token)->with('success', "Order {$order->number} sent to the kitchen. This page shows when it’s accepted and on its way.");
    }

    public function cancelOrder(string $token, int $order, GuestOrderService $orders)
    {
        $r = $this->reservation($token);
        $o = \App\Models\Pos\GuestOrder::where('reservation_id', $r->id)->findOrFail($order);
        try {
            $orders->cancelByGuest($o);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Order {$o->number} cancelled.");
    }

    /** The guest's copy of their bill: every charge and payment, to print or save as PDF. */
    public function receipt(string $token)
    {
        $r = $this->reservation($token);
        abort_unless($r->folio && in_array($r->status, [ReservationStatus::CheckedIn, ReservationStatus::CheckedOut], true), 404);
        $folio = $r->folio->load(['guest', 'reservation.rooms.room']);
        $charges = $folio->charges()->whereNull('voided_at')->orderBy('service_date')->get();
        $payments = $folio->payments()->whereNull('voided_at')->with('bankAccount')->orderBy('received_at')->get();
        $summary = \App\Http\Controllers\Billing\FolioController::summary($folio, $charges, $payments);
        $bankAccounts = HotelBankAccount::forInvoices();
        $guestCopy = route('stay.show', $token);

        return view('billing.print-invoice', compact('folio', 'charges', 'payments', 'summary', 'bankAccounts', 'guestCopy'));
    }

    public function cancel(string $token)
    {
        $r = $this->reservation($token);
        try {
            $this->booking->cancelByGuest($r);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Your booking has been cancelled.' . ($this->booking->paid($r) > 0 ? ' The hotel will contact you about the refund of your payment.' : ''));
    }

    public function request(Request $request, string $token, GuestRequestService $service)
    {
        $r = $this->reservation($token);
        $data = $request->validate(['type' => 'required|in:' . implode(',', array_keys(GuestRequest::TYPES)), 'details' => 'nullable|string|max:500']);
        try {
            $req = $service->create($r, $data['type'], $data['details'] ?? null);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Thanks — your request for “{$req->type_label}” has been sent.");
    }

    private function reservation(string $token): Reservation
    {
        abort_unless(strlen($token) === 48, 404);

        $r = Reservation::withoutGlobalScope('hotel')->where('guest_token', $token)->firstOrFail();
        \App\Support\Tenancy\CurrentHotel::get()->adopt($r);

        return $r;
    }
}
