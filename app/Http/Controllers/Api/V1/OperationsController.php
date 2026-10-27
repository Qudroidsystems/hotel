<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingException;
use App\Models\Company;
use App\Models\Events\Event;
use App\Models\Folio;
use App\Models\NightAudit;
use App\Models\Reservation;
use App\Services\Companies\CompanyLedger;
use App\Services\Events\EventService;
use App\Services\NightAudit\NightAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Staff app: closing the day (night audit), today's and coming events, and moving a guest's bill to a
 * company. Same services and permissions as the website.
 */
class OperationsController extends ApiController
{
    // ── Night audit ───────────────────────────────────────────────────

    /** GET /night-audit — the business day, what to check before closing it, and the day so far. */
    public function nightAudit(Request $request, NightAuditService $audits)
    {
        abort_unless($request->user()->canAny(['Run night audit', 'View night audit reports']), 403);
        $date = NightAudit::businessDate();
        $canClose = $date->lte(today());
        $checks = $canClose ? $audits->checks($date) : null;

        return $this->ok([
            'business_date' => $date->toDateString(),
            'business_date_label' => $date->format('l j F Y'),
            'days_behind' => $date->lt(today()) ? (int) $date->diffInDays(today()) : 0,
            'can_close' => $canClose && $request->user()->can('Run night audit'),
            'auto_close' => (bool) config('hotel.night_audit.auto_close') ? (string) config('hotel.night_audit.auto_close_at') : null,
            'checks' => $checks ? [
                'arrivals' => $checks['arrivals']->map(fn (Reservation $r) => [
                    'id' => $r->id, 'reference' => $r->reference, 'guest' => $r->guest?->full_name, 'status_label' => $r->status->label(),
                    'due' => $r->check_in_date->format('D j M'), 'rooms' => $r->rooms->pluck('room.number')->filter()->values(),
                ])->values(),
                'overstays' => $checks['overstays']->map(fn (Reservation $r) => [
                    'id' => $r->id, 'reference' => $r->reference, 'guest' => $r->guest?->full_name,
                    'due_out' => $r->check_out_date->format('D j M'), 'rooms' => $r->rooms->pluck('room.number')->filter()->values(),
                ])->values(),
                'open_tills' => $checks['shifts']->map(fn ($s) => ['name' => $s->user?->name, 'station' => $s->station, 'since' => $s->opened_at?->format('D g:i A')])->values(),
                'pos_attention' => $checks['pos'],
                'dirty_rooms' => $checks['dirty'],
            ] : null,
            'today' => $canClose ? $this->summary($audits->report($date)) : null,
            'recent' => NightAudit::latest('business_date')->limit(7)->get()->map(fn ($a) => ['id' => $a->id, 'date' => $a->business_date->toDateString(), 'label' => $a->business_date->format('D j M')] + $this->summary($a->report))->values(),
        ]);
    }

