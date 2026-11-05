<?php

namespace App\Services\Mobile\Modules;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus as S;
use App\Exceptions\BookingException;
use App\Exceptions\OutstandingBalanceException;
use App\Models\Billing\PaymentTerminal;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Billing\TerminalService;
use App\Services\Reservations\AvailabilityService;
use App\Services\Reservations\ReservationService;
use Illuminate\Database\Eloquent\Model;

/**
 * The front desk on a phone: today's arrivals, who's in house, today's
 * departures. Give a room, check in, take a payment (cash, transfer or the
 * card terminal), check out, mark a no-show — the website's own rules.
 */
class StaysModule extends StaffModule
{
    public function __construct(
        private ReservationService $reservations,
        private FolioService $folios,
        private AvailabilityService $availability,
        private TerminalService $terminals,
    ) {
    }

    public function key(): string { return 'stays'; }
    public function title(): string { return 'Check-in & out'; }
    public function icon(): string { return 'hotel'; }
    public function section(): string { return 'Front desk'; }
    public function blurb(): string { return 'Rooms, check-in, payments, check-out'; }

    public function allowed(User $user): bool
    {
        return $user->can('View reservations') && $user->canAny(['Check in guests', 'Check out guests', 'Receive payments', 'Update reservations']);
    }

    public function count(User $user): ?int
    {
        return Reservation::arrivingOn(today())->count() + Reservation::departingOn(today())->count() ?: null;
    }

    public function tabs(User $user): array
    {
        return [['key' => 'arrivals', 'label' => 'Arriving'], ['key' => 'in_house', 'label' => 'In house'], ['key' => 'departures', 'label' => 'Leaving']];
    }

    public function searchable(): bool
    {
        return true;
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        $q = Reservation::with(['guest:id,title,first_name,last_name,phone,is_vip', 'rooms.room:id,number,status', 'rooms.roomType:id,name', 'folio']);
        if ($search) {
            $q->whereIn('status', [S::Pending->value, S::Confirmed->value, S::CheckedIn->value])
                ->where(fn ($w) => $w->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('guest', fn ($g) => $g->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                    ->orWhereHas('rooms.room', fn ($r) => $r->where('number', $search)));
        } else {
            match ($tab) {
                'in_house' => $q->inHouse(),
                'departures' => $q->departingOn(today()),
                // Today's arrivals, and anyone due earlier who hasn't come yet.
                default => $q->whereIn('status', [S::Pending->value, S::Confirmed->value])->whereDate('check_in_date', '<=', today())->whereDate('check_out_date', '>', today()),
            };
        }

        return $q->orderBy('check_in_date')->orderBy('id')->limit(150)->get()->map(fn ($r) => $this->item($user, $r))->all();
    }

    public function find(int $id): ?Model
    {
        return Reservation::with(['guest', 'rooms.room:id,number,status', 'rooms.roomType:id,name', 'folio'])->find($id);
    }

    public function item(User $user, Model $r): array
    {
        /** @var Reservation $r */
        $rooms = $r->rooms->map(fn ($l) => $l->room?->number)->filter()->implode(', ');
        $balance = $r->folio ? round($r->folio->balance(), 2) : 0.0;

        return [
            'id' => $r->id,
            'title' => ($r->guest?->full_name ?? 'Guest') . ($r->guest?->is_vip ? ' ★' : ''),
            'subtitle' => $r->reference . ' · ' . ($rooms !== '' ? 'Room ' . $rooms : $r->rooms->pluck('roomType.name')->filter()->unique()->implode(', ') . ' — no room yet')
                . ' · ' . $r->check_in_date->format('j M') . ' → ' . $r->check_out_date->format('j M'),
            'badge' => ['label' => $r->status->label(), 'tone' => match ($r->status) { S::CheckedIn => 'success', S::Confirmed => 'info', S::Pending => 'warning', default => 'muted' }],
            'chips' => array_values(array_filter([
                $balance >= 0.01 ? ['label' => 'Owes ' . $this->folios->money($balance), 'tone' => 'warning'] : ($r->folio ? ['label' => 'Paid up', 'tone' => 'success'] : null),
                $r->check_in_date->lt(today()) && $r->isArrivalDue() ? ['label' => 'Due ' . $r->check_in_date->format('j M'), 'tone' => 'danger'] : null,
                $r->expected_arrival_time ? ['label' => 'Arrives ' . $r->expected_arrival_time, 'tone' => 'muted'] : null,
            ])),
            'amount' => $r->folio ? $this->folios->money($balance) : null,
            'phone' => $r->guest?->phone,
            'lines' => self::lines([
                'Guests' => trim(($r->adults ?? '') . ' adult(s)' . ($r->children ? ", {$r->children} child(ren)" : '')),
                'Nights' => $r->nights, 'Phone' => $r->guest?->phone, 'E-mail' => $r->guest?->email,
                'Bill' => $r->folio ? $r->folio->number . ' · balance ' . $this->folios->money($balance) : null,
                'Notes' => $r->notes ?? null,
            ]),
            'web' => route('reservations.show', $r),
            'actions' => $this->actions($user, $r),
        ];
    }

    public function actions(User $user, Model $r): array
    {
        /** @var Reservation $r */
        $a = [];
        $due = in_array($r->status, [S::Pending, S::Confirmed], true);
        if ($due && $user->can('Update reservations')) {
            foreach ($r->rooms as $line) {
                if ($line->room_id) {
                    continue;
                }
                $free = $this->availability->freeRooms($r->check_in_date, $r->check_out_date, $line->room_type_id, $r->id)
                    ->map(fn (Room $x) => ['value' => (string) $x->id, 'label' => 'Room ' . $x->number . ($x->status?->value !== 'available' ? ' (' . $x->status?->label() . ')' : '')])->values()->all();
                $a[] = ['id' => 'room_' . $line->id, 'label' => 'Give a room' . ($r->rooms->count() > 1 ? ' (' . ($line->roomType?->name ?? 'room') . ')' : ''), 'tone' => 'neutral',
                    'fields' => [self::field('room_id', 'Room', 'select', ['required' => true, 'options' => $free])]];
            }
        }
        if ($due && $r->check_in_date->lte(today()) && $user->can('Check in guests')) {
            $a[] = ['id' => 'check_in', 'label' => 'Check in', 'tone' => 'primary', 'confirm' => 'Check in ' . ($r->guest?->full_name ?? 'this guest') . '?'];
        }
        if ($r->folio && $r->folio->isOpen() && $user->can('Receive payments')) {
            $balance = max(0, round($r->folio->balance(), 2));
            $terminals = $this->terminals->available()->map(fn (PaymentTerminal $t) => ['value' => (string) $t->id, 'label' => $t->name . ' · ' . $t->provider_label])->values()->all();
            if ($terminals) {
                $a[] = ['id' => 'card', 'label' => 'Card on terminal', 'tone' => 'primary', 'fields' => [
                    self::field('terminal_id', 'Terminal', 'select', ['required' => true, 'options' => $terminals, 'value' => $terminals[0]['value']]),
                    self::field('amount', 'Amount', 'money', ['required' => true, 'min' => 1, 'value' => $balance ?: null]),
                ]];
            }
            $a[] = ['id' => 'pay', 'label' => 'Record payment', 'tone' => 'neutral', 'fields' => [
                self::field('method', 'How', 'select', ['required' => true, 'value' => 'cash', 'options' => collect(PaymentMethod::takeable())->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()])->all()]),
                self::field('amount', 'Amount', 'money', ['required' => true, 'min' => 0.01, 'value' => $balance ?: null]),
                self::field('reference', 'Reference (transfer, card or cheque)', 'text', ['max' => 100]),
            ]];
        }
        if ($r->status === S::CheckedIn && $user->can('Check out guests')) {
            $fields = $user->can('Check out with balance') ? [self::field('balance_reason', 'If they still owe: why they may leave', 'text')] : [];
            $a[] = ['id' => 'check_out', 'label' => 'Check out', 'tone' => 'primary', 'confirm' => 'Check out ' . ($r->guest?->full_name ?? 'this guest') . '? The room goes to housekeeping.', 'fields' => $fields];
        }
        if ($due && $r->check_in_date->lt(today()->addDay()) && $user->can('Cancel reservations')) {
            $a[] = ['id' => 'no_show', 'label' => 'No-show', 'tone' => 'danger', 'confirm' => 'Mark as a no-show?'];
        }

