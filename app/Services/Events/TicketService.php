<?php

namespace App\Services\Events;

use App\Enums\ChargeType;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Events\Event;
use App\Models\Events\EventTicket;
use App\Models\Events\EventTicketOrder;
use App\Models\Events\EventTicketType;
use App\Models\FolioCharge;
use App\Models\NightAudit;
use App\Models\Reservation;
use App\Models\Events\EventTicketPromo;
use App\Services\Billing\FolioService;
use App\Services\Booking\PaystackService;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Tickets for events the public pays to attend. A confirmed event with tickets turned on sells its ticket types at the
 * desk (paid on the spot or charged to an in-house guest's room) and in the guest app (charged to the room). Each seat
 * gets its own QR ticket, scanned once at the door. Sales never pass a type's quantity or the hall's capacity.
 *
 * Anyone can also buy online (website or guest app) and pay with Paystack: the order holds its tickets for
 * HOLD_MINUTES while they pay, and the tickets are issued when Paystack confirms the payment. Early-bird prices and
 * promo codes apply everywhere; every order with an e-mail address gets its tickets by e-mail.
 */
class TicketService
{
    /** Doors open this long before the start; tickets are refused once the event is over. */
    public const DOORS_OPEN_HOURS = 3;

    /** An online order keeps its tickets this long while the buyer pays. */
    public const HOLD_MINUTES = 20;

    public function __construct(private FolioService $folios, private PaystackService $paystack)
    {
    }

    // ── Setting up ────────────────────────────────────────────────────

    /** data: is_ticketed, ticket_info */
    public function configure(Event $event, bool $ticketed, ?string $info): void
    {
        if (! $ticketed && $event->tickets()->where('status', '!=', 'void')->exists()) {
            throw new BookingException('Tickets have been sold for this event. Cancel those orders before turning tickets off.');
        }
        $event->forceFill(['is_ticketed' => $ticketed, 'ticket_info' => $info, 'ticket_sales_open' => $ticketed && $event->ticket_sales_open])->save();
        Audit::record('events.tickets_setup', "Tickets " . ($ticketed ? 'on' : 'off') . " for event {$event->number}: {$event->title}", $event);
    }

    public function setSalesOpen(Event $event, bool $open): void
    {
        if ($open) {
            if (! $event->is_ticketed) {
                throw new BookingException('Turn tickets on for this event first.');
            }
            if ($event->status !== 'confirmed') {
                throw new BookingException('Confirm the event before selling tickets.');
            }
            if (! $event->ticketTypes()->where('is_active', true)->exists()) {
                throw new BookingException('Add at least one ticket type first.');
            }
        }
        $event->forceFill(['ticket_sales_open' => $open])->save();
        Audit::record('events.ticket_sales', 'Ticket sales ' . ($open ? 'opened' : 'closed') . " for event {$event->number}: {$event->title}", $event);
    }

    /** data: name, description, price, admits, quantity, max_per_order, sales_start_at, sales_end_at, is_active */
    public function saveType(Event $event, array $data, ?EventTicketType $type = null): EventTicketType
    {
        $admits = max(1, (int) ($data['admits'] ?? 1));
        if ($admits > $event->ticketCapacity() && $event->ticketCapacity() > 0) {
            throw new BookingException("One ticket can't admit more people than the event takes ({$event->ticketCapacity()}).");
        }
        if ($type && isset($data['quantity']) && $data['quantity'] !== null && (int) $data['quantity'] < $type->sold()) {
            throw new BookingException("{$type->sold()} {$type->name} tickets are already sold; the quantity can't go below that.");
        }
        $fields = [
            'name' => $data['name'], 'description' => $data['description'] ?? null, 'price' => round((float) $data['price'], 2),
            'admits' => $admits, 'quantity' => ($data['quantity'] ?? null) !== null && $data['quantity'] !== '' ? (int) $data['quantity'] : null,
            'max_per_order' => (int) ($data['max_per_order'] ?? 10), 'sales_start_at' => $data['sales_start_at'] ?? null,
            'sales_end_at' => $data['sales_end_at'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true),
            'early_price' => ($data['early_price'] ?? null) !== null && $data['early_price'] !== '' ? round((float) $data['early_price'], 2) : null,
            'early_until' => ($data['early_price'] ?? null) !== null && $data['early_price'] !== '' ? ($data['early_until'] ?? null) : null,
        ];
        // The price of a type with tickets sold stays as it was sold; make a new type for a new price.
        if ($type && $type->sold() > 0 && round((float) $type->price, 2) !== $fields['price']) {
            throw new BookingException("{$type->name} tickets have been sold at " . $this->folios->money((float) $type->price) . '. Add a new ticket type for the new price.');
        }
        if ($type) {
            $type->update($fields);
        } else {
            $type = $event->ticketTypes()->create($fields + ['sort_order' => (int) $event->ticketTypes()->max('sort_order') + 1]);
        }
        Audit::record('events.ticket_type', "Ticket type {$type->name} (" . $this->folios->money((float) $type->price) . ") for event {$event->number}", $event);

        return $type;
    }

    // ── Selling ───────────────────────────────────────────────────────

    /**
     * Sell tickets. lines: [ticket_type_id => quantity]. buyer: name, phone, email.
     * method: a payment method value, or "room" with the in-house reservation to charge.
     */
    public function sell(Event $event, array $lines, array $buyer, string $method, ?string $reference = null, ?int $bankAccountId = null, ?Reservation $room = null, string $channel = 'desk', ?string $key = null, ?string $promo = null): EventTicketOrder
    {
        if ($key && ($existing = EventTicketOrder::where('idempotency_key', $key)->first())) {
            return $existing;   // the same sale sent twice
        }
        $lines = $this->cleanLines($lines);
        if ($method === EventTicketOrder::ROOM) {
            if (! $room || $room->status !== ReservationStatus::CheckedIn) {
                throw new BookingException('Tickets can be charged only to a guest who is staying now.');
            }
        } elseif (! in_array(PaymentMethod::tryFrom($method), PaymentMethod::takeable(), true)) {
            throw new BookingException('Choose how the tickets are paid for.');
        }
        if (blank($buyer['name'] ?? null)) {
            throw new BookingException('Enter the buyer’s name.');
        }

        $order = DB::transaction(function () use ($event, $lines, $buyer, $method, $reference, $bankAccountId, $room, $channel, $key, $promo) {
            $event = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();   // one sale at a time per event
            $q = $this->price($event, $lines, $promo);

            $order = $event->ticketOrders()->create([
                'channel' => $channel, 'status' => 'paid', 'buyer_name' => $buyer['name'], 'buyer_phone' => $buyer['phone'] ?? null,
                'buyer_email' => $buyer['email'] ?? null, 'reservation_id' => $room?->id, 'quantity' => $q['count'], 'total' => $q['total'],
                'discount' => $q['discount'], 'promo_id' => $q['promo']?->id, 'token' => $this->newToken(),
                'method' => $method, 'reference' => $reference, 'bank_account_id' => $method === EventTicketOrder::ROOM ? null : $bankAccountId,
                'sold_by' => auth()->id(), 'paid_at' => now(), 'idempotency_key' => $key,
            ]);
            $order->forceFill(['number' => 'TO-' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)])->save();
            $this->issue($order, $q);

            $net = round($q['subtotal'] - $q['discount'], 2);
            if ($method === EventTicketOrder::ROOM && $net > 0) {
                $what = $q['types']->map(fn ($t) => $lines[$t->id] . ' × ' . $t->name)->implode(', ');
                $charge = $this->folios->postCharge($this->folios->forReservation($room), ChargeType::Service, Str::limit("Tickets {$order->number}: {$event->title} ({$what})", 250), 1, $net, today(),
                    ['source_type' => EventTicketOrder::class, 'source_id' => $order->id]);
                $order->forceFill(['folio_charge_id' => $charge->id])->save();
            }

            Audit::record('events.tickets_sold', "Sold {$q['count']} " . Str::plural('ticket', $q['count']) . " ({$order->number}, {$this->folios->money($q['total'])}, {$order->method_label}) for event {$event->number}: {$event->title}", $event, [], ['total' => $q['total']]);

            return $order->load('tickets.type');
        });
        $this->email($order);

        return $order;
    }

    /**
     * What an order would cost now: early-bird prices, a promo code, and every limit checked (type quantities,
     * per-order maximum, sales dates, the hall's capacity — counting tickets held by online orders being paid).
     * Call inside a transaction that has locked the event when the order will be saved.
     *
     * @return array{types: \Illuminate\Support\Collection, lines: array, count: int, seats: int, subtotal: float, discount: float, total: float, promo: ?EventTicketPromo, rows: array}
     */
    public function price(Event $event, array $lines, ?string $promoCode = null): array
    {
        $lines = $this->cleanLines($lines);
        if ($why = $event->ticketSalesBlocker()) {
            throw new BookingException($why);
        }
        $types = $event->ticketTypes()->whereIn('id', array_keys($lines))->get()->keyBy('id');
        if ($types->count() !== count($lines)) {
            throw new BookingException('One of those tickets is not for this event.');
        }
        $seats = 0;
        $subtotal = 0.0;
        $rows = [];
        foreach ($lines as $id => $qty) {
            $t = $types[$id];
            if ($why = $t->closedReason()) {
                throw new BookingException("{$t->name}: {$why}.");
            }
            if ($qty > $t->max_per_order) {
                throw new BookingException("At most {$t->max_per_order} {$t->name} tickets in one order.");
            }
            if (($left = $t->left()) !== null && $qty > $left) {
                throw new BookingException("Only {$left} {$t->name} " . Str::plural('ticket', $left) . ' left.');
            }
            $each = $t->priceNow();
            $seats += $qty * $t->admits;
            $subtotal += $qty * $each;
            $rows[] = ['type_id' => $t->id, 'name' => $t->name, 'quantity' => $qty, 'price' => $each, 'early_bird' => $t->isEarlyBird(), 'amount' => round($qty * $each, 2)];
        }
        $roomLeft = $event->ticketCapacity() - $event->ticketSeatsSold() - $event->ticketSeatsHeld();
        if ($event->ticketCapacity() > 0 && $seats > $roomLeft) {
            throw new BookingException('The hall has room for only ' . max(0, $roomLeft) . ' more ' . Str::plural('person', max(0, $roomLeft)) . '.');
        }
        $subtotal = round($subtotal, 2);
        $promo = null;
        if (filled($promoCode)) {
            $promo = $event->ticketPromos()->where('code', Str::upper(trim($promoCode)))->first();
            if (! $promo) {
                throw new BookingException('That promo code isn’t valid for this event.');
            }
            if ($why = $promo->closedReason()) {
                throw new BookingException($why);
            }
        }
        $discount = $promo ? $promo->discountOn($subtotal) : 0.0;
        $total = FolioCharge::computeTax(round($subtotal - $discount, 2), true)['total'];

        return ['types' => $types, 'lines' => $lines, 'count' => array_sum($lines), 'seats' => $seats, 'subtotal' => $subtotal,
            'discount' => $discount, 'total' => round((float) $total, 2), 'promo' => $promo, 'rows' => $rows];
    }

    // ── Online: anyone, paid with Paystack ──────────────────────────────

    /**
     * Start an online order. Free tickets are issued at once; otherwise the order holds its tickets for
     * HOLD_MINUTES and the answer has Paystack's payment page. buyer: name, email, phone.
     *
     * @return array{order: EventTicketOrder, pay_url: ?string}
     */
    public function startOnline(Event $event, array $lines, array $buyer, ?string $promo, string $callbackUrl, string $channel = 'web'): array
    {
        if (blank($buyer['name'] ?? null) || ! filter_var($buyer['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new BookingException('Enter your name and e-mail address: your tickets are sent there.');
        }
        $quote = $this->price($event, $lines, $promo);
        if ($quote['total'] <= 0) {
            return ['order' => $this->sell($event, $lines, $buyer, PaymentMethod::Online->value, 'Free', null, null, $channel, null, $promo), 'pay_url' => null];
        }
        if (! $this->paystack->enabled()) {
            throw new BookingException('Online payment isn’t available right now. Buy your tickets at the hotel’s front desk.');
        }
        // One buyer can't hold more than a couple of unpaid orders at once (tickets held while paying).
        if (EventTicketOrder::holding()->where('buyer_email', $buyer['email'])->count() >= 2) {
            throw new BookingException('You already have tickets waiting for payment. Finish paying for those first, or wait a few minutes for them to be released.');
        }

        $order = DB::transaction(function () use ($event, $lines, $buyer, $promo, $channel) {
            $event = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $q = $this->price($event, $lines, $promo);
            $order = $event->ticketOrders()->create([
                'channel' => $channel, 'status' => 'pending', 'buyer_name' => $buyer['name'], 'buyer_phone' => $buyer['phone'] ?? null,
                'buyer_email' => $buyer['email'], 'quantity' => $q['count'], 'lines' => $q['lines'], 'total' => $q['total'], 'discount' => $q['discount'],
                'prices' => collect($q['rows'])->mapWithKeys(fn ($r) => [$r['type_id'] => $r['price']])->all(),
                'promo_id' => $q['promo']?->id, 'method' => PaymentMethod::Online->value, 'token' => $this->newToken(),
                'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
            ]);
            $order->forceFill(['number' => 'TO-' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT), 'gateway_reference' => 'TK' . $order->id . '-' . Str::upper(Str::random(10))])->save();

            return $order;
        });

        $url = $this->paystack->initialize($order->buyer_email, (float) $order->total, (string) config('hotel.currency', 'NGN'), $order->gateway_reference, $callbackUrl,
            ['tickets' => $order->number, 'event' => $event->title, 'hotel' => config('hotel.name')]);

        return ['order' => $order, 'pay_url' => $url];
    }

    /** The pay page again for a pending order (the buyer came back). */
    public function payAgain(EventTicketOrder $order, string $callbackUrl): string
    {
        if (! $order->isPending() || $order->expires_at->isPast()) {
            throw new BookingException('This order is no longer waiting for payment. Start a new one.');
        }

        return $this->paystack->initialize($order->buyer_email, (float) $order->total, (string) config('hotel.currency', 'NGN'), $order->gateway_reference, $callbackUrl, ['tickets' => $order->number]);
    }

    /** Paystack says it was paid (callback or webhook; safe to call twice): confirm with Paystack and issue the tickets. */
    public function settle(string $reference): EventTicketOrder
    {
        $order = EventTicketOrder::where('gateway_reference', $reference)->firstOrFail();
        if ($order->status === 'paid') {
            return $order;
        }
        $v = $this->paystack->verify($reference);
        if ($v['status'] !== 'success' || $v['amount'] <= 0) {
            return $order;   // not paid (yet): it stays pending until it expires
        }

        $order = DB::transaction(function () use ($order, $v) {
            Event::whereKey($order->event_id)->lockForUpdate()->first();
            $order = EventTicketOrder::whereKey($order->id)->lockForUpdate()->first();
            if ($order->status === 'paid') {
                return $order;
            }
            $event = $order->event;
            $types = $event->ticketTypes()->whereIn('id', array_keys($order->lines ?? []))->get()->keyBy('id');
            $late = $order->status !== 'pending' || $order->expires_at?->isPast();
            $order->forceFill(['status' => 'paid', 'paid_at' => $v['paid_at'] ? Carbon::parse($v['paid_at']) : now(), 'reference' => 'Paystack ' . ($v['channel'] ?? '') . ' ' . $order->gateway_reference, 'expires_at' => null])->save();
            $this->issue($order, ['types' => $types, 'lines' => $order->lines ?? [], 'rows' => collect($order->lines ?? [])->map(fn ($q, $id) => ['type_id' => (int) $id, 'quantity' => (int) $q,
                'price' => (float) (($order->prices ?? [])[$id] ?? ($types[$id]->price ?? 0))])->values()->all(), 'promo' => $order->promo]);
            if (strtoupper($v['currency']) !== strtoupper((string) config('hotel.currency', 'NGN')) || $v['amount'] + 0.001 < (float) $order->total) {
                Audit::record('events.tickets_payment_mismatch', "Paystack {$order->gateway_reference}: expected {$order->total}, received {$v['currency']} {$v['amount']}", $event, flagged: true);
            }
            if ($late && $event->ticketCapacity() > 0 && $event->ticketSeatsSold() > $event->ticketCapacity()) {
                Audit::record('events.tickets_oversold', "Tickets {$order->number} were paid after their hold ran out and the hall is now over capacity ({$event->ticketSeatsSold()} of {$event->ticketCapacity()}). Refund or make room.", $event, flagged: true);
            }
            Audit::record('events.tickets_sold', "Sold {$order->quantity} " . Str::plural('ticket', $order->quantity) . " online ({$order->number}, {$this->folios->money((float) $order->total)}, Paystack) for event {$event->number}: {$event->title}", $event, [], ['total' => (float) $order->total]);

            return $order;
        });
        $this->email($order);

        return $order;
    }

    /** Online orders never paid let their tickets go. */
    public function expire(): int
    {
        return EventTicketOrder::where('status', 'pending')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }

    /** E-mail the buyer their tickets: a link to the page with every QR code, and one per ticket. */
    public function email(EventTicketOrder $order, bool $again = false): bool
    {
        if (! $order->buyer_email || $order->status !== 'paid' || ($order->emailed_at && ! $again)) {
            return false;
        }
        if (! $order->token) {
            $order->forceFill(['token' => $this->newToken()])->save();
        }
        $order->loadMissing('event.hall', 'tickets.type');
        try {
            Mail::send('events.tickets.email', ['order' => $order, 'event' => $order->event], function ($m) use ($order) {
                $m->to($order->buyer_email, $order->buyer_name)->subject("Your tickets: {$order->event->title} ({$order->number})");
            });
            $order->forceFill(['emailed_at' => now()])->save();

            return true;
        } catch (\Throwable $e) {
            Log::warning("Tickets {$order->number} not e-mailed: " . $e->getMessage());

            return false;
        }
    }

    private function issue(EventTicketOrder $order, array $q): void
    {
        foreach ($q['lines'] as $id => $qty) {
            $price = collect($q['rows'] ?? [])->firstWhere('type_id', (int) $id)['price'] ?? (float) ($q['types'][$id]->price ?? 0);
            for ($i = 0; $i < (int) $qty; $i++) {
                $order->tickets()->create(['event_id' => $order->event_id, 'ticket_type_id' => (int) $id, 'code' => $this->newCode(), 'price' => $price]);
            }
        }
        if ($q['promo'] ?? null) {
            EventTicketPromo::whereKey($q['promo']->id)->increment('used');
        }
    }

    private function cleanLines(array $lines): array
    {
        $lines = array_filter(array_map('intval', $lines), fn ($q) => $q > 0);
        if (! $lines) {
            throw new BookingException('Choose how many tickets.');
        }

        return $lines;
    }

    private function newToken(): string
    {
        return Str::random(48);
    }

    /** Cancel an order: its tickets stop working and the money goes back (the room charge is voided). */
    public function cancel(EventTicketOrder $order, string $reason): void
    {
        if ($order->isCancelled()) {
            throw new BookingException('This order is already cancelled.');
        }
        if ($order->tickets()->where('status', 'used')->exists()) {
            throw new BookingException('Some of these tickets have been used at the door; the order can’t be cancelled.');
        }
        if ($order->status !== 'paid') {
            throw new BookingException('Only paid orders can be cancelled; this one was never paid.');
        }
        if (NightAudit::isClosed($order->paid_at) && ! auth()->user()?->can('Change closed days')) {
            throw new BookingException('This sale is dated ' . $order->paid_at->format('D j M Y') . ', a day the night audit has closed. A manager who can change closed days must cancel it.');
        }
        DB::transaction(function () use ($order, $reason) {
            if ($order->folioCharge && ! $order->folioCharge->isVoided()) {
                $this->folios->voidCharge($order->folioCharge, "Tickets {$order->number} cancelled: {$reason}");
            }
            $order->tickets()->update(['status' => 'void']);
            $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason])->save();
            Audit::record('events.tickets_cancelled', "Cancelled tickets {$order->number} ({$this->folios->money((float) $order->total)}, {$order->method_label}) for event {$order->event->number}", $order->event, [], [], $reason, true);
        });
    }

    // ── At the door ───────────────────────────────────────────────────

    /** Look a ticket up without admitting it. state: valid | used | void | early | over | invalid */
    public function check(string $code): array
    {
        $ticket = EventTicket::with(['event.hall', 'type', 'order', 'checker'])->where('code', $this->clean($code))->first();
        if (! $ticket) {
            Audit::record('events.ticket_rejected', 'Unknown event ticket scanned', flagged: true, new: ['code' => Str::limit($code, 20)]);

            return ['state' => 'invalid', 'message' => 'Not a ticket sold here.', 'ticket' => null];
        }
        $e = $ticket->event;

        return ['ticket' => $ticket] + match (true) {
            $ticket->status === 'void' => ['state' => 'void', 'message' => 'This ticket was cancelled' . ($ticket->order->cancel_reason ? ': ' . $ticket->order->cancel_reason : '') . '.'],
            $ticket->status === 'used' => ['state' => 'used', 'message' => 'Already used at ' . $ticket->checked_in_at->format('g:i A, D j M') . ($ticket->checker ? ' (let in by ' . $ticket->checker->name . ')' : '') . '.'],
            $e->status === 'cancelled' => ['state' => 'void', 'message' => 'This event was cancelled.'],
            $e->ends_at->isPast() => ['state' => 'over', 'message' => 'This event ended ' . $e->ends_at->diffForHumans() . '.'],
            $e->starts_at->copy()->subHours(self::DOORS_OPEN_HOURS)->isFuture() => ['state' => 'early', 'message' => 'Doors open ' . $e->starts_at->copy()->subHours(self::DOORS_OPEN_HOURS)->format('g:i A, D j M') . '.'],
            default => ['state' => 'valid', 'message' => $ticket->type->name . ($ticket->type->admits > 1 ? " — admits {$ticket->type->admits}" : '') . '.'],
        };
    }

    /** Let the ticket holder in. Each ticket works once. */
    public function admit(string $code): array
    {
        return DB::transaction(function () use ($code) {
            $id = EventTicket::where('code', $this->clean($code))->lockForUpdate()->value('id');
            $result = $this->check($code);
            if ($result['state'] !== 'valid' || ! $id) {
                return $result;
            }
            $t = $result['ticket'];
            $t->forceFill(['status' => 'used', 'checked_in_at' => now(), 'checked_in_by' => auth()->id()])->save();

            return ['state' => 'admitted', 'message' => 'Let in: ' . $t->type->name . ($t->type->admits > 1 ? " — admits {$t->type->admits}" : '') . '.', 'ticket' => $t->fresh(['event.hall', 'type', 'order'])];
        });
    }

    // ── For guests ────────────────────────────────────────────────────

    /** Events a staying guest can buy tickets for, and the tickets they already have. */
    public function forGuest(Reservation $r): array
    {
        $sym = config('hotel.currency_symbol');
        $money = fn ($v) => $sym . number_format((float) $v, 2);
        $canCharge = $r->status === ReservationStatus::CheckedIn;
        $events = Event::with(['hall', 'ticketTypes' => fn ($q) => $q->where('is_active', true)])
            ->where('is_ticketed', true)->where('ticket_sales_open', true)->where('status', 'confirmed')->where('ends_at', '>', now())
            ->orderBy('starts_at')->limit(30)->get();

        return [
            'can_buy' => $canCharge,
            'blocker' => $canCharge ? null : 'You can buy tickets to your room once you’ve checked in.',
            'events'  => $events->map(fn (Event $e) => [
                'id' => $e->id, 'title' => $e->title, 'type' => $e->type_label, 'hall' => $e->hall?->name, 'when' => $e->when,
                'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'info' => $e->ticket_info,
                'ticket_types' => $e->ticketTypes->map(fn (EventTicketType $t) => [
                    'id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'price' => (float) $t->price, 'price_label' => $money($t->price),
                    'admits' => $t->admits, 'max_per_order' => $t->max_per_order, 'left' => $t->left(), 'available' => $t->closedReason() === null, 'note' => $t->closedReason(),
                ])->values(),
            ])->values(),
            'tickets' => EventTicket::with(['event', 'type'])->whereHas('order', fn ($q) => $q->where('reservation_id', $r->id))
                ->where('status', '!=', 'void')->latest('id')->limit(50)->get()->map(fn (EventTicket $t) => [
                    'code' => $t->code, 'event' => $t->event->title, 'when' => $t->event->when, 'type' => $t->type->name,
                    'status' => $t->status, 'url' => $t->url,
                ])->values(),
            'currency' => $sym,
        ];
    }

    // ------------------------------------------------------------------

    private function clean(string $code): string
    {
        $code = trim($code);
        $code = str_contains($code, '/') ? basename(parse_url($code, PHP_URL_PATH) ?: $code) : $code;   // a scanned link works too

        return Str::upper(substr($code, 0, 20));
    }

    private function newCode(): string
    {
        do {
            $code = 'T' . Str::upper(Str::random(11));
        } while (EventTicket::withoutGlobalScope('hotel')->where('code', $code)->exists());

        return $code;
    }
}
