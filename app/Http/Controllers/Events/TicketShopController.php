<?php

namespace App\Http\Controllers\Events;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Events\Event;
use App\Models\Events\EventTicketOrder;
use App\Models\Events\EventTicketType;
use App\Services\Events\TicketService;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tickets for anyone (no sign-in): an event's ticket page, buying online with Paystack (or free tickets at once),
 * and the buyer's page with every QR code, which the e-mail links to.
 */
class TicketShopController extends Controller
{
    public function __construct(private TicketService $tickets)
    {
    }

    /** GET /events/{event}/tickets */
    public function show(int $event)
    {
        $e = $this->event($event);
        // Only events on the public calendar: confirmed (or just finished), never an enquiry or a provisional booking.
        abort_unless(in_array($e->status, ['confirmed', 'completed'], true) && $e->ends_at->gt(now()->subDays(2)), 404);

        return view('events.tickets.shop', ['e' => $e->load(['hall', 'ticketTypes' => fn ($q) => $q->where('is_active', true)]), 'blocker' => $e->ticketSalesBlocker(),
            'hasPromos' => $e->ticketPromos()->where('is_active', true)->exists(), 'online' => app(\App\Services\Booking\PaystackService::class)->enabled()]);
    }

    /** POST /events/{event}/tickets/quote {lines: {type_id: qty}, promo} — the total, live as they choose. */
    public function quote(Request $request, int $event)
    {
        $e = $this->event($event);
        $d = $request->validate(['lines' => 'required|array|max:20', 'lines.*' => 'nullable|integer|min:0|max:500', 'promo' => 'nullable|string|max:30']);
        try {
            return response()->json(['success' => true, 'data' => $this->quoteJson($this->tickets->price($e, $d['lines'], $d['promo'] ?? null))]);
        } catch (BookingException $ex) {
            return response()->json(['success' => false, 'message' => $ex->getMessage()], 422);
        }
    }

    /** POST /events/{event}/tickets {lines, name, email, phone, promo} */
    public function buy(Request $request, int $event)
    {
        $e = $this->event($event);
        $d = $request->validate([
            'lines' => 'required|array|max:20', 'lines.*' => 'nullable|integer|min:0|max:500',
            'name' => 'required|string|max:120', 'email' => 'required|email|max:150', 'phone' => 'nullable|string|max:40', 'promo' => 'nullable|string|max:30',
        ]);
        try {
            $r = $this->tickets->startOnline($e, $d['lines'], ['name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'] ?? null], $d['promo'] ?? null, route('tickets.callback'));
        } catch (BookingException $ex) {
            return back()->withInput()->with('error', $ex->getMessage());
        }

        return $r['pay_url'] ? redirect()->away($r['pay_url']) : redirect()->route('tickets.mine', $r['order']->token)->with('success', 'Your tickets are ready. We’ve e-mailed them to you too.');
    }

    /** GET /my-tickets/{token} — the buyer's tickets (or the order waiting for payment). */
    public function mine(string $token)
    {
        $order = EventTicketOrder::withoutGlobalScope('hotel')->where('token', $token)->firstOrFail();
        CurrentHotel::get()->adopt($order);
        $order->load(['event.hall', 'tickets.type', 'promo']);

        return view('events.tickets.mine', ['order' => $order]);
    }

    /** GET /my-tickets/{token}/pay — back to Paystack for an order still being held. */
    public function pay(string $token)
    {
        $order = EventTicketOrder::withoutGlobalScope('hotel')->where('token', $token)->firstOrFail();
        CurrentHotel::get()->adopt($order);
        try {
            return redirect()->away($this->tickets->payAgain($order, route('tickets.callback')));
        } catch (BookingException $ex) {
            return redirect()->route('tickets.mine', $token)->with('error', $ex->getMessage());
        }
    }

    /** Paystack sends the buyer back here; the payment is confirmed with Paystack before tickets are issued. */
    public function callback(Request $request)
    {
        $reference = (string) $request->query('reference', $request->query('trxref', ''));
        $order = EventTicketOrder::withoutGlobalScope('hotel')->where('gateway_reference', $reference)->firstOrFail();
        CurrentHotel::get()->adopt($order);
        try {
            $order = $this->tickets->settle($reference);
        } catch (Throwable $ex) {
            Log::warning("Ticket payment {$reference}: " . $ex->getMessage());

            return redirect()->route('tickets.mine', $order->token)->with('error', 'We couldn’t confirm your payment yet. If you were charged, your tickets will appear here shortly — no need to pay again.');
        }

        return redirect()->route('tickets.mine', $order->token)->with($order->status === 'paid' ? 'success' : 'error',
            $order->status === 'paid' ? 'Paid — your tickets are below, and in your e-mail.' : 'The payment wasn’t completed. You can try again while your tickets are held.');
    }

    // ------------------------------------------------------------------

    private function event(int $id): Event
    {
        $e = Event::withoutGlobalScope('hotel')->where('is_ticketed', true)->findOrFail($id);
        CurrentHotel::get()->adopt($e);

        return $e;
    }

    private function quoteJson(array $q): array
    {
        $sym = config('hotel.currency_symbol');
        $m = fn ($v) => $sym . number_format((float) $v, 2);

        return [
            'rows' => collect($q['rows'])->map(fn ($r) => $r + ['amount_label' => $m($r['amount']), 'price_label' => $m($r['price'])])->values(),
            'count' => $q['count'], 'subtotal' => $q['subtotal'], 'discount' => $q['discount'], 'total' => $q['total'],
            'subtotal_label' => $m($q['subtotal']), 'discount_label' => $q['discount'] > 0 ? '− ' . $m($q['discount']) : null, 'total_label' => $q['total'] > 0 ? $m($q['total']) : 'Free',
            'promo' => $q['promo'] ? ['code' => $q['promo']->code, 'label' => $q['promo']->label] : null,
        ];
    }

    /** For the guest app. */
    public function quoteFor(Event $e, array $lines, ?string $promo): array
    {
        return $this->quoteJson($this->tickets->price($e, $lines, $promo));
    }
}
