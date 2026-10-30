<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingException;
use App\Models\Events\Event;
use App\Models\Events\EventPackage;
use App\Models\Events\EventTicketType;
use App\Models\Events\Hall;
use App\Services\Booking\GuestAccountService;
use App\Services\Events\EventService;
use App\Services\Website\WebsiteService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * What anyone can browse and ask for in the guest app without a booking, as on the website: the events with
 * tickets on sale, the halls (with when each is taken, a price for a time and layout) and asking to hire a hall.
 * A hall request becomes an Enquiry under Events & Halls (it holds nothing until the events team confirms it),
 * and the events team is alerted. Signed-in guests (X-Guest-Token) also see their own hall requests.
 */
class GuestExploreController extends ApiController
{
    public function __construct(private EventService $events, private WebsiteService $site)
    {
    }

    /** GET /guest/events — confirmed events with tickets on sale, soonest first. */
    public function events()
    {
        $sym = config('hotel.currency_symbol');
        $money = fn ($v) => (float) $v > 0 ? $sym . number_format((float) $v, 2) : 'Free';

        return $this->ok($this->site->upcomingEvents(30)->map(fn (Event $e) => [
            'id' => $e->id, 'title' => $e->title, 'type' => $e->type_label, 'hall' => $e->hall?->name, 'when' => $e->when,
            'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'info' => $e->ticket_info,
            'from_label' => $e->ticketTypes->isEmpty() ? null : $money($e->ticketTypes->min('price')),
            'ticket_types' => $e->ticketTypes->map(fn (EventTicketType $t) => [
                'id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'price' => (float) $t->price, 'price_label' => $money($t->price),
                'admits' => $t->admits, 'max_per_order' => $t->max_per_order, 'left' => $t->left(), 'available' => $t->closedReason() === null, 'note' => $t->closedReason(),
            ])->values(),
        ])->values(), meta: ['currency' => $sym]);
    }

