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

        return Reservation::where('guest_token', $token)->firstOrFail();
    }

    private function stay(Reservation $r): array
    {
        $r->load(['guest', 'rooms.roomType', 'rooms.room', 'folio', 'guestRequests' => fn ($q) => $q->latest()->with(['housekeepingTask', 'maintenanceOrder'])]);
        app(GuestRequestService::class)->refresh($r->guestRequests);
        $sym = config('hotel.currency_symbol');
        $money = fn ($v) => $sym . number_format((float) $v, 2);
        $paystack = app(PaystackService::class);
        $loyalty = app(LoyaltyService::class);
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
            'can_request'  => $canRequest,
            'request_types' => $canRequest ? collect(GuestRequest::TYPES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0]])->values() : [],
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
