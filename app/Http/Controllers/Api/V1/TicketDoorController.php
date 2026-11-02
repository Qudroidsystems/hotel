<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Events\Event;
use App\Services\Events\TicketService;
use Illuminate\Http\Request;

/** The door in the staff app: tonight's ticketed events, and scanning tickets in. */
class TicketDoorController extends ApiController
{
    public function __construct(private TicketService $tickets)
    {
    }

    /** GET /tickets/door — events whose doors are open now (or soon), with how many are in. */
    public function index(Request $request)
    {
        $this->can($request);
        $events = Event::with('hall')->where('is_ticketed', true)->where('status', 'confirmed')
            ->where('starts_at', '<=', now()->addHours(12))->where('ends_at', '>=', now())->orderBy('starts_at')->get();

        return $this->ok($events->map(fn (Event $e) => [
            'id' => $e->id, 'title' => $e->title, 'when' => $e->when, 'hall' => $e->hall?->name,
            'doors_open' => $e->starts_at->copy()->subHours(TicketService::DOORS_OPEN_HOURS)->lte(now()),
            'doors_at' => $e->starts_at->copy()->subHours(TicketService::DOORS_OPEN_HOURS)->format('g:i A'),
            'sold' => $e->tickets()->where('status', '!=', 'void')->count(), 'in' => $e->tickets()->where('status', 'used')->count(),
            'people_in' => (int) $e->tickets()->where('event_tickets.status', 'used')->join('event_ticket_types', 'event_ticket_types.id', '=', 'event_tickets.ticket_type_id')->sum('event_ticket_types.admits'),
            'capacity' => $e->ticketCapacity(),
        ])->values());
    }

    /** POST /tickets/scan {code, admit: true|false} — check a scanned or typed ticket, and let them in. */
    public function scan(Request $request)
    {
        $this->can($request);
        $d = $request->validate(['code' => 'required|string|max:200', 'admit' => 'nullable|boolean']);
        $r = $request->boolean('admit', true) ? $this->tickets->admit($d['code']) : $this->tickets->check($d['code']);
        $t = $r['ticket'] ?? null;

        return $this->ok([
            'state' => $r['state'], 'ok' => in_array($r['state'], ['admitted', 'valid'], true), 'message' => $r['message'],
            'ticket' => $t ? [
                'code' => $t->code, 'type' => $t->type->name, 'admits' => $t->type->admits, 'event' => $t->event->title, 'event_id' => $t->event_id,
                'name' => $t->order?->buyer_name, 'order' => $t->order?->number, 'used_at' => $t->checked_in_at?->format('g:i A'),
            ] : null,
        ]);
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()?->canAny(['Check event tickets', 'Sell event tickets']), 403, 'Checking tickets isn’t part of your work.');
    }
}