    /** GET /guest/venues — the halls, their layouts and rates, packages, and the kinds of event. */
    public function venues()
    {
        if (! Schema::hasTable('halls')) {
            return $this->ok(['halls' => [], 'packages' => [], 'event_types' => [], 'setups' => [], 'enabled' => false]);
        }
        $sym = config('hotel.currency_symbol');
        $money = fn ($v) => (float) $v > 0 ? $sym . number_format((float) $v, 0) : null;

        return $this->ok([
            'enabled' => true,
            'halls' => $this->site->halls()->map(fn (Hall $h) => [
                'id' => $h->id, 'name' => $h->name, 'description' => $h->description, 'color' => $h->color,
                'capacity_label' => $h->capacity_label,
                'max_guests' => (int) max($h->capacity_theatre, $h->capacity_banquet, $h->capacity_classroom, $h->capacity_boardroom),
                'capacities' => collect(Hall::SETUPS)->map(fn ($s, $key) => ['key' => $key, 'label' => $s[0], 'guests' => $h->capacityFor($key)])
                    ->filter(fn ($s) => $s['guests'] > 0)->values(),
                'rates' => array_values(array_filter([
                    $money($h->hourly_rate) ? ['key' => 'hourly', 'label' => 'Per hour', 'amount_label' => $money($h->hourly_rate)] : null,
                    $money($h->half_day_rate) ? ['key' => 'half_day', 'label' => 'Half day', 'amount_label' => $money($h->half_day_rate)] : null,
                    $money($h->full_day_rate) ? ['key' => 'full_day', 'label' => 'Full day', 'amount_label' => $money($h->full_day_rate)] : null,
                ])),
                'from_label' => $money(collect([$h->hourly_rate, $h->half_day_rate, $h->full_day_rate])->filter(fn ($v) => (float) $v > 0)->min()),
            ])->values(),
            'packages' => EventPackage::where('is_active', true)->orderBy('price_per_person')->get()->map(fn (EventPackage $p) => [
                'id' => $p->id, 'name' => $p->name, 'description' => $p->description,
                'price' => (float) $p->price_per_person, 'price_label' => $sym . number_format((float) $p->price_per_person, 0) . ' per guest',
            ])->values(),
            'event_types' => collect(Event::TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'setups' => collect(Hall::SETUPS)->map(fn ($s, $key) => ['key' => $key, 'label' => $s[0]])->values(),
            'currency' => $sym,
        ]);
    }

    /**
     * GET /guest/venues/{hall}/availability?starts_at&ends_at&setup&guests&package_id&rate — whether the hall is
     * free then, and an estimate. Without times: the taken days of the next 90 (no names).
     */
    public function availability(Request $request, int $hall)
    {
        $h = Hall::active()->find($hall);
        if (! $h) {
            return $this->fail('That hall isn’t available.', 404);
        }
        $taken = Event::holding()->where('hall_id', $h->id)->where('ends_at', '>', now())->where('starts_at', '<', now()->addDays(90))
            ->orderBy('starts_at')->get(['starts_at', 'ends_at'])
            ->map(fn ($e) => ['from' => $e->starts_at->toIso8601String(), 'to' => $e->ends_at->toIso8601String(), 'label' => $e->starts_at->format('D j M, g:i A') . ' – ' . $e->ends_at->format($e->starts_at->isSameDay($e->ends_at) ? 'g:i A' : 'D j M, g:i A')])
            ->values();
        if (! $request->filled('starts_at') || ! $request->filled('ends_at')) {
            return $this->ok(['taken' => $taken]);
        }
        try {
            $start = Carbon::parse((string) $request->query('starts_at'));
            $end = Carbon::parse((string) $request->query('ends_at'));
        } catch (\Throwable) {
            return $this->fail('Choose a date and times.');
        }
        if ($end->lte($start)) {
            return $this->fail('The event must end after it starts.');
        }
        $free = ! Event::holding()->where('hall_id', $h->id)->overlapping($start, $end)->exists();
        $rate = in_array($request->query('rate'), ['hourly', 'half_day', 'full_day'], true) ? $request->query('rate') : $this->bestRate($h, $start, $end);
        $hire = $this->events->hallCharge($h, $rate, $start, $end);
        $guests = max(0, (int) $request->query('guests', 0));
        $package = $request->filled('package_id') ? EventPackage::where('is_active', true)->find((int) $request->query('package_id')) : null;
        $catering = $package ? round((float) $package->price_per_person * $guests, 2) : 0;
        $cap = $h->capacityFor($request->query('setup'));
        $sym = config('hotel.currency_symbol');

        return $this->ok([
            'free' => $free,
            'why_not' => $free ? (($cap && $guests > $cap) ? "{$h->name} takes {$cap} guests in that layout." : null) : 'The hall is already booked for part of that time. Try another time or hall.',
            'fits' => ! ($cap && $guests > $cap),
            'rate' => $rate,
            'hire_label' => $sym . number_format($hire, 2),
            'catering_label' => $package ? $sym . number_format($catering, 2) : null,
            'estimate' => round($hire + $catering, 2),
            'estimate_label' => $sym . number_format($hire + $catering, 2),
            'note' => 'An estimate before taxes and extras. The events team confirms the final quote.',
            'taken' => $taken,
        ]);
    }

    /** POST /guest/venues/requests — ask to hire a hall: an enquiry the events team follows up. */
    public function request(Request $request, GuestAccountService $accounts)
    {
        $data = $request->validate([
            'hall_id'         => 'required|integer',
            'title'           => 'required|string|max:150',
            'event_type'      => 'required|string|in:' . implode(',', array_keys(Event::TYPES)),
            'starts_at'       => 'required|date|after:now',
            'ends_at'         => 'required|date|after:starts_at',
            'setup'           => 'nullable|string|in:' . implode(',', array_keys(Hall::SETUPS)),
            'guests'          => 'required|integer|min:1|max:5000',
            'package_id'      => 'nullable|integer',
            'name'            => 'required|string|max:120',
            'email'           => 'required|email:rfc|max:150',
            'phone'           => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'notes'           => 'nullable|string|max:2000',
        ], ['phone.regex' => 'Enter a valid phone number.', 'starts_at.after' => 'Choose a time from now on.']);

        $hall = Hall::active()->find($data['hall_id']);
        if (! $hall) {
            return $this->fail('That hall isn’t available.');
        }
        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        if (Event::holding()->where('hall_id', $hall->id)->overlapping($start, $end)->exists()) {
            return $this->fail('The hall is already booked for part of that time. Try another time or hall.', 422, 'hall_taken');
        }
        $package = ! empty($data['package_id']) ? EventPackage::where('is_active', true)->find($data['package_id']) : null;

        try {
            $event = $this->events->save([
                'title' => $data['title'], 'event_type' => $data['event_type'], 'status' => 'enquiry', 'hall_id' => $hall->id,
                'starts_at' => $start, 'ends_at' => $end, 'setup' => $data['setup'] ?? null, 'guests_expected' => $data['guests'],
                'organiser_name' => $data['name'], 'organiser_phone' => $data['phone'], 'organiser_email' => mb_strtolower($data['email']),
                'package_id' => $package?->id, 'hall_rate_type' => $this->bestRate($hall, $start, $end),
                'requirements' => $data['notes'] ?? null, 'internal_notes' => 'Asked for in the guest app.',
            ]);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }
        Audit::record('events.app_request', "Hall request from the guest app: {$event->number} {$event->title} ({$hall->name}, {$event->when})", $event);
        app(\App\Services\Mobile\StaffAlerts::class)->notify('Manage events', [
            'title' => 'New hall request: ' . $hall->name,
            'body' => "{$data['name']} · {$event->title} · {$event->when} · {$data['guests']} guests",
            'url' => route('events.show', $event), 'thread' => "event:{$event->id}", 'kind' => 'event', 'item_id' => $event->id,
        ]);
        try {
            $this->site->receive([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'subject' => "Hall request {$event->number}: {$event->title}",
                'message' => $data['notes'] ?? '', 'date' => $start->toDateString(), 'time' => $start->format('H:i') . '–' . $end->format('H:i'),
                'guests' => (string) $data['guests'], 'event_type' => Event::TYPES[$data['event_type']], 'hall' => $hall->name, 'source' => 'Guest app',
            ], 'event', $request->ip());
        } catch (\Throwable) {
            // The enquiry is saved under Events & Halls either way.
        }

        return $this->ok($this->summary($event->fresh('hall')), "Thank you! Your request for {$hall->name} is with our events team. They’ll call or e-mail you with a quote.", status: 201);
    }

    /** GET /guest/venues/requests — the signed-in guest's hall requests (made with their e-mail or phone). */
    public function myRequests(Request $request, GuestAccountService $accounts)
    {
        $s = $accounts->session($request->header('X-Guest-Token'));
        abort_unless($s, 401, 'Please sign in again.');
        $contact = $s->contact;
        $q = Event::with('hall')->latest('starts_at')->limit(30);
        if (str_contains($contact, '@')) {
            $q->whereRaw('LOWER(organiser_email) = ?', [$contact]);
        } else {
            $q->where('organiser_phone', 'like', '%' . substr(preg_replace('/\D/', '', $contact), -9));
        }

        return $this->ok($q->get()->map(fn (Event $e) => $this->summary($e))->values());
    }

    // ------------------------------------------------------------------

    /** Hourly for a short booking, half day up to 5 hours, otherwise the full day (only rates the hall has). */
    private function bestRate(Hall $h, Carbon $start, Carbon $end): string
    {
        $hours = $start->diffInMinutes($end) / 60;
        if ($hours <= 3 && (float) $h->hourly_rate > 0) {
            return 'hourly';
        }
        if ($hours <= 5 && (float) $h->half_day_rate > 0) {
            return 'half_day';
        }

        return (float) $h->full_day_rate > 0 ? 'full_day' : ((float) $h->half_day_rate > 0 ? 'half_day' : 'hourly');
    }

    private function summary(Event $e): array
    {
        $state = match ($e->status) {
            'confirmed', 'completed' => 'ok', 'tentative' => 'warn', 'cancelled' => 'bad', default => 'info',
        };

        return [
            'id' => $e->id, 'number' => $e->number, 'title' => $e->title, 'type' => $e->type_label, 'hall' => $e->hall?->name,
            'when' => $e->when, 'starts_at' => $e->starts_at->toIso8601String(), 'guests' => (int) $e->guests_expected,
            'status' => $e->status, 'status_label' => $e->status === 'enquiry' ? 'With our events team' : $e->status_label, 'state' => $state,
            'total_label' => (float) $e->total > 0 && $e->status !== 'enquiry' ? config('hotel.currency_symbol') . number_format((float) $e->total, 2) : null,
        ];
    }
}