    /** POST /night-audit/close {date, no_shows[], notes} */
    public function closeDay(Request $request, NightAuditService $audits)
    {
        abort_unless($request->user()->can('Run night audit'), 403);
        $data = $request->validate(['date' => 'required|date', 'no_shows' => 'nullable|array', 'no_shows.*' => 'integer', 'notes' => 'nullable|string|max:1000']);
        try {
            $audit = $audits->close(Carbon::parse($data['date']), $data['no_shows'] ?? [], $data['notes'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(['id' => $audit->id, 'date' => $audit->business_date->toDateString()] + $this->summary($audit->report), $audit->business_date->format('D j M') . ' is closed.');
    }

    /** GET /night-audit/{audit} — a closed day's report. */
    public function auditReport(Request $request, NightAudit $audit)
    {
        abort_unless($request->user()->canAny(['Run night audit', 'View night audit reports']), 403);

        return $this->ok(['id' => $audit->id, 'date' => $audit->business_date->toDateString(), 'label' => $audit->business_date->format('l j F Y'),
            'closed_by' => $audit->automatic ? 'Automatically' : $audit->closer?->name, 'closed_at' => $audit->closed_at?->toIso8601String(),
            'notes' => $audit->notes, 'report' => $audit->report] + $this->summary($audit->report));
    }

    private function summary(array $r): array
    {
        return [
            'occupancy' => data_get($r, 'rooms.occupancy', 0), 'rooms_sold' => data_get($r, 'rooms.sold', 0), 'rooms_for_sale' => data_get($r, 'rooms.sellable', 0),
            'adr' => data_get($r, 'rooms.adr', 0), 'revpar' => data_get($r, 'rooms.revpar', 0),
            'revenue' => data_get($r, 'revenue.total', 0), 'collected' => data_get($r, 'collections.total', 0),
            'arrivals' => data_get($r, 'guests.arrivals', 0), 'departures' => data_get($r, 'guests.departures', 0), 'no_shows' => data_get($r, 'guests.no_shows', 0),
            'owed_after_stay' => data_get($r, 'owed.after_stay', 0),
        ];
    }

    // ── Events ────────────────────────────────────────────────────────

    /** GET /events?when=today|upcoming — events for the front desk, kitchen and setup crew. */
    public function events(Request $request)
    {
        abort_unless($request->user()->canAny(['View events', 'Manage events']), 403);
        $when = $request->query('when') === 'upcoming' ? 'upcoming' : 'today';
        $q = Event::with(['hall', 'company', 'payments'])->where('status', '!=', 'cancelled');
        $when === 'today'
            ? $q->where('starts_at', '<', today()->addDay())->where('ends_at', '>', today())
            : $q->where('starts_at', '>=', today()->addDay())->where('starts_at', '<', today()->addDays(30));

        return $this->ok($q->orderBy('starts_at')->limit(100)->get()->map(fn (Event $e) => $this->eventRow($e))->values(), meta: ['when' => $when]);
    }

    /** GET /events/{event} */
    public function event(Request $request, Event $event)
    {
        abort_unless($request->user()->canAny(['View events', 'Manage events']), 403);
        $event->load(['hall', 'company', 'package', 'items', 'payments']);

        return $this->ok($this->eventRow($event) + [
            'requirements' => $event->requirements,
            'internal_notes' => $event->internal_notes,
            'package' => $event->package ? ['name' => $event->package->name, 'includes' => $event->package->description, 'price' => (float) $event->package_price] : null,
            'items' => $event->items->map(fn ($i) => ['description' => $i->description, 'kind' => $i->kind, 'quantity' => (float) $i->quantity, 'amount' => (float) $i->amount])->values(),
            'organiser_phone' => $event->organiser_phone, 'organiser_email' => $event->organiser_email,
            'actions' => $request->user()->can('Manage events') ? $this->nextStatuses($event->status) : [],
        ]);
    }

    /** POST /events/{event}/status {status, reason} */
    public function eventStatus(Request $request, Event $event, EventService $events)
    {
        abort_unless($request->user()->can('Manage events'), 403);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Event::STATUSES))], 'reason' => 'nullable|string|max:255']);
        try {
            $events->setStatus($event, $data['status'], $data['reason'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($this->eventRow($event->fresh(['hall', 'company', 'payments'])), "{$event->title} is now " . strtolower($event->fresh()->status_label) . '.');
    }

    private function eventRow(Event $e): array
    {
        return [
            'id' => $e->id, 'number' => $e->number, 'title' => $e->title, 'type' => $e->type_label,
            'status' => $e->status, 'status_label' => $e->status_label,
            'hall' => $e->hall?->name, 'hall_color' => $e->hall?->color,
            'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(), 'when' => $e->when,
            'guests' => (int) $e->guests_expected, 'setup' => $e->setup,
            'organiser' => $e->company?->name ?? $e->organiser_name, 'contact' => $e->organiser_name,
            'total' => (float) $e->total, 'balance' => $e->balance(),
            'url' => route('events.show', $e),
        ];
    }

    private function nextStatuses(string $status): array
    {
        $labels = ['tentative' => 'Hold as provisional', 'confirmed' => 'Confirm', 'completed' => 'Mark as done', 'enquiry' => 'Back to enquiry', 'cancelled' => 'Cancel'];
        $next = ['enquiry' => ['tentative', 'confirmed', 'cancelled'], 'tentative' => ['confirmed', 'enquiry', 'cancelled'], 'confirmed' => ['completed', 'tentative', 'cancelled'], 'completed' => ['confirmed'], 'cancelled' => ['enquiry']][$status] ?? [];

        return array_map(fn ($s) => ['status' => $s, 'label' => $labels[$s], 'needs_reason' => $s === 'cancelled'], $next);
    }

    // ── Group (several hotels) ────────────────────────────────────────

    /** GET /group — every hotel today, for owners and group managers. */
    // ── Driver mode ───────────────────────────────────────────────────

    /** GET /driver — the driver's car, duty, the ride under way and the ones coming up. */
    public function driver(Request $request, \App\Services\Transport\RideService $rides)
    {
        $d = $rides->driverFor($request->user());
        abort_unless($d, 403, 'You are not set up as a driver. Ask the front desk.');

        return $this->ok($this->driverJson($d, $rides));
    }

    /** POST /driver/duty {on} */
    public function driverDuty(Request $request, \App\Services\Transport\RideService $rides)
    {
        $d = $rides->driverFor($request->user());
        abort_unless($d, 403);
        $on = $request->boolean('on');
        $d->forceFill(['on_duty' => $on] + ($on ? [] : ['latitude' => null, 'longitude' => null]))->save();

        return $this->ok($this->driverJson($d->fresh(), $rides), $on ? 'You’re on duty: new rides can come to you.' : 'Off duty. Your location is no longer shared.');
    }

    /** POST /driver/location {lat, lng} — every 30–60 s while on duty. */
    public function driverLocation(Request $request, \App\Services\Transport\RideService $rides)
    {
        $d = $rides->driverFor($request->user());
        abort_unless($d, 403);
        $data = $request->validate(['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180']);
        if (! $d->on_duty && ! $d->activeRide()) {
            return $this->ok(['on_duty' => false]);
        }
        $rides->driverLocation($d, (float) $data['lat'], (float) $data['lng']);

        return $this->ok(['on_duty' => (bool) $d->on_duty]);
    }

    private function driverJson(\App\Models\Transport\Driver $d, \App\Services\Transport\RideService $rides): array
    {
        $active = $d->activeRide();
        $row = fn (\App\Models\Transport\Ride $r) => $rides->rideJson($r) + ['nav' => $rides->navUrl($r), 'guest' => $r->guest_name, 'guest_phone' => $r->guest_phone, 'room' => $r->reservation?->rooms()->with('room')->get()->pluck('room.number')->filter()->implode(', ')];

        return [
            'id' => $d->id, 'name' => $d->name, 'on_duty' => (bool) $d->on_duty,
            'car' => $d->vehicle ? ['name' => $d->vehicle->name, 'plate' => $d->vehicle->plate, 'label' => $d->vehicle->label] : null,
            'licence_warning' => $d->licenceExpired() ? 'Your licence on file has expired. Tell the front desk.' : null,
            'ride' => $active ? $row($active) : null,
            'upcoming' => $d->rides()->where('status', \App\Models\Transport\Ride::ASSIGNED)->when($active, fn ($q) => $q->whereKeyNot($active->id))->orderBy('pickup_at')->limit(10)->get()->map($row)->values(),
            'ping_seconds' => 45,
        ];
    }

    /** GET /owner?scope=group — the owner's dashboard: this hotel, or every hotel together. */
    public function owner(Request $request, \App\Services\Reports\OwnerDashboardService $dash)
    {
        abort_unless($request->user()->canAny(['View management reports', 'View finance reports']), 403);

        return $this->ok($dash->build($request->user(), $request->query('scope') === 'group'));
    }

    public function group(Request $request, \App\Services\Staff\StaffAppService $app)
    {
        abort_unless(\App\Support\Tenancy\CurrentHotel::multi() && $request->user()->can('Access all hotels'), 403);
        $rows = \App\Http\Controllers\Settings\GroupController::rows($request->user(), $app);

        return $this->ok($rows->map(fn ($r) => [
            'id' => $r['hotel']->id, 'name' => $r['hotel']->name, 'city' => $r['hotel']->city,
            'occupancy' => $r['today']['occupancy'], 'occupied' => $r['today']['occupied'], 'rooms' => $r['today']['rooms'],
            'in_house' => $r['today']['in_house'], 'arrivals' => $r['today']['arrivals'], 'arrived' => $r['today']['arrived'], 'departures' => $r['today']['departures'],
            'dirty' => $r['today']['dirty'], 'maintenance' => $r['today']['maintenance'], 'upcoming' => $r['upcoming'],
            'collected' => $r['today']['collected'], 'collected_label' => $r['currency'] . number_format($r['today']['collected'], 0),
            'month_revenue' => $r['month_revenue'], 'month_revenue_label' => $r['currency'] . number_format($r['month_revenue'], 0),
            'month_occupancy' => $r['month_occupancy'], 'last_closed' => $r['last_audit'] ? \Illuminate\Support\Carbon::parse($r['last_audit'])->toDateString() : null,
        ])->values());
    }

    // ── Company billing ───────────────────────────────────────────────

    /** GET /companies?q= — open accounts a bill can be moved to, with what each can still take. */
    public function companies(Request $request)
    {
        abort_unless($request->user()->canAny(['Bill to company', 'View company accounts']), 403);

        return $this->ok(Company::active()->search($request->query('q'))->orderBy('name')->limit(50)->get()->map(fn (Company $c) => [
            'id' => $c->id, 'name' => $c->name, 'account_number' => $c->account_number,
            'owes' => $c->balance(), 'credit_limit' => (float) $c->credit_limit, 'available' => $c->available(),
        ])->values());
    }

    /** POST /folios/{folio}/to-company {company_id, amount, note} */
    public function billToCompany(Request $request, Folio $folio, CompanyLedger $ledger)
    {
        abort_unless($request->user()->can('Bill to company'), 403);
        $data = $request->validate(['company_id' => 'required|integer|exists:companies,id', 'amount' => 'required|numeric|min:0.01|max:1000000000', 'note' => 'nullable|string|max:150']);
        $company = Company::findOrFail($data['company_id']);
        try {
            $entry = $ledger->transferFolio($folio, $company, (float) $data['amount'], $data['note'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(['invoice' => $entry->number, 'balance' => $folio->fresh()->balance()], $ledger->money((float) $data['amount']) . " moved to {$company->name}'s account.");
    }
}
