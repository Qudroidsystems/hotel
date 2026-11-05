<?php

namespace App\Services\Mobile\Modules;

use App\Exceptions\BookingException;
use App\Models\Transport\Vehicle;
use App\Models\Transport\VehicleLog;
use App\Models\User;
use App\Services\Transport\RideService;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

/** The hotel's cars: papers, servicing, checks and fuel; taking a car off the road and back. */
class FleetModule extends StaffModule
{
    public function __construct(private RideService $rides)
    {
    }

    public function key(): string { return 'fleet'; }
    public function title(): string { return 'Cars'; }
    public function icon(): string { return 'directions_car'; }
    public function section(): string { return 'Front desk'; }
    public function blurb(): string { return 'Papers, services, checks and fuel'; }

    public function allowed(User $user): bool
    {
        return $user->can('Manage transport');
    }

    public function count(User $user): ?int
    {
        return Vehicle::where('is_active', true)->get()->filter(fn (Vehicle $v) => $v->expiredPapers() || $v->serviceDue() || $v->status !== 'available')->count() ?: null;
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        return Vehicle::where('is_active', true)->orderBy('name')->get()->map(fn (Vehicle $v) => $this->item($user, $v))->all();
    }

    public function find(int $id): ?Model
    {
        return Vehicle::find($id);
    }

    public function item(User $user, Model $v): array
    {
        /** @var Vehicle $v */
        $expired = $v->expiredPapers();
        $due = $v->serviceDue();
        $last = $v->logs()->with('driver:id,name', 'user:id,name')->limit(8)->get();

        return [
            'id' => $v->id,
            'title' => $v->label,
            'subtitle' => (Vehicle::KINDS[$v->kind] ?? $v->kind) . ' · ' . $v->seats . ' seats' . ($v->odometer_km !== null ? ' · ' . number_format($v->odometer_km) . ' km' : ''),
            'badge' => $v->status !== 'available' ? ['label' => 'Off the road', 'tone' => 'danger'] : ($expired ? ['label' => 'Papers ran out', 'tone' => 'danger'] : ['label' => 'In service', 'tone' => 'success']),
            'chips' => array_values(array_filter([
                $due ? ['label' => $due, 'tone' => 'warning'] : null,
                ...array_map(fn ($p) => ['label' => $p, 'tone' => 'warning'], $v->papersEnding()),
                $v->checked_at ? ['label' => 'Checked ' . self::when($v->checked_at), 'tone' => 'muted'] : ['label' => 'Never checked', 'tone' => 'muted'],
            ])),
            'urgent' => (bool) $expired || $v->status !== 'available',
            'lines' => self::lines([
                'Insurance until' => $v->insurance_expires?->format('j M Y'), 'Roadworthy until' => $v->roadworthy_expires?->format('j M Y'),
                'Service every' => $v->service_every_km ? number_format($v->service_every_km) . ' km' : null,
                'Last service at' => $v->last_service_km !== null ? number_format($v->last_service_km) . ' km' : null, 'Notes' => $v->notes,
            ]),
            'history' => $last->map(fn (VehicleLog $l) => ['who' => $l->driver?->name ?? $l->user?->name, 'at' => self::when($l->created_at),
                'what' => $l->kind_label . ($l->odometer_km ? ' · ' . number_format($l->odometer_km) . ' km' : '') . ($l->litres ? " · {$l->litres} L" : '')
                    . ($l->cost ? ' · ' . self::money($l->cost) : '') . ($l->failed ? ' · problems: ' . implode(', ', $l->failed) : '') . ($l->notes ? " — {$l->notes}" : '')])->all(),
            'actions' => $this->actions($user, $v),
        ];
    }

    public function actions(User $user, Model $v): array
    {
        /** @var Vehicle $v */
        return array_values(array_filter([
            ['id' => 'fuel', 'label' => 'Log fuel', 'tone' => 'neutral', 'fields' => [
                self::field('litres', 'Litres', 'number', ['required' => true, 'min' => 0.1, 'max' => 500]), self::field('cost', 'Cost', 'money'),
                self::field('km', 'Kilometres now', 'number', ['min' => 0, 'max' => 2000000]), self::field('photo', 'Receipt', 'photo'),
            ]],
            ['id' => 'service', 'label' => 'Log service / repair', 'tone' => 'neutral', 'fields' => [
                self::field('notes', 'What was done', 'textarea', ['required' => true, 'max' => 500]), self::field('cost', 'Cost', 'money'),
                self::field('km', 'Kilometres now', 'number', ['min' => 0, 'max' => 2000000]), self::field('next_service_on', 'Next service by (date)', 'date'),
                $v->status !== 'available' ? self::field('back_in_service', 'Back on the road', 'switch') : null,
            ]],
            $v->status === 'available'
                ? ['id' => 'off', 'label' => 'Take off the road', 'tone' => 'danger', 'fields' => [self::field('notes', 'Why', 'text', ['required' => true])]]
                : ['id' => 'on', 'label' => 'Back on the road', 'tone' => 'primary'],
            ['id' => 'papers', 'label' => 'Papers & servicing', 'tone' => 'neutral', 'fields' => [
                self::field('insurance_expires', 'Insurance until', 'date', ['value' => $v->insurance_expires?->toDateString()]),
                self::field('roadworthy_expires', 'Roadworthiness until', 'date', ['value' => $v->roadworthy_expires?->toDateString()]),
                self::field('service_every_km', 'Service every (km)', 'number', ['min' => 0, 'max' => 100000, 'value' => $v->service_every_km]),
                self::field('last_service_km', 'Last service at (km)', 'number', ['min' => 0, 'max' => 2000000, 'value' => $v->last_service_km]),
                self::field('service_due_on', 'Next service by (date)', 'date', ['value' => $v->service_due_on?->toDateString()]),
            ]],
        ]));
    }

    public function perform(User $user, Model $v, string $action, array $input, array $files): string
    {
        /** @var Vehicle $v */
        abort_unless($user->can('Manage transport'), 403);
        // An action's fields carry nulls for the "switch" helpers; strip them before re-validating.
        switch ($action) {
            case 'fuel':
            case 'service':
                $this->rides->logVehicle($v, $action, $input, $user, null, $files['photo'] ?? null);

                return $action === 'fuel' ? 'Fuel logged.' : 'Service logged.' . (! empty($input['back_in_service']) ? ' The car is back on the road.' : '');
            case 'off':
                $v->forceFill(['status' => 'out_of_service', 'notes' => mb_substr((string) $input['notes'], 0, 255)])->save();
                Audit::record('transport.vehicle_off', "{$v->label} taken off the road: {$input['notes']}", $v, by: $user);

                return "{$v->label} is off the road. Rides can’t be given to it.";
            case 'on':
                $v->forceFill(['status' => 'available'])->save();
                Audit::record('transport.vehicle_on', "{$v->label} back on the road", $v, by: $user);

                return "{$v->label} is back on the road.";
            case 'papers':
                $v->forceFill(collect($input)->only(['insurance_expires', 'roadworthy_expires', 'service_every_km', 'last_service_km', 'service_due_on'])->all())->save();

                return 'Saved.';
        }
        throw new BookingException('Unknown action.');
    }

    public function emptyText(?string $tab): string
    {
        return 'No cars yet. Add them on the website (Car Service → Cars).';
    }
}
