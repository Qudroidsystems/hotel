<?php

namespace App\Http\Controllers\Booking;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking\OnlinePayment;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\Booking\OnlineBookingService;
use App\Services\Booking\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The public booking pages (spec §4). No sign-in: guests search, book and pay.
 * Every request is rate-limited in routes/web.php.
 */
class BookingController extends Controller
{
    public function __construct(private OnlineBookingService $booking)
    {
    }

    public function index(Request $request)
    {
        $types = RoomType::where('is_active', true)->with('photos')->withCount(['rooms' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('base_rate')->get()->filter(fn ($t) => $t->rooms_count > 0)->values();

        return view('booking.index', ['types' => $types, 'q' => $this->defaults($request)]);
    }

    public function search(Request $request)
    {
        $q = $this->defaults($request);
        try {
            [$in, $out, $adults, $children, $rooms] = $this->booking->criteria($q);
        } catch (BookingException $e) {
            return redirect()->route('book.index', $q)->with('error', $e->getMessage());
        }
        $results = $this->booking->search($in, $out, $adults, $children, $rooms, $q['promo']);
        $results->each(fn ($r) => $r->type->loadMissing('photos'));
        $promoProblem = $this->booking->promoProblem($q['promo'], $results, $in, $out);

        return view('booking.results', compact('results', 'q', 'in', 'out', 'adults', 'children', 'rooms', 'promoProblem'));
    }

    public function details(Request $request)
    {
        $q = $this->defaults($request);
        try {
            [$in, $out, $adults, $children, $rooms] = $this->booking->criteria($q);
            $option = $this->booking->search($in, $out, $adults, $children, $rooms, $q['promo'])->first(fn ($r) => $r->type->id === (int) $request->query('type'));
            if (! $option || ! $option->bookable) {
                throw new BookingException($option?->restriction ?: 'That room is no longer available for these dates. Please choose another.');
            }
            $rate = $option->rates->firstWhere('key', (string) $request->query('rate', 'standard'));
            if (! $rate) {
                throw new BookingException('That rate is not available for these dates. Please choose another.');
            }
        } catch (BookingException $e) {
            return redirect()->route('book.search', $q)->with('error', $e->getMessage());
        }
        $depositPct = (float) config('hotel.booking.deposit_percent', 30);
        $deposit = round($rate->total * $depositPct / 100, 2);

        return view('booking.details', compact('option', 'rate', 'q', 'in', 'out', 'adults', 'children', 'rooms', 'deposit', 'depositPct'));
    }

    public function store(Request $request, PaystackService $paystack)
    {
        // A hidden field real people never fill in: quietly drop bot submissions.
        if (filled($request->input('website'))) {
            return redirect()->route('book.index');
        }
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
            'accept'           => 'accepted',
            'pay'              => 'nullable|in:online,later',
            'rate_plan_id'     => 'nullable|integer',
            'promo'            => 'nullable|string|max:30',
        ], ['accept.accepted' => 'Please accept the hotel policies to continue.', 'phone.regex' => 'Enter a valid phone number.']);

        try {
            // Came from the group booking site's list of hotels?
            $data['via_group'] = $request->session()->get('book_via_group') === \App\Support\Tenancy\CurrentHotel::get()->hotel()?->slug;
            $r = $this->booking->book($data);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Straight to the payment page when a deposit is due and the guest chose to pay now.
        if ($this->booking->depositDue($r) > 0 && $paystack->enabled() && ($data['pay'] ?? 'online') === 'online') {
            try {
                return redirect()->away($this->booking->startPayment($r, route('book.paystack.callback')));
            } catch (BookingException $e) {
                return redirect()->route('stay.show', $r->guest_token)->with('error', $e->getMessage() . ' Your booking is held — you can pay from this page.');
            }
        }

        return redirect()->route('stay.show', $r->guest_token)->with('success', 'Booking received! A confirmation has been sent to ' . $r->guest->email . '.');
    }

    /** "Pay now" from the guest's booking page. */
    public function pay(string $token)
    {
        $r = Reservation::withoutGlobalScope('hotel')->where('guest_token', $token)->firstOrFail();
        \App\Support\Tenancy\CurrentHotel::get()->adopt($r);
        try {
            return redirect()->away($this->booking->startPayment($r, route('book.paystack.callback')));
        } catch (BookingException $e) {
            return redirect()->route('stay.show', $token)->with('error', $e->getMessage());
        }
    }

    /** Paystack sends the guest back here; the result is confirmed with Paystack before anything is recorded. */
    public function callback(Request $request)
    {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));
        $op = OnlinePayment::withoutGlobalScope('hotel')->where('reference', $reference)->first();
        abort_unless($op, 404);
        \App\Support\Tenancy\CurrentHotel::get()->adopt($op);
        $op->load('reservation');
        try {
            $op = $this->booking->settle($reference);
        } catch (Throwable $e) {
            Log::warning("Paystack callback for {$reference}: " . $e->getMessage());

            return redirect()->route('stay.show', $op->reservation->guest_token)
                ->with('error', 'We could not confirm your payment yet. If you were charged, it will show here shortly — no need to pay again.');
        }

        return redirect()->route('stay.show', $op->reservation->guest_token)->with($op->status === 'success' ? 'success' : 'error',
            $op->status === 'success' ? 'Payment received — thank you!' : 'The payment was not completed. You can try again below.');
    }

    /** Paystack server-to-server notification (signed). */
    public function webhook(Request $request, PaystackService $paystack)
    {
        if (! $paystack->validSignature($request->getContent(), $request->header('x-paystack-signature'))) {
            Log::warning('Paystack webhook with a bad signature', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 401);
        }
        $event = $request->json('event');
        $reference = (string) $request->json('data.reference');
        $known = OnlinePayment::withoutGlobalScope('hotel')->where('reference', $reference)->first();
        \App\Support\Tenancy\CurrentHotel::get()->adopt($known);
        if ($event === 'charge.success' && $known) {
            try {
                $this->booking->settle($reference);
            } catch (Throwable $e) {
                Log::error("Paystack webhook settle failed for {$reference}: " . $e->getMessage());

                return response()->json(['ok' => false], 500);   // Paystack retries
            }
        }

        return response()->json(['ok' => true]);
    }

    private function defaults(Request $request): array
    {
        return [
            'check_in'  => $request->query('check_in', $request->input('check_in', today()->toDateString())),
            'check_out' => $request->query('check_out', $request->input('check_out', today()->addDay()->toDateString())),
            'adults'    => (int) $request->query('adults', $request->input('adults', 2)),
            'children'  => (int) $request->query('children', $request->input('children', 0)),
            'rooms'     => (int) $request->query('rooms', $request->input('rooms', 1)),
            'promo'     => mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->query('promo', $request->input('promo', '')))), 0, 30),
        ];
    }
}
