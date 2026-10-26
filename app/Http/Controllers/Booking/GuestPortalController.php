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
        ];

        return view('booking.stay', $data);
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
