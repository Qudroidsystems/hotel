<?php

namespace App\Http\Controllers\Wellness;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Wellness\WellnessBooking;
use App\Models\Wellness\WellnessService;
use App\Models\Wellness\WellnessVenue;
use App\Services\Wellness\WellnessBookings;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The public spa, gym & pool page: visitors book and pay online; their booking page; Paystack's return. */
class WellnessShopController extends Controller
{
    public function __construct(private WellnessBookings $wellness)
    {
    }

    public function index()
    {
        abort_unless($this->wellness->enabled(), 404);

        return view('wellness.public', ['venues' => $this->wellness->catalogue(true), 'days' => (int) config('hotel.wellness.days_ahead', 30)]);
    }

    /** Free times for the public form (JSON). */
    public function times(Request $request)
    {
        $data = $request->validate(['venue_id' => 'required|integer', 'service_id' => 'nullable|integer', 'date' => 'required|date', 'people' => 'nullable|integer|min:1|max:' . WellnessBookings::MAX_PEOPLE]);
        $v = WellnessVenue::active()->where('visitors', true)->findOrFail($data['venue_id']);
        $s = ! empty($data['service_id']) ? WellnessService::where('venue_id', $v->id)->where('is_active', true)->find($data['service_id']) : null;
        $people = (int) ($data['people'] ?? 1);

        return response()->json([
            'slots' => $this->wellness->slots($v, $s, Carbon::parse($data['date']), $people),
            'total' => $this->wellness->money($this->wellness->price($v, $s, $people, true)),
        ]);
    }

    public function book(Request $request)
    {
        abort_unless($this->wellness->enabled(), 404);
        $data = $request->validate([
            'venue_id' => 'required|integer', 'service_id' => 'nullable|integer', 'date' => 'required|date', 'time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'people' => 'nullable|integer|min:1|max:' . WellnessBookings::MAX_PEOPLE,
            'name' => 'required|string|max:120', 'email' => 'required|email|max:150', 'phone' => 'required|string|max:30', 'note' => 'nullable|string|max:300',
        ]);
        $v = WellnessVenue::active()->findOrFail($data['venue_id']);
        $s = ! empty($data['service_id']) ? WellnessService::where('venue_id', $v->id)->find($data['service_id']) : null;
        try {
            $r = $this->wellness->bookVisitor($v, $s, Carbon::parse($data['date'] . ' ' . $data['time']), (int) ($data['people'] ?? 1),
                ['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone']], $data['note'] ?? null, route('wellness.callback'));
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $r['pay_url'] ? redirect()->away($r['pay_url'])
            : redirect()->route('wellness.mine', $r['booking']->token)->with('success', 'Booked — see you then. We’ve e-mailed you the details.');
    }

    public function mine(string $token)
    {
        $b = $this->find($token);

        return view('wellness.mine', ['b' => $b->load('venue', 'service', 'therapist'), 'svc' => $this->wellness]);
    }

    public function pay(string $token)
    {
        $b = $this->find($token);
        try {
            return redirect()->away($this->wellness->payAgain($b, route('wellness.callback')));
        } catch (BookingException $e) {
            return redirect()->route('wellness.mine', $token)->with('error', $e->getMessage());
        }
    }

    public function cancel(string $token)
    {
        $b = $this->find($token);
        try {
            $this->wellness->cancelByGuest($b);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Cancelled.');
    }

    public function callback(Request $request)
    {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));
        $b = WellnessBooking::withoutGlobalScope('hotel')->where('gateway_reference', $reference)->firstOrFail();
        CurrentHotel::get()->adopt($b);
        try {
            $b = $this->wellness->settle($reference);
        } catch (Throwable $e) {
            Log::warning("Wellness payment {$reference}: " . $e->getMessage());

            return redirect()->route('wellness.mine', $b->token)->with('error', 'We couldn’t confirm your payment yet. If you were charged, your booking will show as paid shortly — no need to pay again.');
        }

        return redirect()->route('wellness.mine', $b->token)->with($b->paid_at ? 'success' : 'error', $b->paid_at ? 'Paid — you’re booked. The details are in your e-mail.' : 'The payment wasn’t completed. You can try again while your time is held.');
    }

    private function find(string $token): WellnessBooking
    {
        abort_unless(strlen($token) === 48, 404);
        $b = WellnessBooking::withoutGlobalScope('hotel')->where('token', $token)->firstOrFail();
        CurrentHotel::get()->adopt($b);

        return $b;
    }
}
