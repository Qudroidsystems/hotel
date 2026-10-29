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
use App\Services\Billing\FolioService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tickets for events the public pays to attend. A confirmed event with tickets turned on sells its ticket types at the
 * desk (paid on the spot or charged to an in-house guest's room) and in the guest app (charged to the room). Each seat
 * gets its own QR ticket, scanned once at the door. Sales never pass a type's quantity or the hall's capacity.
 */
class TicketService
{
    /** Doors open this long before the start; tickets are refused once the event is over. */
    public const DOORS_OPEN_HOURS = 3;

    public function __construct(private FolioService $folios)
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
    public function sell(Event $event, array $lines, array $buyer, string $method, ?string $reference = null, ?int $bankAccountId = null, ?Reservation $room = null, string $channel = 'desk', ?string $key = null): EventTicketOrder
    {
        if ($key && ($existing = EventTicketOrder::where('idempotency_key', $key)->first())) {
            return $existing;   // the same sale sent twice
        }
        $lines = array_filter(array_map('intval', $lines), fn ($q) => $q > 0);
        if (! $lines) {
            throw new BookingException('Choose how many tickets.');
        }
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

        return DB::transaction(function () use ($event, $lines, $buyer, $method, $reference, $bankAccountId, $room, $channel, $key) {
            $event = Event::whereKey($event->id)->lockForUpdate()->firstOrFail();   // one sale at a time per event
            if ($why = $event->ticketSalesBlocker()) {
                throw new BookingException($why);
            }
            $types = $event->ticketTypes()->whereIn('id', array_keys($lines))->get()->keyBy('id');
            if ($types->count() !== count($lines)) {
                throw new BookingException('One of those tickets is not for this event.');
            }

            $seats = 0;
            $subtotal = 0.0;
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
                $seats += $qty * $t->admits;
                $subtotal += $qty * (float) $t->price;
            }
            $room_left = $event->ticketCapacity() - $event->ticketSeatsSold();
            if ($event->ticketCapacity() > 0 && $seats > $room_left) {
                throw new BookingException('The hall has room for only ' . max(0, $room_left) . ' more ' . Str::plural('person', max(0, $room_left)) . '.');
            }
            $subtotal = round($subtotal, 2);
            $total = FolioCharge::computeTax($subtotal, true)['total'];
            $count = array_sum($lines);

            $order = $event->ticketOrders()->create([
                'channel' => $channel, 'status' => 'paid', 'buyer_name' => $buyer['name'], 'buyer_phone' => $buyer['phone'] ?? null,
                'buyer_email' => $buyer['email'] ?? null, 'reservation_id' => $room?->id, 'quantity' => $count, 'total' => $total,
                'method' => $method, 'reference' => $reference, 'bank_account_id' => $method === EventTicketOrder::ROOM ? null : $bankAccountId,
                'sold_by' => auth()->id(), 'paid_at' => now(), 'idempotency_key' => $key,
            ]);
            $order->forceFill(['number' => 'TO-' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)])->save();
            foreach ($lines as $id => $qty) {
                for ($i = 0; $i < $qty; $i++) {
                    $order->tickets()->create(['event_id' => $event->id, 'ticket_type_id' => $id, 'code' => $this->newCode(), 'price' => $types[$id]->price]);
                }
            }

            if ($method === EventTicketOrder::ROOM && $subtotal > 0) {
                $what = $types->map(fn ($t) => $lines[$t->id] . ' × ' . $t->name)->implode(', ');
                $charge = $this->folios->postCharge($this->folios->forReservation($room), ChargeType::Service, Str::limit("Tickets {$order->number}: {$event->title} ({$what})", 250), 1, $subtotal, today(),
                    ['source_type' => EventTicketOrder::class, 'source_id' => $order->id]);
                $order->forceFill(['folio_charge_id' => $charge->id])->save();
            }

            Audit::record('events.tickets_sold', "Sold {$count} " . Str::plural('ticket', $count) . " ({$order->number}, {$this->folios->money($total)}, {$order->method_label}) for event {$event->number}: {$event->title}", $event, [], ['total' => $total]);

            return $order->load('tickets.type');
        });
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
