<?php

namespace App\Services\Mobile\Modules;

use App\Enums\ReservationStatus as S;
use App\Exceptions\BookingException;
use App\Models\Reservation;
use App\Models\Transport\Driver;
use App\Models\Transport\Ride;
use App\Models\Transport\RidePlace;
use App\Models\Transport\Vehicle;
use App\Models\User;
use App\Services\Transport\RideService;
use Illuminate\Database\Eloquent\Model;

/** The car desk: book a car, give it to a driver and car, follow it, take in drivers' cash. */
class DispatchModule extends StaffModule
{
    public function __construct(private RideService $rides)
    {
    }

    public function key(): string { return 'dispatch'; }
    public function title(): string { return 'Car service'; }
    public function icon(): string { return 'local_taxi'; }
    public function section(): string { return 'Front desk'; }
    public function blurb(): string { return 'Book cars, give them to drivers, cash in'; }

    public function allowed(User $user): bool
    {
        return $user->can('Manage transport');
    }

    public function count(User $user): ?int
    {
        return Ride::where('status', Ride::REQUESTED)->count() ?: null;
    }

    public function tabs(User $user): array
    {
        $cash = Ride::where('cash_collected', '>', 0)->whereNull('cash_handed_at')->count();

        return [
            ['key' => 'new', 'label' => 'To assign'],
            ['key' => 'active', 'label' => 'Under way'],
            ['key' => 'cash', 'label' => 'Cash' . ($cash ? " ({$cash})" : '')],
            ['key' => 'done', 'label' => 'Today'],
        ];
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        $q = Ride::with(['driver', 'vehicle', 'reservation.rooms.room']);
        match ($tab) {
            'active' => $q->whereIn('status', Ride::ACTIVE)->orderByRaw('pickup_at IS NULL DESC')->orderBy('pickup_at'),
            'cash' => $q->where('cash_collected', '>', 0)->whereNull('cash_handed_at')->orderBy('completed_at'),
            'done' => $q->whereIn('status', [Ride::COMPLETED, Ride::CANCELLED])->where('updated_at', '>=', today())->latest('updated_at'),
            default => $q->where('status', Ride::REQUESTED)->orderByRaw('pickup_at IS NULL DESC')->orderBy('pickup_at'),
        };

        return $q->limit(80)->get()->map(fn (Ride $r) => $this->item($user, $r))->all();
    }

    public function find(int $id): ?Model
    {
        return Ride::with(['driver', 'vehicle', 'reservation.rooms.room'])->find($id);
    }

    public function item(User $user, Model $r): array
    {
        /** @var Ride $r */
        $room = $r->reservation?->rooms->pluck('room.number')->filter()->implode(', ');
        $soon = $r->status === Ride::REQUESTED && (! $r->pickup_at || $r->pickup_at->lt(now()->addMinutes(60)));

        return [
            'id' => $r->id,
            'title' => ($r->toHotel() ? 'Pick up: ' : 'To: ') . $r->place_label,
            'subtitle' => trim($r->number . ' · ' . ($r->guest_name ?? 'Guest') . ($room ? " · room {$room}" : '') . ' · ' . $r->when_label),
            'badge' => ['label' => $r->no_show ? 'No-show' : $r->staff_label, 'tone' => self::tone($r->status_pill)],
            'amount' => $r->fare !== null ? $this->rides->money((float) $r->fare) : null,
            'chips' => array_values(array_filter([
                ['label' => $r->passengers . ' pax' . ($r->bags ? ", {$r->bags} bags" : ''), 'tone' => 'muted'],
                ['label' => ['room' => 'On the bill', 'cash' => 'Cash', 'card' => 'Card'][$r->payment] ?? $r->payment, 'tone' => 'muted'],
                $r->flight ? ['label' => 'Flight ' . $r->flight, 'tone' => 'info'] : null,
                $r->driver ? ['label' => $r->driver->name . ($r->vehicle ? ' · ' . $r->vehicle->plate : ''), 'tone' => 'info'] : null,
                $r->cash_collected > 0 && ! $r->cash_handed_at ? ['label' => 'Cash ' . $this->rides->money((float) $r->cash_collected) . ' with driver', 'tone' => 'warning'] : null,
            ])),
            'urgent' => $soon,
            'live' => in_array($r->status, [Ride::ON_THE_WAY, Ride::ARRIVED, Ride::ON_TRIP], true),
            'phone' => $r->guest_phone,
            'lines' => self::lines([
                'Notes' => $r->notes, 'Driver phone' => $r->driver?->phone, 'Distance' => $r->distance_km ? $r->distance_km . ' km' : null,
                'Kilometres' => $r->start_km !== null ? number_format($r->start_km) . ' → ' . ($r->end_km !== null ? number_format($r->end_km) : '…') : null,
                'ETA' => $r->eta_minutes ? $r->eta_minutes . ' min' : null, 'Cancelled because' => $r->cancel_reason,
            ]),
            'actions' => $this->actions($user, $r),
        ];
    }

