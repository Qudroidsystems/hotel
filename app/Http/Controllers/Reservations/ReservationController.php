<?php

namespace App\Http\Controllers\Reservations;

use App\Enums\BookingSource;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Exceptions\OutstandingBalanceException;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Billing\FolioService;
use App\Services\Reservations\AvailabilityService;
use App\Services\Reservations\ReservationService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    public function __construct(private ReservationService $service, private AvailabilityService $availability)
    {
        $this->middleware('permission:View reservations', ['only' => ['index', 'show', 'print', 'calendar', 'scan', 'lookup', 'qr']]);
        $this->middleware('permission:Create reservations', ['only' => ['create', 'store', 'availability']]);
        $this->middleware('permission:Update reservations', ['only' => ['update', 'assignRoom', 'unassignRoom', 'confirm', 'changeDates']]);
        $this->middleware('permission:Cancel reservations', ['only' => ['cancel', 'noShow']]);
        $this->middleware('permission:Check in guests', ['only' => ['checkIn']]);
        $this->middleware('permission:Check out guests', ['only' => ['checkOut']]);
        $this->middleware('permission:Apply reservation discounts', ['only' => ['discount']]);
    }

    // ── Lists ─────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $this->prepareArrivalsOncePerDay();

        $tab = in_array($request->query('tab'), ['arrivals', 'inhouse', 'departures', 'upcoming', 'all'], true)
            ? $request->query('tab') : 'arrivals';
        $pagetitle = 'Reservations';

        $base = Reservation::query()->with(['guest', 'rooms.room', 'rooms.roomType']);
        $tabs = [
            'arrivals'   => (clone $base)->arrivingOn(today()),
            'inhouse'    => (clone $base)->inHouse(),
            'departures' => (clone $base)->departingOn(today()),
            'upcoming'   => (clone $base)->open()->where('check_in_date', '>', today()->toDateString()),
            'all'        => clone $base,
        ];
        $counts = collect($tabs)->except('all')->map(fn ($q) => (clone $q)->count());
        $overdue = Reservation::whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value])
            ->where('check_in_date', '<', today()->toDateString())->count();

        $filters = $request->only(['q', 'status', 'source', 'from', 'to']);
        $reservations = $tabs[$tab]
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(fn ($q) => $q->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('guest', fn ($g) => $g->search($term)));
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('check_out_date', '>', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('check_in_date', '<=', $v))
            ->orderBy($tab === 'departures' || $tab === 'inhouse' ? 'check_out_date' : 'check_in_date', $tab === 'all' ? 'desc' : 'asc')
            ->orderBy('id', 'desc')
            ->paginate(25)->withQueryString();

        return view('reservations.index', compact('pagetitle', 'tab', 'counts', 'overdue', 'filters', 'reservations'));
    }

    /** Tape chart: rooms down the side, dates across the top. */
    public function calendar(Request $request)
    {
        $pagetitle = 'Booking Calendar';
        $days = in_array((int) $request->query('days'), [7, 14, 21, 30], true) ? (int) $request->query('days') : 14;
        $start = $request->filled('start') ? Carbon::parse($request->query('start'))->startOfDay() : today()->subDay();
        $end = $start->copy()->addDays($days);
        $dates = collect(range(0, $days - 1))->map(fn ($i) => $start->copy()->addDays($i));

        $rooms = Room::active()->with(['roomType', 'floor'])->get()
            ->sortBy([fn ($a, $b) => $a->roomType->base_rate <=> $b->roomType->base_rate, fn ($a, $b) => strnatcasecmp($a->number, $b->number)])
            ->groupBy('room_type_id');

        $lines = ReservationRoom::with(['reservation.guest', 'roomType'])
            ->whereHas('reservation', fn ($q) => $q->whereIn('status', array_merge(ReservationStatus::holding(), [ReservationStatus::CheckedOut->value]))->overlapping($start, $end))
            ->get();

        return view('reservations.calendar', compact('pagetitle', 'days', 'start', 'end', 'dates', 'rooms', 'lines'));
    }

    // ── Booking ───────────────────────────────────────────────────────

    public function create(Request $request)
    {
        $pagetitle = 'New Reservation';
        $guest = $request->filled('guest') ? Guest::find($request->query('guest')) : null;

        return view('reservations.create', [
            'pagetitle' => $pagetitle,
            'guest'     => $guest,
            'in'        => $request->query('in', today()->toDateString()),
            'out'       => $request->query('out', today()->addDay()->toDateString()),
            'idTypes'   => Guest::ID_TYPES,
        ]);
    }

    /** JSON for the booking form: what's free and what it costs. */
    public function availability(Request $request)
    {
        $data = $request->validate([
            'in'  => 'required|date|after_or_equal:today',
            'out' => 'required|date|after:in',
        ]);

        $nights = count(Reservation::nightsBetween($data['in'], $data['out']));
        if ($nights > 90) {
            return response()->json(['message' => 'Stays longer than 90 nights must be booked in parts.'], 422);
        }

        // Rooms that are ready right now first, then the rest, each in room-number order.
        $free = $this->availability->freeRooms($data['in'], $data['out'])
            ->sort(fn ($a, $b) => $this->notReady($a) <=> $this->notReady($b) ?: strnatcasecmp($a->number, $b->number))
            ->groupBy('room_type_id');

        return response()->json([
            'nights' => $nights,
            'types'  => $this->availability->summary($data['in'], $data['out'])->map(fn ($row) => [
                'id'        => $row['type']->id,
                'name'      => $row['type']->name,
                'code'      => $row['type']->code,
                'color'     => $row['type']->color,
                'capacity'  => $row['type']->capacity_label,
                'max_adults'   => $row['type']->max_adults,
                'max_children' => $row['type']->max_children,
                'available' => $row['available'],
                'total'     => $row['total'],
                'amount'    => $row['amount'],
                'nights'    => collect($row['nights'])->map(fn ($rate, $date) => ['date' => Carbon::parse($date)->format('D j M'), 'rate' => $rate])->values(),
                'rooms'     => ($free[$row['type']->id] ?? collect())->map(fn ($r) => [
                    'id' => $r->id, 'number' => $r->number, 'floor' => $r->floor->name,
                    'status' => $r->status->label(), 'ready' => in_array($r->status->value, ['available', 'reserved'], true),
                    'rate_override' => $r->rate_override !== null ? (float) $r->rate_override : null,
                ])->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'check_in_date'         => 'required|date|after_or_equal:today',
            'check_out_date'        => 'required|date|after:check_in_date',
            'source'                => ['required', Rule::enum(BookingSource::class)],
            'status'                => ['required', Rule::in([ReservationStatus::Confirmed->value, ReservationStatus::Pending->value])],
            'expected_arrival_time' => 'nullable|date_format:H:i',
            'special_requests'      => 'nullable|string|max:2000',
            'notes'                 => 'nullable|string|max:2000',
            'rooms'                 => 'required|array|min:1|max:50',
            'rooms.*.room_type_id'  => 'required|integer|exists:room_types,id',
            'rooms.*.room_id'       => 'nullable|integer|exists:rooms,id',
            'rooms.*.adults'        => 'required|integer|min:1|max:20',
            'rooms.*.children'      => 'nullable|integer|min:0|max:20',
            'guest_id'              => 'nullable|integer|exists:guests,id',
            'guest.first_name'      => 'required_without:guest_id|nullable|string|max:100',
            'guest.last_name'       => 'required_without:guest_id|nullable|string|max:100',
            'guest.phone'           => ['required_without:guest_id', 'nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'guest.email'           => 'nullable|email|max:255',
            'guest.nationality'     => 'nullable|string|max:100',
            'guest.id_type'         => 'nullable|string|max:50',
            'guest.id_number'       => 'nullable|string|max:100',
            'guest.company'         => 'nullable|string|max:150',
            'guest.address'         => 'nullable|string|max:500',
        ], [
            'rooms.required'                  => 'Add at least one room.',
            'guest.first_name.required_without' => "Enter the guest's first name or pick an existing guest.",
            'guest.last_name.required_without'  => "Enter the guest's last name or pick an existing guest.",
            'guest.phone.required_without'      => "Enter the guest's phone number or pick an existing guest.",
        ]);

        // Capacity check per room line.
        $types = RoomType::whereIn('id', collect($data['rooms'])->pluck('room_type_id'))->get()->keyBy('id');
        foreach ($data['rooms'] as $i => $line) {
            $type = $types[$line['room_type_id']];
            if ($line['adults'] > $type->max_adults || ($line['children'] ?? 0) > $type->max_children) {
                return back()->withInput()->withErrors(["rooms.{$i}.adults" => "{$type->name} sleeps at most {$type->capacity_label}."]);
            }
        }

        try {
            $reservation = DB::transaction(function () use ($data) {
                $guest = ! empty($data['guest_id'])
                    ? Guest::findOrFail($data['guest_id'])
                    : Guest::create(collect($data['guest'])->filter(fn ($v) => $v !== null && $v !== '')->all() + ['created_by' => auth()->id()]);

                return $this->service->create($guest, $data, $data['rooms']);
            });
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('reservations.show', $reservation)->with('success', "Reservation {$reservation->reference} created.");
    }

    // ── A single reservation ─────────────────────────────────────────

    public function show(Reservation $reservation)
    {
        $reservation->load(['guest', 'rooms.room.floor', 'rooms.roomType', 'createdBy', 'checkedInBy', 'checkedOutBy']);
        $pagetitle = 'Reservation ' . $reservation->reference;
        $events = $reservation->events()->with('user')->get();

        // Rooms that could be assigned to each line (free for the rest of the stay).
        $assignable = [];
        if ($reservation->status->isOpen()) {
            $from = $reservation->status === ReservationStatus::CheckedIn ? today()->max($reservation->check_in_date) : $reservation->check_in_date;
            if ($from->lt($reservation->check_out_date)) {
                $free = $this->availability->freeRooms($from, $reservation->check_out_date, null, $reservation->id)
                    ->whereNotIn('id', $reservation->rooms->pluck('room_id')->filter());
                foreach ($reservation->rooms as $line) {
                    // Same type first, then other types (upgrades / room changes).
                    $assignable[$line->id] = $free->sort(fn ($a, $b) => [$a->room_type_id === $line->room_type_id ? 0 : 1, $a->roomType->base_rate, $this->notReady($a)]
                        <=> [$b->room_type_id === $line->room_type_id ? 0 : 1, $b->roomType->base_rate, $this->notReady($b)] ?: strnatcasecmp($a->number, $b->number))->values();
                }
            }
        }

        // The bill: bring used room nights up to date, then total it.
        $folios = app(FolioService::class);
        $folios->postRoomNights($reservation);
        $folio = $folios->forReservation($reservation);
        $bill = ['charged' => $folio->chargesTotal(), 'paid' => $folio->paidTotal(), 'balance' => $folio->balance(),
                 'recent' => $folio->charges()->whereNull('voided_at')->where('type', '!=', 'room')->latest('id')->limit(4)->get()];

        return view('reservations.show', compact('pagetitle', 'reservation', 'events', 'assignable', 'folio', 'bill'));
    }

    public function update(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'source'                => ['required', Rule::enum(BookingSource::class)],
            'expected_arrival_time' => 'nullable|date_format:H:i',
            'special_requests'      => 'nullable|string|max:2000',
            'notes'                 => 'nullable|string|max:2000',
        ]);

        $reservation->update($data);
        $reservation->log('updated', 'Booking details updated (source, arrival time, requests or notes).');

        return back()->with('success', 'Reservation updated.');
    }

    /** Printable confirmation with the guest's QR code. */
    public function print(Reservation $reservation)
    {
        $reservation->load(['guest', 'rooms.room', 'rooms.roomType']);

        return view('reservations.print', compact('reservation'));
    }

    // ── Front desk actions ───────────────────────────────────────────

    public function assignRoom(Request $request, Reservation $reservation, ReservationRoom $line)
    {
        abort_unless($line->reservation_id === $reservation->id, 404);
        $data = $request->validate(['room_id' => 'required|integer|exists:rooms,id', 'reason' => 'nullable|string|max:255']);

        return $this->attempt(fn () => $this->service->assignRoom($line, Room::findOrFail($data['room_id']), $data['reason'] ?? null), 'Room assigned.');
    }

    public function unassignRoom(Reservation $reservation, ReservationRoom $line)
    {
        abort_unless($line->reservation_id === $reservation->id, 404);

        return $this->attempt(fn () => $this->service->unassignRoom($line), 'Room released.');
    }

    public function confirm(Reservation $reservation)
    {
        return $this->attempt(fn () => $this->service->confirm($reservation), 'Reservation confirmed.');
    }

    public function checkIn(Reservation $reservation)
    {
        return $this->attempt(fn () => $this->service->checkIn($reservation), 'Guest checked in.');
    }

    public function checkOut(Request $request, Reservation $reservation)
    {
        $reason = null;
        if ($request->filled('balance_reason')) {
            abort_unless($request->user()->can('Check out with balance'), 403);
            $reason = $request->validate(['balance_reason' => 'required|string|max:255'])['balance_reason'];
        }

        try {
            $this->service->checkOut($reservation, $reason);
        } catch (OutstandingBalanceException $e) {
            // Re-open the check-out dialog with the amount owed.
            return back()->with('checkout_owing', $e->balance);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Guest checked out. Room(s) sent to housekeeping.');
    }

    public function cancel(Request $request, Reservation $reservation)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        return $this->attempt(fn () => $this->service->cancel($reservation, $data['reason']), 'Reservation cancelled.');
    }

    public function noShow(Reservation $reservation)
    {
        return $this->attempt(fn () => $this->service->markNoShow($reservation), 'Marked as no-show.');
    }

    public function changeDates(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'check_in_date'  => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'reason'         => 'nullable|string|max:255',
        ]);

        return $this->attempt(fn () => $this->service->changeDates($reservation, $data['check_in_date'], $data['check_out_date'], $data['reason'] ?? null), 'Dates updated and stay re-priced.');
    }

    public function discount(Request $request, Reservation $reservation)
    {
        $data = $request->validate([
            'discount_amount' => 'required|numeric|min:0',
            'discount_reason' => 'required_unless:discount_amount,0|nullable|string|max:255',
        ]);

        return $this->attempt(fn () => $this->service->applyDiscount($reservation, (float) $data['discount_amount'], (string) ($data['discount_reason'] ?? '')), 'Discount updated.');
    }

    // ── QR & lookup (spec §5) ────────────────────────────────────────

    public function scan()
    {
        return view('reservations.scan', ['pagetitle' => 'Scan / Find Booking']);
    }

    /** Manual lookup by booking reference (fallback when the guest has no QR). */
    public function lookup(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:200']);
        $code = trim($data['code']);

        // A scanned QR contains the full verification URL; accept that too.
        if (preg_match('#/r/([A-Za-z0-9]{40,64})#', $code, $m)) {
            return redirect()->route('reservations.qr', $m[1]);
        }

        $reservation = Reservation::where('reference', strtoupper($code))->first();
        if (! $reservation) {
            Log::warning('Booking lookup failed', ['code' => $code, 'user_id' => auth()->id(), 'ip' => $request->ip()]);
            $this->auditFailedScan('reservations.lookup_failed', 'No reservation found for "' . str($code)->limit(60) . '"');

            return back()->withInput()->with('error', "No reservation found for “{$code}”.");
        }

        return redirect()->route('reservations.show', $reservation);
    }

    /** Target of the QR code. Logs every scan; flags bookings the QR is no longer valid for. */
    public function qr(Request $request, string $token)
    {
        $reservation = Reservation::where('qr_token', $token)->first();

        if (! $reservation) {
            Log::warning('Invalid reservation QR scanned', ['user_id' => auth()->id(), 'ip' => $request->ip()]);
            $this->auditFailedScan('reservations.qr_invalid', 'Scanned a QR code that matches no reservation');

            return redirect()->route('reservations.scan')->with('error', 'This QR code does not match any reservation.');
        }

        $valid = in_array($reservation->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true)
            && $reservation->check_out_date->gt(today());

        $reservation->log('qr_scanned', $valid
            ? 'QR code verified at the front desk.'
            : "QR code scanned but not valid for check-in (reservation is {$reservation->status->label()}).");

        $redirect = redirect()->route('reservations.show', $reservation);

        return $valid
            ? $redirect->with('success', 'QR verified — booking found.')
            : $redirect->with('error', "This QR is not valid for check-in: the reservation is {$reservation->status->label()}.");
    }

    // ------------------------------------------------------------------

    private function attempt(callable $action, string $success)
    {
        try {
            $action();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    /** Failed look-ups go on the audit trail; repeated ones from the same person or device are flagged (spec §5). */
    private function auditFailedScan(string $action, string $description): void
    {
        $recent = \App\Models\AuditLog::whereIn('action', ['reservations.lookup_failed', 'reservations.qr_invalid'])
            ->where('created_at', '>=', now()->subMinutes(15))
            ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhere('ip_address', request()->ip()))
            ->count();

        Audit::record($action, $description, flagged: $recent + 1 >= config('hotel.audit.flag_failures', 5));
    }

    private function notReady(Room $room): int
    {
        return in_array($room->status->value, ['available', 'reserved'], true) ? 0 : 1;
    }

    /** Without a cron job on XAMPP, make sure today's arrivals are marked Reserved at least once a day. */
    private function prepareArrivalsOncePerDay(): void
    {
        if (Cache::add('arrivals-prepared-' . today()->toDateString(), true, now()->endOfDay())) {
            $this->service->prepareTodaysArrivals();
            app(FolioService::class)->nightAudit(); // bill last night for guests in house
        }
    }
}
