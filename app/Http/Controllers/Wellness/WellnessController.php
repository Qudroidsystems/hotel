<?php

namespace App\Http\Controllers\Wellness;

use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Wellness\WellnessBooking;
use App\Models\Wellness\WellnessService;
use App\Models\Wellness\WellnessTherapist;
use App\Models\Wellness\WellnessVenue;
use App\Services\Wellness\WellnessBookings;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Spa, gym & pool for staff: the day's diary, booking at the desk, and setting up places, treatments and therapists. */
class WellnessController extends Controller
{
    public function __construct(private WellnessBookings $wellness)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['Manage wellness bookings', 'Set up wellness']), 403);
        $day = $this->day($request->query('date'));
        $venues = WellnessVenue::active()->ordered()->with(['services' => fn ($q) => $q->where('is_active', true), 'therapists' => fn ($q) => $q->where('is_active', true)])->get();
        $bookings = WellnessBooking::whereDate('starts_at', $day->toDateString())->whereNot('status', WellnessBooking::EXPIRED)
            ->with(['service', 'therapist', 'reservation.guest:id,first_name,last_name,is_vip', 'handler:id,name'])->orderBy('starts_at')->get()->groupBy('venue_id');
        $inHouse = Reservation::whereIn('status', [ReservationStatus::CheckedIn, ReservationStatus::Confirmed])->where('check_out_date', '>=', today())
            ->where('check_in_date', '<=', today()->addDays(7))->with(['guest:id,first_name,last_name', 'rooms.room:id,number'])->get()
            ->map(fn ($r) => ['id' => $r->id, 'label' => ($r->status === ReservationStatus::CheckedIn ? ($r->rooms->pluck('room.number')->filter()->implode(', ') ?: '—') : 'Arriving ' . $r->check_in_date->format('j M')) . ' · ' . ($r->guest?->full_name ?? $r->reference)])
            ->sortBy('label', SORT_NATURAL)->values();

        return view('wellness.index', [
            'pagetitle' => 'Spa, Gym & Pool', 'day' => $day, 'venues' => $venues, 'bookings' => $bookings, 'inHouse' => $inHouse, 'svc' => $this->wellness,
            'canManage' => $this->wellness->canManage($request->user()),
            'counts' => ['booked' => $bookings->flatten()->where('status', 'booked')->count(), 'arrived' => $bookings->flatten()->where('status', 'arrived')->count(),
                'takings' => (float) $bookings->flatten()->whereIn('status', ['arrived', 'completed'])->sum('total')],
        ]);
    }

    /** The free times for the booking form (JSON). */
    public function slots(Request $request)
    {
        abort_unless($this->wellness->canManage($request->user()), 403);
        $data = $request->validate(['venue_id' => 'required|integer', 'service_id' => 'nullable|integer', 'date' => 'required|date', 'people' => 'nullable|integer|min:1|max:' . WellnessBookings::MAX_PEOPLE]);
        $v = WellnessVenue::findOrFail($data['venue_id']);
        $s = ! empty($data['service_id']) ? WellnessService::where('venue_id', $v->id)->find($data['service_id']) : null;

        return response()->json(['slots' => $this->wellness->slots($v, $s, Carbon::parse($data['date']), (int) ($data['people'] ?? 1), true)]);
    }

    public function store(Request $request)
    {
        abort_unless($this->wellness->canManage($request->user()), 403);
        $data = $request->validate([
            'venue_id' => 'required|integer', 'service_id' => 'nullable|integer', 'date' => 'required|date', 'time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'people' => 'nullable|integer|min:1|max:' . WellnessBookings::MAX_PEOPLE, 'reservation_id' => 'nullable|integer|exists:reservations,id',
            'name' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:30', 'note' => 'nullable|string|max:300',
        ]);
        $v = WellnessVenue::findOrFail($data['venue_id']);
        $s = ! empty($data['service_id']) ? WellnessService::where('venue_id', $v->id)->find($data['service_id']) : null;
        try {
            $b = $this->wellness->deskBook($request->user(), $v, $s, Carbon::parse($data['date'] . ' ' . $data['time']), (int) ($data['people'] ?? 1),
                ! empty($data['reservation_id']) ? Reservation::find($data['reservation_id']) : null, ['name' => $data['name'] ?? null, 'phone' => $data['phone'] ?? null], $data['note'] ?? null);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('wellness.index', ['date' => $b->starts_at->toDateString()])
            ->with('success', "Booked {$b->number}: {$b->what} for {$b->guest_name}, " . $b->starts_at->format('D g:i A') . ($b->therapist ? " with {$b->therapist->name}" : '') . '.');
    }

    public function act(Request $request, WellnessBooking $booking, string $action)
    {
        abort_unless($this->wellness->canManage($request->user()), 403);
        $note = trim((string) ($request->validate(['note' => 'nullable|string|max:255'])['note'] ?? '')) ?: null;
        try {
            match ($action) {
                'arrive'   => $this->wellness->arrive($booking, $request->user()),
                'complete' => $this->wellness->complete($booking, $request->user()),
                'no_show'  => $this->wellness->noShow($booking, $request->user()),
                'cancel'   => $note ? $this->wellness->cancel($booking, $request->user(), $note) : throw new BookingException('Say why, so the guest knows.'),
                default    => abort(404),
            };
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }
        $done = ['arrive' => 'arrived' . ($booking->payment === 'room' && (float) $booking->total > 0 ? ' — on the room bill' : ($booking->payment === 'desk' ? ' — take payment now' : '')),
            'complete' => 'done', 'no_show' => 'marked as didn’t come', 'cancel' => 'cancelled'][$action];

        return back()->with('success', "{$booking->number} {$done}.");
    }

    // ── Set-up ────────────────────────────────────────────────────────

    public function setup(Request $request)
    {
        abort_unless($request->user()->can('Set up wellness'), 403);

        return view('wellness.setup', [
            'pagetitle' => 'Spa, Gym & Pool — set-up',
            'venues' => WellnessVenue::ordered()->with(['services', 'therapists.user:id,name'])->get(),
            'staff' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function saveVenue(Request $request, ?WellnessVenue $venue = null)
    {
        abort_unless($request->user()->can('Set up wellness'), 403);
        $data = $request->validate([
            'name' => 'required|string|max:80', 'kind' => ['required', Rule::in(array_keys(WellnessVenue::KINDS))], 'description' => 'nullable|string|max:500',
            'opens' => ['required', 'regex:/^\d{2}:\d{2}$/'], 'closes' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'capacity' => 'required|integer|min:1|max:500', 'session_minutes' => 'required|integer|min:15|max:600',
            'guest_price' => 'nullable|numeric|min:0', 'visitor_price' => 'nullable|numeric|min:0', 'visitors' => 'nullable|boolean', 'is_active' => 'nullable|boolean',
        ]);
        $data['guest_price'] = (float) ($data['guest_price'] ?? 0);
        $data['visitor_price'] = isset($data['visitor_price']) && $data['visitor_price'] !== '' ? (float) $data['visitor_price'] : null;
        $data['visitors'] = (bool) ($data['visitors'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? ! $venue);
        $venue ??= new WellnessVenue();
        $venue->fill($data)->save();
        Audit::record('wellness.setup', "Saved {$venue->name} (spa, gym & pool set-up)", $venue, by: $request->user());

        return back()->with('success', "{$venue->name} saved.");
    }

    public function saveService(Request $request, WellnessVenue $venue, ?WellnessService $service = null)
    {
        abort_unless($request->user()->can('Set up wellness'), 403);
        $data = $request->validate([
            'name' => 'required|string|max:100', 'description' => 'nullable|string|max:500', 'minutes' => 'required|integer|min:15|max:480',
            'price' => 'required|numeric|min:0', 'visitor_price' => 'nullable|numeric|min:0', 'is_active' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0|max:999',
        ]);
        $data['visitor_price'] = isset($data['visitor_price']) && $data['visitor_price'] !== '' ? (float) $data['visitor_price'] : null;
        $data['is_active'] = (bool) ($data['is_active'] ?? ! $service);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        abort_if($service && $service->venue_id !== $venue->id, 404);
        $service ??= new WellnessService();
        $service->fill($data + ['venue_id' => $venue->id])->save();
        Audit::record('wellness.setup', "Saved treatment {$service->name} at {$venue->name}", $service, by: $request->user());

        return back()->with('success', "{$service->name} saved.");
    }

    public function saveTherapist(Request $request, WellnessVenue $venue, ?WellnessTherapist $therapist = null)
    {
        abort_unless($request->user()->can('Set up wellness'), 403);
        $data = $request->validate(['name' => 'required|string|max:80', 'user_id' => 'nullable|integer|exists:users,id', 'is_active' => 'nullable|boolean']);
        $data['is_active'] = (bool) ($data['is_active'] ?? ! $therapist);
        abort_if($therapist && $therapist->venue_id !== $venue->id, 404);
        $therapist ??= new WellnessTherapist();
        $therapist->fill($data + ['venue_id' => $venue->id])->save();

        return back()->with('success', "{$therapist->name} saved.");
    }

    private function day(?string $d): Carbon
    {
        try {
            return $d ? Carbon::parse($d)->startOfDay() : today();
        } catch (\Throwable) {
            return today();
        }
    }
}
