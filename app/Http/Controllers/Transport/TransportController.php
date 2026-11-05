<?php

namespace App\Http\Controllers\Transport;

use App\Enums\ReservationStatus as S;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Transport\Driver;
use App\Models\Transport\Ride;
use App\Models\Transport\RidePlace;
use App\Models\Transport\Vehicle;
use App\Models\User;
use App\Services\Transport\RideService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Car Service: the dispatch board, and the hotel's cars, drivers and set-fare places. */
class TransportController extends Controller
{
    public function __construct(private RideService $rides)
    {
        $this->middleware('permission:Manage transport');
    }

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['rides', 'drivers', 'cars', 'places'], true) ? $request->query('tab') : 'rides';
        $drivers = Driver::with('vehicle', 'user:id,name')->orderByDesc('is_active')->orderByDesc('on_duty')->orderBy('name')->get();
        $vehicles = Vehicle::orderByDesc('is_active')->orderBy('name')->get();
        $data = ['pagetitle' => 'Car Service', 'tab' => $tab, 'drivers' => $drivers, 'vehicles' => $vehicles, 'places' => RidePlace::orderBy('sort_order')->orderBy('name')->get()]
            + $this->board();
        if ($tab === 'drivers') {
            $data['users'] = User::where('is_disabled', false)->orderBy('name')->get(['id', 'name', 'email']);
        }
        if ($request->boolean('partial')) {
            return view('transport.partials.board', $data);
        }
        $data['stays'] = Reservation::whereIn('status', [S::Pending->value, S::Confirmed->value, S::CheckedIn->value])
            ->where('check_out_date', '>=', today())->where('check_in_date', '<=', today()->addDays(14))
            ->with('guest:id,first_name,last_name', 'rooms.room:id,number')->orderBy('check_in_date')->limit(200)->get();
        $data['settings'] = $this->rides->settings();

        return view('transport.index', $data);
    }

    private function board(): array
    {
        $open = Ride::open()->with(['driver', 'vehicle', 'reservation.rooms.room'])->orderByRaw('pickup_at IS NULL DESC')->orderBy('pickup_at')->orderBy('id')->get();

        return [
            'requested' => $open->where('status', Ride::REQUESTED)->values(),
            'active'    => $open->where('status', '!=', Ride::REQUESTED)->values(),
            'done'      => Ride::whereIn('status', [Ride::COMPLETED, Ride::CANCELLED])->where('updated_at', '>=', today())->with('driver')->latest('updated_at')->limit(30)->get(),
            'cash'      => Ride::where('cash_collected', '>', 0)->whereNull('cash_handed_at')->with('driver')->orderBy('completed_at')->get(),
            'candidates' => $open->where('status', Ride::REQUESTED)->mapWithKeys(fn (Ride $r) => [$r->id => $this->rides->candidates($r)->take(5)]),
            'map' => [
                'hotel'   => app(\App\Services\Booking\ArrivalService::class)->hotelPoint(),
                'drivers' => Driver::where('is_active', true)->where('on_duty', true)->whereNotNull('latitude')->where('seen_at', '>', now()->subMinutes(30))->get(['id', 'name', 'latitude', 'longitude', 'seen_at'])
                    ->map(fn ($d) => ['name' => $d->name, 'lat' => $d->latitude, 'lng' => $d->longitude, 'seen' => $d->seen_at->diffForHumans(short: true)])->values(),
                'pickups' => $open->whereNotNull('place_lat')->map(fn (Ride $r) => ['number' => $r->number, 'label' => $r->place_label, 'lat' => $r->place_lat, 'lng' => $r->place_lng, 'status' => $r->staff_label])->values(),
            ],
        ];
    }

    // ── Rides ─────────────────────────────────────────────────────────

    /** The front desk books a car for a guest (on the phone, at the desk). */
    public function storeRide(Request $request)
    {
        $d = $request->validate([
            'reservation_id' => 'required|integer|exists:reservations,id', 'direction' => 'required|in:to_hotel,from_hotel',
            'place_id' => 'nullable|integer', 'label' => 'nullable|string|max:255', 'pickup_at' => 'nullable|date',
            'passengers' => 'nullable|integer|min:1|max:20', 'bags' => 'nullable|integer|min:0|max:30', 'flight' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:300', 'payment' => 'nullable|in:room,cash,card', 'fare' => 'nullable|numeric|min:0|max:10000000',
        ]);
        try {
            $ride = $this->rides->book(Reservation::findOrFail($d['reservation_id']), $d, 'desk');
            if (isset($d['fare']) && $d['fare'] !== '') {
                $ride->forceFill(['fare' => round((float) $d['fare'], 2)])->save();
            }
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Car {$ride->number} booked. Now assign a driver.");
    }

    public function assign(Request $request, Ride $ride)
    {
        $d = $request->validate(['driver_id' => 'required|integer|exists:drivers,id', 'vehicle_id' => 'nullable|integer|exists:vehicles,id', 'fare' => 'nullable|numeric|min:0|max:10000000']);

        return $this->attempt(function () use ($ride, $d, $request) {
            $driver = Driver::findOrFail($d['driver_id']);
            $this->rides->assign($ride, $driver, ! empty($d['vehicle_id']) ? Vehicle::find($d['vehicle_id']) : null, $request->user(), isset($d['fare']) && $d['fare'] !== '' ? (float) $d['fare'] : null);

            return "{$ride->number} given to {$driver->name}.";
        });
    }

    public function auto(Request $request, Ride $ride)
    {
        return $this->attempt(fn () => ($d = $this->rides->autoAssign($ride, $request->user())) ? "{$ride->number} given to {$d->name}." : throw new BookingException('No driver is free with a car right now.'));
    }

    public function cancel(Request $request, Ride $ride)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => tap("{$ride->number} cancelled.", fn () => $this->rides->cancel($ride, $d['reason'], $request->user())));
    }

    /** Move a ride on for a driver without the app (start, arrived, picked_up, complete, no_show). */
    public function status(Request $request, Ride $ride, string $action)
    {
        abort_unless(in_array($action, ['start', 'arrived', 'picked_up', 'complete', 'no_show'], true), 404);
        $d = $request->validate(['km' => 'nullable|integer|min:0|max:2000000', 'cash' => 'nullable|numeric|min:0|max:10000000']);

        return $this->attempt(fn () => $ride->number . ': ' . $this->rides->driverAction($ride, $request->user(), $action, null, $d));
    }

    /** A driver hands in the cash a guest paid them: it goes on the guest's bill as paid, in this person's till. */
    public function cashIn(Request $request, Ride $ride)
    {
        abort_unless($request->user()->canAny(['Manage transport', 'Receive payments']), 403);

        return $this->attempt(fn () => $this->rides->cashHandedIn($ride, $request->user()));
    }

    /** Fuel, a service or a problem for one of the cars. */
    public function logVehicle(Request $request, Vehicle $vehicle)
    {
        $d = $request->validate([
            'kind' => 'required|in:fuel,service,issue', 'litres' => 'nullable|required_if:kind,fuel|numeric|min:0.1|max:500', 'cost' => 'nullable|numeric|min:0|max:10000000',
            'km' => 'nullable|integer|min:0|max:2000000', 'notes' => 'nullable|required_unless:kind,fuel|string|max:500', 'next_service_on' => 'nullable|date',
            'back_in_service' => 'nullable|boolean', 'photo' => 'nullable|image|max:5120',
        ]);
        $this->rides->logVehicle($vehicle, $d['kind'], $d, $request->user(), null, $request->file('photo'));

        return redirect()->route('transport.index', ['tab' => 'cars', 'edit' => $vehicle->id])->with('success', \App\Models\Transport\VehicleLog::KINDS[$d['kind']] . ' logged.');
    }

    // ── Fleet ─────────────────────────────────────────────────────────

    public function saveVehicle(Request $request, ?Vehicle $vehicle = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:80', 'plate' => ['required', 'string', 'max:20', Rule::unique('vehicles', 'plate')->ignore($vehicle?->id)],
            'color' => 'nullable|string|max:30', 'kind' => ['required', Rule::in(array_keys(Vehicle::KINDS))], 'seats' => 'required|integer|min:1|max:60',
            'status' => 'required|in:available,out_of_service', 'notes' => 'nullable|string|max:255',
            'odometer_km' => 'nullable|integer|min:0|max:2000000', 'insurance_expires' => 'nullable|date', 'roadworthy_expires' => 'nullable|date',
            'service_every_km' => 'nullable|integer|min:0|max:100000', 'last_service_km' => 'nullable|integer|min:0|max:2000000', 'service_due_on' => 'nullable|date',
        ]);
        $d['plate'] = strtoupper(trim($d['plate']));
        $d['is_active'] = $request->boolean('is_active', true);
        $vehicle ? $vehicle->update($d) : $vehicle = Vehicle::create($d);
        Audit::record('transport.vehicle', "Car saved: {$vehicle->label}", $vehicle);

        return redirect()->route('transport.index', ['tab' => 'cars'])->with('success', "{$vehicle->label} saved.");
    }

    public function saveDriver(Request $request, ?Driver $driver = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:100', 'phone' => 'required|string|max:30', 'licence_number' => 'nullable|string|max:40', 'licence_expires' => 'nullable|date',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id', 'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('drivers', 'user_id')->ignore($driver?->id)],
        ], ['user_id.unique' => 'That staff login is already linked to another driver.']);
        $d['is_active'] = $request->boolean('is_active', true);
        $driver ? $driver->update($d) : $driver = Driver::create($d);
        if ($driver->user_id && ($u = User::find($driver->user_id)) && ! $u->can('Drive guests')) {
            $u->givePermissionTo('Drive guests');   // so the staff app shows Driver mode
        }
        Audit::record('transport.driver', "Driver saved: {$driver->name}", $driver);

        return redirect()->route('transport.index', ['tab' => 'drivers'])->with('success', "{$driver->name} saved." . ($driver->user_id ? ' They can use Driver mode in the staff app.' : ' Link a staff login so they can use the staff app.'));
    }

    public function savePlace(Request $request, ?RidePlace $place = null)
    {
        $d = $request->validate([
            'name' => 'required|string|max:80', 'kind' => ['required', Rule::in(array_keys(RidePlace::KINDS))],
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude', 'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
            'fare' => 'nullable|numeric|min:0|max:10000000', 'sort_order' => 'nullable|integer|min:0|max:999',
        ]);
        $d['is_active'] = $request->boolean('is_active', true);
        $d['sort_order'] = (int) ($d['sort_order'] ?? 0);
        $place ? $place->update($d) : $place = RidePlace::create($d);

        return redirect()->route('transport.index', ['tab' => 'places'])->with('success', "{$place->name} saved.");
    }

    private function attempt(callable $fn)
    {
        try {
            $msg = $fn();
        } catch (BookingException $e) {
            return request()->expectsJson() ? response()->json(['success' => false, 'message' => $e->getMessage()], 422) : back()->with('error', $e->getMessage());
        }

        return request()->expectsJson() ? response()->json(['success' => true, 'message' => $msg]) : back()->with('success', $msg);
    }
}
