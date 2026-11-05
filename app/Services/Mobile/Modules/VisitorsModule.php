<?php

namespace App\Services\Mobile\Modules;

use App\Exceptions\BookingException;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Security\SecurityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** The gate: who is on site, signing visitors in and out, checking a pass. */
class VisitorsModule extends StaffModule
{
    public function __construct(private SecurityService $security)
    {
    }

    public function key(): string { return 'visitors'; }
    public function title(): string { return 'Visitors'; }
    public function icon(): string { return 'badge'; }
    public function section(): string { return 'Security'; }
    public function blurb(): string { return 'Sign visitors in and out, check passes'; }

    public function allowed(User $user): bool
    {
        return $user->can('View visitors');
    }

    public function count(User $user): ?int
    {
        return Visitor::onSite()->count() ?: null;
    }

    public function tabs(User $user): array
    {
        return [['key' => 'on_site', 'label' => 'On site'], ['key' => 'today', 'label' => 'Today']];
    }

    public function searchable(): bool
    {
        return true;
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        return Visitor::with(['reservation.guest', 'reservation.rooms.room:id,number', 'hostUser:id,name'])
            ->when($tab === 'today', fn ($q) => $q->where('signed_in_at', '>=', today()), fn ($q) => $q->onSite())
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('full_name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('company', 'like', "%{$search}%")->orWhere('vehicle_plate', 'like', "%{$search}%")->orWhere('pass_code', $search)))
            ->orderByRaw('signed_out_at is not null')->latest('signed_in_at')->limit(100)->get()
            ->map(fn (Visitor $v) => $this->item($user, $v))->all();
    }

    public function find(int $id): ?Model
    {
        return Visitor::with(['reservation.guest', 'reservation.rooms.room:id,number', 'hostUser:id,name'])->find($id);
    }

    public function item(User $user, Model $v): array
    {
        /** @var Visitor $v */
        $state = ! $v->isOnSite() ? ['Left ' . $v->signed_out_at->format('g:i A'), 'muted'] : ($v->isOverstayed() ? ['Overstayed', 'danger'] : ['On site', 'success']);

        return [
            'id' => $v->id,
            'title' => $v->full_name . ($v->party_size > 1 ? " +" . ($v->party_size - 1) : ''),
            'subtitle' => $v->purpose_label . ' · ' . $v->visiting,
            'badge' => ['label' => $state[0], 'tone' => $state[1]],
            'chips' => array_values(array_filter([
                ['label' => 'In ' . $v->signed_in_at->format('g:i A'), 'tone' => 'muted'],
                $v->isOnSite() && $v->expected_out_at ? ['label' => 'Until ' . $v->expected_out_at->format('g:i A'), 'tone' => $v->isOverstayed() ? 'danger' : 'muted'] : null,
                $v->vehicle_plate ? ['label' => $v->vehicle_plate, 'tone' => 'info'] : null,
            ])),
            'urgent' => $v->isOverstayed(),
            'lines' => self::lines([
                'Pass' => $v->pass_code, 'Phone' => $v->phone, 'Company' => $v->company,
                'ID' => trim(($v->id_type ?? '') . ' ' . ($v->id_number ?? '')), 'Car' => $v->vehicle_plate,
                'Signed in' => self::when($v->signed_in_at), 'Signed out' => self::when($v->signed_out_at), 'Notes' => $v->notes,
            ]),
            'phone' => $v->phone,
            'actions' => $this->actions($user, $v),
        ];
    }

    public function actions(User $user, Model $v): array
    {
        /** @var Visitor $v */
        if (! $user->can('Manage visitors') || ! $v->isOnSite()) {
            return [];
        }

        return [
            ['id' => 'sign_out', 'label' => 'Sign out', 'tone' => 'primary'],
            ['id' => 'extend', 'label' => 'Extend pass', 'tone' => 'neutral', 'fields' => [self::field('until', 'Valid until', 'datetime', ['required' => true])]],
        ];
    }

    public function perform(User $user, Model $v, string $action, array $input, array $files): string
    {
        /** @var Visitor $v */
        abort_unless($user->can('Manage visitors'), 403);
        if ($action === 'sign_out') {
            $this->security->signOut($v, $user);

            return "{$v->full_name} signed out.";
        }
        if ($action === 'extend') {
            $this->security->extend($v, Carbon::parse($input['until']));

            return 'Pass extended.';
        }
        throw new BookingException('Unknown action.');
    }

    public function form(User $user): ?array
    {
        if (! $user->can('Manage visitors')) {
            return null;
        }
        $inHouse = Reservation::inHouse()->with(['guest:id,first_name,last_name', 'rooms.room:id,number'])->get()
            ->map(fn ($r) => ['value' => (string) $r->id, 'label' => trim(($r->rooms->first()?->room?->number ? $r->rooms->first()->room->number . ' — ' : '') . $r->guest?->full_name)])
            ->sortBy('label')->values()->all();

        return ['title' => 'Sign a visitor in', 'submit' => 'Sign in & give pass', 'fields' => [
            self::field('full_name', 'Full name', 'text', ['required' => true, 'max' => 150]),
            self::field('phone', 'Phone', 'phone'),
            self::field('purpose', 'Purpose', 'select', ['required' => true, 'value' => 'guest_visit', 'options' => self::options(Visitor::PURPOSES)]),
            self::field('reservation_id', 'Visiting guest (room)', 'select', ['options' => $inHouse]),
            self::field('host', 'Or visiting (name / department)', 'text', ['max' => 150]),
            self::field('id_type', 'ID type', 'select', ['options' => array_map(fn ($t) => ['value' => $t, 'label' => $t], Visitor::ID_TYPES)]),
            self::field('id_number', 'ID number', 'text', ['max' => 50]),
            self::field('company', 'Company', 'text', ['max' => 120]),
            self::field('vehicle_plate', 'Car plate', 'text', ['max' => 20]),
            self::field('party_size', 'People in the party', 'number', ['value' => 1, 'min' => 1, 'max' => 50]),
            self::field('notes', 'Notes', 'textarea', ['max' => 500]),
        ]];
    }

    public function create(User $user, array $input, array $files): array
    {
        abort_unless($user->can('Manage visitors'), 403);
        $input['party_size'] = (int) ($input['party_size'] ?? 1) ?: 1;
        $v = $this->security->signIn(array_filter($input, fn ($x) => $x !== null), $user);

        return ["{$v->full_name} signed in. Pass {$v->pass_code}, valid until " . $v->expected_out_at?->format('g:i A') . '.', $v];
    }

    public function scanner(User $user): ?array
    {
        return ['label' => 'Check a pass', 'hint' => 'Scan the QR code on the visitor’s pass, or type its code.'];
    }

    public function scan(User $user, string $code): array
    {
        $r = $this->security->checkPass($code);
        $v = $r['visitor'];

        return [
            'state' => match ($r['state']) { 'valid' => 'ok', 'expired' => 'warn', default => 'bad' },
            'title' => $v ? $v->full_name : 'Not valid',
            'message' => $r['message'] . ($v ? ' ' . $v->purpose_label . ' · ' . $v->visiting . '.' : ''),
            'item' => $v ? $this->item($user, $v->load(['reservation.guest', 'reservation.rooms.room:id,number', 'hostUser:id,name'])) : null,
        ];
    }

    public function emptyText(?string $tab): string
    {
        return $tab === 'today' ? 'No visitors today.' : 'No visitors on site.';
    }
}
