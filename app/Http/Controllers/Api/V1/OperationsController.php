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

    /** POST /driver/duty {on} — going on duty needs today's car check (POST /driver/check) first. */
    public function driverDuty(Request $request, \App\Services\Transport\RideService $rides)
    {
        $d = $rides->driverFor($request->user());
        abort_unless($d, 403);
        $on = $request->boolean('on');
        if ($on && config('hotel.transport.check_before_duty', true) && $d->vehicle && ! $rides->checkedRecently($d, $d->vehicle)) {
            return response()->json(['success' => false, 'code' => 'vehicle_check', 'message' => "Check {$d->vehicle->label} before you start.",
                'data' => $this->checkForm($d)], 422);
        }
        if ($on && $d->licenceExpired()) {
            return $this->fail('Your driving licence on file has run out. Give the front desk the new one first.', 422, 'licence');
        }
        $d->forceFill(['on_duty' => $on] + ($on ? [] : ['latitude' => null, 'longitude' => null]))->save();

        return $this->ok($this->driverJson($d->fresh(), $rides), $on ? 'You’re on duty: new rides can come to you.' : 'Off duty. Your location is no longer shared.');
    }

    private function checkForm(\App\Models\Transport\Driver $d): array
    {
        return [
            'vehicle' => $d->vehicle ? ['id' => $d->vehicle->id, 'label' => $d->vehicle->label, 'odometer_km' => $d->vehicle->odometer_km] : null,
            'items' => collect(\App\Models\Transport\VehicleLog::CHECKS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ];
    }

    /** POST /driver/check {checks: {tyres: true …}, km?, safe, notes?, photo?, on?} — the car check before a shift. */
    public function driverCheck(Request $request, \App\Services\Transport\RideService $rides)
    {
        $d = $rides->driverFor($request->user());
        abort_unless($d, 403);
        abort_unless($d->vehicle, 422, 'You have no car of your own on file. Ask the front desk to set one.');
        $data = $request->validate([
            'checks' => 'required|array', 'checks.*' => 'boolean', 'km' => 'nullable|integer|min:0|max:2000000',
            'safe' => 'required|boolean', 'notes' => 'nullable|string|max:500', 'photo' => 'nullable|image|max:5120',
        ]);
        $log = $rides->vehicleCheck($d, $d->vehicle, $data['checks'], $data['km'] ?? null, (bool) $data['safe'], $data['notes'] ?? null, $request->file('photo'), $request->header('X-Offline-Id'));
        $msg = $log->ok ? 'Car checked. All good.' : ($data['safe'] ? 'Saved. The car desk knows about: ' . implode(', ', $log->failed) . '.' : 'The car is off the road; the car desk has been told.');
        if ($request->boolean('on') && $data['safe'] && ! $d->licenceExpired()) {
            $d->forceFill(['on_duty' => true])->save();
            $msg .= ' You’re on duty.';
        }

        return $this->ok($this->driverJson($d->fresh(), $rides), $msg);
    }

    /** POST /driver/log {kind: fuel|issue, litres?, cost?, km?, notes?, photo?} */
    public function driverLog(Request $request, \App\Services\Transport\RideService $rides)
    {
        $d = $rides->driverFor($request->user());
        abort_unless($d && $d->vehicle, 403);
        $data = $request->validate([
            'kind' => 'required|in:fuel,issue', 'litres' => 'nullable|required_if:kind,fuel|numeric|min:0.1|max:500', 'cost' => 'nullable|numeric|min:0|max:10000000',
            'km' => 'nullable|integer|min:0|max:2000000', 'notes' => 'nullable|required_if:kind,issue|string|max:500', 'photo' => 'nullable|image|max:5120',
        ]);
        $rides->logVehicle($d->vehicle, $data['kind'], $data + ['offline_id' => $request->header('X-Offline-Id')], $request->user(), $d, $request->file('photo'));

        return $this->ok($this->driverJson($d->fresh(), $rides), $data['kind'] === 'fuel' ? 'Fuel logged.' : 'Problem reported to the car desk.');
    }

    /** POST /driver/rides/{ride}/{action} {note?, km?, cash?} — start, arrived, picked_up, complete, decline, no_show. */
    public function driverRide(Request $request, int $ride, string $action, \App\Services\Transport\RideService $rides)
    {
        $r = \App\Models\Transport\Ride::findOrFail($ride);
        $data = $request->validate(['note' => 'nullable|string|max:300', 'km' => 'nullable|integer|min:0|max:2000000', 'cash' => 'nullable|numeric|min:0|max:10000000']);
        if ($action === 'decline' && blank($data['note'] ?? null)) {
            return $this->fail('Say why you can’t do this ride.', 422, 'reason_needed');
        }
        $msg = $rides->driverAction($r, $request->user(), $action, $data['note'] ?? null, [
            'km' => $data['km'] ?? null, 'cash' => $data['cash'] ?? null, 'at' => $request->attributes->get('offline_at'),
        ]);
        $d = $rides->driverFor($request->user());

        return $this->ok($d ? $this->driverJson($d->fresh(), $rides) : null, $msg);
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
        $row = fn (\App\Models\Transport\Ride $r) => $rides->rideJson($r) + [
            'nav' => $rides->navUrl($r), 'guest' => $r->guest_name, 'guest_phone' => $r->guest_phone,
            'room' => $r->reservation?->rooms()->with('room')->get()->pluck('room.number')->filter()->implode(', '),
            'fare' => $r->fare !== null ? (float) $r->fare : null, 'start_km' => $r->start_km,
            'arrived_at' => $r->arrived_at?->toIso8601String(), 'no_show_after' => (int) config('hotel.transport.no_show_wait_minutes', 15),
        ];
        $v = $d->vehicle;
        $cash = $d->rides()->where('cash_collected', '>', 0)->whereNull('cash_handed_at')->get(['id', 'number', 'cash_collected']);

        return [
            'id' => $d->id, 'name' => $d->name, 'on_duty' => (bool) $d->on_duty,
            'car' => $v ? ['id' => $v->id, 'name' => $v->name, 'plate' => $v->plate, 'label' => $v->label, 'odometer_km' => $v->odometer_km,
                'checked' => $rides->checkedRecently($d, $v), 'warnings' => array_values(array_filter([
                    $v->status !== 'available' ? 'This car is off the road.' : null,
                    ...array_map(fn ($p) => "{$p} has run out.", $v->expiredPapers()),
                    ...$v->papersEnding(), $v->serviceDue(),
                ]))] : null,
            'licence_warning' => $d->licenceExpired() ? 'Your licence on file has expired. Tell the front desk.' : null,
            'check' => $this->checkForm($d),
            'cash_held' => round((float) $cash->sum('cash_collected'), 2),
            'cash_held_label' => $cash->isNotEmpty() ? $rides->money((float) $cash->sum('cash_collected')) . ' from ' . $cash->pluck('number')->implode(', ') : null,
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