        return $a;
    }

    public function perform(User $user, Model $r, string $action, array $input, array $files): string
    {
        /** @var Reservation $r */
        if (str_starts_with($action, 'room_')) {
            abort_unless($user->can('Update reservations'), 403);
            $line = $r->rooms->firstWhere('id', (int) substr($action, 5)) ?? throw new BookingException('That room line is gone.');
            $this->reservations->assignRoom($line, Room::findOrFail((int) $input['room_id']));

            return 'Room ' . Room::find($input['room_id'])->number . ' given.';
        }

        switch ($action) {
            case 'check_in':
                abort_unless($user->can('Check in guests'), 403);
                $this->reservations->checkIn($r);

                return 'Checked in. Welcome them!';
            case 'check_out':
                abort_unless($user->can('Check out guests'), 403);
                $reason = $input['balance_reason'] ?? null;
                if ($reason !== null) {
                    abort_unless($user->can('Check out with balance'), 403);
                }
                try {
                    $this->reservations->checkOut($r, $reason);
                } catch (OutstandingBalanceException $e) {
                    throw new BookingException('They still owe ' . $this->folios->money($e->balance) . '. Take the payment first' . ($user->can('Check out with balance') ? ', or say why they may leave owing.' : '.'));
                }

                return 'Checked out. The room is on the cleaning list.';
            case 'no_show':
                abort_unless($user->can('Cancel reservations'), 403);
                $this->reservations->markNoShow($r);

                return 'Marked as no-show.';
            case 'pay':
                abort_unless($user->can('Receive payments'), 403);
                $method = PaymentMethod::from((string) $input['method']);
                if ($method === PaymentMethod::Company) {
                    throw new BookingException('Use “Bill to company” for that.');
                }
                if ($method->needsReference() && blank($input['reference'] ?? null)) {
                    throw new BookingException('Enter the transfer, card or cheque reference.');
                }
                $p = $this->folios->receivePayment($r->folio ?? throw new BookingException('This stay has no bill yet.'), PaymentKind::Payment, $method, (float) $input['amount'], $input['reference'] ?? null, 'Staff app');

                return $this->folios->money((float) $p->amount) . ' recorded' . ($p->receipt_number ? " (receipt {$p->receipt_number})" : '') . '.';
            case 'card':
                abort_unless($user->can('Receive payments'), 403);
                $t = PaymentTerminal::findOrFail((int) $input['terminal_id']);
                $p = $this->terminals->request($t, (float) $input['amount'], ['folio' => $r->folio], $user, 'app');

                return 'Sent ' . $this->folios->money((float) $p->amount) . " to {$t->name}. Ask the guest to tap or insert their card — follow it under Card payments.";
        }
        throw new BookingException('Unknown action.');
    }

    public function emptyText(?string $tab): string
    {
        return match ($tab) {
            'in_house' => 'Nobody in house.',
            'departures' => 'No more departures today.',
            default => 'No more arrivals today.',
        };
    }
}