    public function actions(User $user, Model $r): array
    {
        /** @var Ride $r */
        $a = [];
        if (in_array($r->status, [Ride::REQUESTED, Ride::ASSIGNED], true)) {
            $drivers = $this->rides->candidates($r)->map(fn (Driver $d) => ['value' => (string) $d->id,
                'label' => $d->name . ($d->vehicle ? ' · ' . $d->vehicle->plate : '') . ($d->on_duty ? ($d->free ? ' · free' : ' · busy') : ' · off duty') . ($d->km_away !== null ? " · {$d->km_away} km" : '')])->values()->all();
            $cars = Vehicle::where('is_active', true)->orderBy('name')->get()->map(fn (Vehicle $v) => ['value' => (string) $v->id,
                'label' => $v->label . ' · ' . $v->seats . ' seats' . ($v->status !== 'available' ? ' (out of service)' : '') . ($v->expiredPapers() ? ' (papers ran out)' : '')])->all();
            $a[] = ['id' => 'assign', 'label' => $r->driver_id ? 'Change driver' : 'Give to a driver', 'tone' => 'primary', 'fields' => [
                self::field('driver_id', 'Driver', 'select', ['required' => true, 'options' => $drivers, 'value' => $r->driver_id ? (string) $r->driver_id : null]),
                self::field('vehicle_id', 'Car (empty: their usual car)', 'select', ['options' => $cars]),
                self::field('fare', 'Price (empty: keep ' . ($r->fare !== null ? $this->rides->money((float) $r->fare) : 'to be confirmed') . ')', 'money'),
            ]];
            if ($r->status === Ride::REQUESTED) {
                $a[] = ['id' => 'auto', 'label' => 'Nearest free driver', 'tone' => 'neutral'];
            }
        }
        if ($r->isOpen()) {
            $a[] = ['id' => 'cancel', 'label' => 'Cancel ride', 'tone' => 'danger', 'fields' => [self::field('reason', 'Why', 'text', ['required' => true])]];
        }
        if ((float) $r->cash_collected > 0 && ! $r->cash_handed_at) {
            $a[] = ['id' => 'cash_in', 'label' => 'Cash received', 'tone' => 'primary', 'confirm' => 'Have you counted ' . $this->rides->money((float) $r->cash_collected) . ' from ' . ($r->driver?->name ?? 'the driver') . '? It goes into your till.'];
        }

        return $a;
    }

    public function perform(User $user, Model $r, string $action, array $input, array $files): string
    {
        /** @var Ride $r */
        abort_unless($user->can('Manage transport'), 403);
        switch ($action) {
            case 'assign':
                $driver = Driver::findOrFail((int) $input['driver_id']);
                $this->rides->assign($r, $driver, ! empty($input['vehicle_id']) ? Vehicle::find($input['vehicle_id']) : null, $user, $input['fare'] ?? null);

                return "{$r->number} given to {$driver->name}.";
            case 'auto':
                return ($d = $this->rides->autoAssign($r, $user)) ? "{$r->number} given to {$d->name}." : throw new BookingException('No driver is free with a suitable car right now.');
            case 'cancel':
                $this->rides->cancel($r, (string) $input['reason'], $user);

                return "{$r->number} cancelled.";
            case 'cash_in':
                return $this->rides->cashHandedIn($r, $user);
        }
        throw new BookingException('Unknown action.');
    }

    public function form(User $user): ?array
    {
        $stays = Reservation::whereIn('status', [S::Pending->value, S::Confirmed->value, S::CheckedIn->value])
            ->where('check_out_date', '>=', today())->where('check_in_date', '<=', today()->addDays(14))
            ->with('guest:id,first_name,last_name', 'rooms.room:id,number')->orderBy('check_in_date')->limit(200)->get()
            ->map(fn ($r) => ['value' => (string) $r->id, 'label' => trim(($r->rooms->first()?->room?->number ? $r->rooms->first()->room->number . ' — ' : '') . $r->guest?->full_name . ' · ' . $r->reference)])->all();

        return ['title' => 'Book a car', 'submit' => 'Book', 'fields' => [
            self::field('reservation_id', 'For which guest', 'select', ['required' => true, 'options' => $stays]),
            self::field('direction', 'Trip', 'select', ['required' => true, 'value' => 'from_hotel', 'options' => [['value' => 'from_hotel', 'label' => 'From the hotel'], ['value' => 'to_hotel', 'label' => 'To the hotel (pick-up)']]]),
            self::field('place_id', 'Place', 'select', ['options' => RidePlace::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->map(fn ($p) => ['value' => (string) $p->id, 'label' => $p->name . ($p->fare !== null ? ' · ' . $this->rides->money((float) $p->fare) : '')])->all()]),
            self::field('label', 'Or address', 'text'),
            self::field('pickup_at', 'Pick-up time (empty: now)', 'datetime'),
            self::field('passengers', 'Passengers', 'number', ['value' => 1, 'min' => 1, 'max' => 20]),
            self::field('bags', 'Bags', 'number', ['value' => 0, 'min' => 0, 'max' => 30]),
            self::field('flight', 'Flight', 'text', ['max' => 20]),
            self::field('payment', 'Payment', 'select', ['value' => 'room', 'options' => [['value' => 'room', 'label' => 'On the bill'], ['value' => 'cash', 'label' => 'Cash to driver'], ['value' => 'card', 'label' => 'Card']]]),
            self::field('fare', 'Price (empty: worked out)', 'money'),
            self::field('notes', 'Notes', 'textarea', ['max' => 300]),
        ]];
    }

    public function create(User $user, array $input, array $files): array
    {
        abort_unless($user->can('Manage transport'), 403);
        $ride = $this->rides->book(Reservation::findOrFail((int) $input['reservation_id']), array_filter($input, fn ($v) => $v !== null), 'desk');
        if (isset($input['fare'])) {
            $ride->forceFill(['fare' => round((float) $input['fare'], 2)])->save();
        }

        return ["Car {$ride->number} booked. Now give it to a driver.", $ride];
    }

    public function emptyText(?string $tab): string
    {
        return match ($tab) {
            'active' => 'No cars on the road.',
            'cash' => 'No cash waiting to be handed in.',
            'done' => 'No finished rides today.',
            default => 'Nothing to assign.',
        };
    }
}
