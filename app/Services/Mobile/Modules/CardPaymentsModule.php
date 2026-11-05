<?php

namespace App\Services\Mobile\Modules;

use App\Exceptions\BookingException;
use App\Models\Billing\PaymentTerminal;
use App\Models\Billing\TerminalPayment;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Billing\TerminalService;
use Illuminate\Database\Eloquent\Model;

/**
 * Send an amount to a Paystack / Moniepoint / OPay card terminal and watch it
 * get paid. A guest's bill is paid off by itself when the card goes through.
 */
class CardPaymentsModule extends StaffModule
{
    public function __construct(private TerminalService $terminals)
    {
    }

    public function key(): string { return 'card_payments'; }
    public function title(): string { return 'Card payments'; }
    public function icon(): string { return 'contactless'; }
    public function section(): string { return 'Money'; }
    public function blurb(): string { return 'Send a bill to the card terminal'; }

    public function allowed(User $user): bool
    {
        return $this->terminals->enabled() && $user->canAny(['Receive payments', 'Use POS', 'Manage card terminals']);
    }

    public function count(User $user): ?int
    {
        return TerminalPayment::open()->where('requested_by', $user->id)->count() ?: null;
    }

    public function tabs(User $user): array
    {
        return array_values(array_filter([
            ['key' => 'mine', 'label' => 'Mine'],
            $user->can('Manage card terminals') ? ['key' => 'all', 'label' => 'All today'] : null,
        ]));
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        $rows = TerminalPayment::with(['terminal', 'payment', 'folio'])
            ->when($tab === 'all' && $user->can('Manage card terminals'), fn ($q) => $q->where('created_at', '>=', today()), fn ($q) => $q->where('requested_by', $user->id)->where('created_at', '>=', now()->subDays(3)))
            ->latest('id')->limit(60)->get();
        // Ask the providers about the ones still waiting, so the list is up to date.
        $rows->filter->isOpen()->take(5)->each(fn (TerminalPayment $p) => $this->safeRefresh($p));

        return $rows->map(fn ($p) => $this->item($user, $p->fresh(['terminal', 'payment', 'folio'])))->all();
    }

    private function safeRefresh(TerminalPayment $p): void
    {
        try {
            $this->terminals->refresh($p);
        } catch (\Throwable) {
        }
    }

    public function find(int $id): ?Model
    {
        return TerminalPayment::with(['terminal', 'payment', 'folio'])->find($id);
    }

    public function item(User $user, Model $p): array
    {
        /** @var TerminalPayment $p */
        abort_unless($p->requested_by === $user->id || $user->canAny(['Manage card terminals', 'Receive payments']), 403);
        [$label, $pill] = TerminalPayment::STATUSES[$p->status] ?? [ucfirst($p->status), 'st-muted'];

        return [
            'id' => $p->id,
            'title' => $p->description ?: 'Card payment',
            'subtitle' => ($p->terminal?->name ?? 'Terminal') . ' · ' . $p->reference . ' · ' . self::when($p->created_at),
            'badge' => ['label' => $label, 'tone' => self::tone($pill)],
            'amount' => self::money($p->amount),
            'live' => $p->isOpen(),
            'chips' => array_values(array_filter([
                $p->card ? ['label' => $p->card, 'tone' => 'muted'] : null,
                $p->approval ? ['label' => 'Approval ' . $p->approval, 'tone' => 'muted'] : null,
                $p->payment?->receipt_number ? ['label' => 'Receipt ' . $p->payment->receipt_number, 'tone' => 'success'] : null,
            ])),
            'lines' => self::lines(['Message' => $p->message, 'Paid' => self::when($p->paid_at), 'Bill' => $p->folio?->number]),
            'actions' => $this->actions($user, $p),
        ];
    }

    public function actions(User $user, Model $p): array
    {
        /** @var TerminalPayment $p */
        return $p->isOpen() ? [
            ['id' => 'check', 'label' => 'Check now', 'tone' => 'neutral'],
            ['id' => 'cancel', 'label' => 'Cancel', 'tone' => 'danger', 'confirm' => 'Cancel this payment on the terminal?'],
        ] : [];
    }

    public function perform(User $user, Model $p, string $action, array $input, array $files): string
    {
        /** @var TerminalPayment $p */
        $p->load('terminal');
        if ($action === 'check') {
            $p = $this->terminals->refresh($p);

            return $p->status === TerminalPayment::PAID ? 'Paid.' : ($p->isOpen() ? 'Still waiting for the card.' : $p->status_label . '.');
        }
        if ($action === 'cancel') {
            $p = $this->terminals->cancel($p, $user);

            return $p->status === TerminalPayment::PAID ? 'It was already paid.' : 'Cancelled.';
        }
        throw new BookingException('Unknown action.');
    }

    public function form(User $user): ?array
    {
        $terminals = $this->terminals->available()->map(fn (PaymentTerminal $t) => ['value' => (string) $t->id, 'label' => $t->name . ' · ' . $t->provider_label])->values()->all();
        if (! $terminals || ! $user->canAny(['Receive payments', 'Use POS'])) {
            return null;
        }
        $fields = [
            self::field('terminal_id', 'Terminal', 'select', ['required' => true, 'options' => $terminals, 'value' => $terminals[0]['value']]),
            self::field('amount', 'Amount', 'money', ['required' => true, 'min' => 1, 'max' => 99999999]),
        ];
        if ($user->can('Receive payments')) {
            $fields[] = self::field('reservation_id', 'Guest’s bill (pays it off)', 'select', ['options' => Reservation::inHouse()->with(['guest:id,first_name,last_name', 'rooms.room:id,number'])->get()
                ->map(fn ($r) => ['value' => (string) $r->id, 'label' => trim(($r->rooms->first()?->room?->number ? $r->rooms->first()->room->number . ' — ' : '') . $r->guest?->full_name)])
                ->sortBy('label')->values()->all()]);
        }
        $fields[] = self::field('description', 'What for (if not a guest’s bill)', 'text', ['max' => 120]);

        return ['title' => 'Take a card payment', 'submit' => 'Send to terminal', 'fields' => $fields];
    }

    public function create(User $user, array $input, array $files): array
    {
        $folio = null;
        if (! empty($input['reservation_id'])) {
            abort_unless($user->can('Receive payments'), 403);
            $folio = Reservation::find($input['reservation_id'])?->folio ?? throw new BookingException('That guest has no bill yet.');
        } else {
            abort_unless($user->canAny(['Receive payments', 'Use POS']), 403);
        }
        $t = PaymentTerminal::findOrFail((int) $input['terminal_id']);
        $p = $this->terminals->request($t, (float) $input['amount'], array_filter(['folio' => $folio]), $user, 'app', $input['description'] ?? null);

        return ['Sent ' . self::money($p->amount) . " to {$t->name}. Ask for the card.", $p];
    }

    public function emptyText(?string $tab): string
    {
        return 'No card payments yet.';
    }
}
