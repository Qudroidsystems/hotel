<?php

namespace App\Http\Controllers\Events;

use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Events\Event;
use App\Models\Events\EventTicket;
use App\Models\Events\EventTicketOrder;
use App\Models\Events\EventTicketType;
use App\Models\Reservation;
use App\Services\Events\TicketService;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Event tickets: ticket types, selling at the desk, the orders list, the door scanner, and the ticket a buyer opens. */
class TicketController extends Controller
{
    public function __construct(private TicketService $tickets)
    {
        $this->middleware('auth')->except('public');
        $this->middleware('permission:Manage events', ['only' => ['configure', 'sales', 'storeType', 'updateType']]);
        $this->middleware('permission:Sell event tickets', ['only' => ['sell', 'cancel']]);
        $this->middleware('permission:Sell event tickets|View events', ['only' => ['index', 'order']]);
        $this->middleware('permission:Check event tickets|Sell event tickets', ['only' => ['door', 'admit']]);
    }

    public function index(Request $request)
    {
        $orders = EventTicketOrder::with(['event', 'reservation'])
            ->when($request->query('event'), fn ($q, $id) => $q->where('event_id', $id))
            ->when($request->query('q'), function ($q, $t) {
                $q->where(fn ($w) => $w->where('number', 'like', "%{$t}%")->orWhere('buyer_name', 'like', "%{$t}%")->orWhere('buyer_phone', 'like', "%{$t}%")
                    ->orWhereHas('tickets', fn ($k) => $k->where('code', strtoupper(trim($t)))));
            })
            ->latest('id')->paginate(30)->withQueryString();
        $events = Event::where('is_ticketed', true)->orderByDesc('starts_at')->limit(50)->get();

        return view('events.tickets.index', ['pagetitle' => 'Event Tickets', 'orders' => $orders, 'events' => $events]);
    }

    public function configure(Request $request, Event $event)
    {
        $data = $request->validate(['is_ticketed' => 'required|boolean', 'ticket_info' => 'nullable|string|max:1000']);

        return $this->attempt(fn () => $this->tickets->configure($event, (bool) $data['is_ticketed'], $data['ticket_info'] ?? null), 'Saved.');
    }

    public function sales(Request $request, Event $event)
    {
        $open = $request->boolean('open');

        return $this->attempt(fn () => $this->tickets->setSalesOpen($event, $open), $open ? 'Tickets are on sale.' : 'Ticket sales closed.');
    }

    public function storeType(Request $request, Event $event)
    {
        $data = $this->typeData($request);

        return $this->attempt(fn () => $this->tickets->saveType($event, $data), "{$data['name']} tickets added.");
    }

    public function updateType(Request $request, Event $event, EventTicketType $type)
    {
        abort_unless($type->event_id === $event->id, 404);
        $data = $this->typeData($request);

        return $this->attempt(fn () => $this->tickets->saveType($event, $data, $type), 'Saved.');
    }

    public function sell(Request $request, Event $event)
    {
        $data = $request->validate([
            'lines'           => 'required|array|max:20',
            'lines.*'         => 'nullable|integer|min:0|max:500',
            'buyer_name'      => 'required|string|max:120',
            'buyer_phone'     => 'nullable|string|max:40',
            'buyer_email'     => 'nullable|email|max:150',
            'method'          => ['required', Rule::in(array_merge([EventTicketOrder::ROOM], array_map(fn ($m) => $m->value, PaymentMethod::takeable())))],
            'reservation_id'  => 'nullable|required_if:method,room|integer',
            'reference'       => 'nullable|string|max:100',
            'bank_account_id' => 'nullable|integer|exists:hotel_bank_accounts,id',
            'key'             => 'nullable|string|max:64',
        ]);
        $room = $data['method'] === EventTicketOrder::ROOM ? Reservation::find($data['reservation_id']) : null;
        try {
            $order = $this->tickets->sell($event, $data['lines'], ['name' => $data['buyer_name'], 'phone' => $data['buyer_phone'] ?? null, 'email' => $data['buyer_email'] ?? null],
                $data['method'], $data['reference'] ?? null, $data['bank_account_id'] ?? null, $room, 'desk', $data['key'] ?? null);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('events.tickets.order', $order)->with('success', "{$order->number}: {$order->quantity} " . str('ticket')->plural($order->quantity) . ' sold. Print them or send the links.');
    }

    /** The order's tickets, to print or send. */
    public function order(EventTicketOrder $order)
    {
        $order->load(['event.hall', 'tickets.type', 'reservation.rooms.room', 'seller']);

        return view('events.tickets.order', compact('order'));
    }

    public function cancel(Request $request, EventTicketOrder $order)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->tickets->cancel($order, $data['reason']), "{$order->number} cancelled." . ($order->isRoomCharge() ? ' The room charge was voided.' : ' Give the buyer back ' . config('hotel.currency_symbol') . number_format((float) $order->total, 2) . '.'));
    }

    /** The door: scan or type a ticket. */
    public function door(Request $request)
    {
        $result = $request->filled('code') ? $this->tickets->check((string) $request->query('code')) : session('ticket_result');
        $today = Event::with('hall')->where('is_ticketed', true)->where('status', 'confirmed')
            ->where('starts_at', '<=', now()->addHours(TicketService::DOORS_OPEN_HOURS))->where('ends_at', '>=', now())->get()
            ->map(fn ($e) => ['event' => $e, 'sold' => $e->tickets()->where('status', '!=', 'void')->count(), 'in' => $e->tickets()->where('status', 'used')->count()]);

        return view('events.tickets.door', ['pagetitle' => 'Check Tickets', 'result' => $result, 'today' => $today, 'auto' => $request->boolean('auto', (bool) session('ticket_auto'))]);
    }

    public function admit(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:200', 'auto' => 'nullable|boolean']);
        $result = $this->tickets->admit($data['code']);

        return redirect()->route('events.tickets.door', $request->boolean('auto') ? ['auto' => 1] : [])
            ->with('ticket_result', $result)->with('ticket_auto', $request->boolean('auto'));
    }

    /** The ticket as its holder sees it (no sign-in: the code is the key). */
    public function public(string $code)
    {
        abort_unless(preg_match('/^T[A-Z0-9]{11}$/', $code) === 1, 404);
        $ticket = EventTicket::withoutGlobalScope('hotel')->where('code', $code)->firstOrFail();
        CurrentHotel::get()->adopt($ticket);
        $ticket->load(['event.hall', 'type', 'order']);

        return view('events.tickets.public', compact('ticket'));
    }

    // ------------------------------------------------------------------

    private function typeData(Request $request): array
    {
        $data = $request->validate([
            'name'           => 'required|string|max:80',
            'description'    => 'nullable|string|max:255',
            'price'          => 'required|numeric|min:0|max:100000000',
            'admits'         => 'nullable|integer|min:1|max:1000',
            'quantity'       => 'nullable|integer|min:1|max:100000',
            'max_per_order'  => 'nullable|integer|min:1|max:500',
            'sales_start_at' => 'nullable|date',
            'sales_end_at'   => 'nullable|date|after:sales_start_at',
            'is_active'      => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        return $data;
    }

    private function attempt(callable $action, string $success)
    {
        try {
            $action();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    /** In-house guests a sale can be charged to. */
    public static function inHouse()
    {
        return Reservation::with(['guest', 'rooms.room'])->where('status', ReservationStatus::CheckedIn->value)->orderBy('id')->get();
    }
}
