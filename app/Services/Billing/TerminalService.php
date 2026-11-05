<?php

namespace App\Services\Billing;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingException;
use App\Models\Billing\PaymentTerminal;
use App\Models\Billing\TerminalPayment;
use App\Models\Folio;
use App\Models\User;
use App\Services\Billing\Terminals\DemoTerminal;
use App\Services\Billing\Terminals\MoniepointTerminal;
use App\Services\Billing\Terminals\OpayTerminal;
use App\Services\Billing\Terminals\PaystackTerminal;
use App\Services\Billing\Terminals\TerminalDriver;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Card terminals (Billing → Card Terminals). Staff send an amount to a
 * terminal from the guest bill, the bar till or the staff app; the guest pays
 * on the terminal; the system asks the provider every few seconds (and when a
 * webhook arrives) and, once paid, records it by itself: a card payment on
 * the bill with the terminal's reference, or the till finishes the sale.
 * Nothing is recorded on a webhook's word alone: the provider is asked.
 *
 * Card terminals need the internet to reach the provider. When it is down,
 * staff take the card on the terminal as before and type its approval code.
 */
class TerminalService
{
    public function __construct(private FolioService $folios)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('hotel.terminals.enabled', true);
    }

    public function driver(string $provider): TerminalDriver
    {
        return match ($provider) {
            'paystack'   => app(PaystackTerminal::class),
            'moniepoint' => app(MoniepointTerminal::class),
            'opay'       => app(OpayTerminal::class),
            'demo'       => app(DemoTerminal::class),
            default      => throw new BookingException('Unknown terminal provider.'),
        };
    }

    /** Terminals staff can send to, the outlet's own first. */
    public function available(?int $outletId = null)
    {
        if (! $this->enabled()) {
            return collect();
        }

        return PaymentTerminal::active()->orderByRaw('case when pos_outlet_id = ? then 0 else 1 end', [$outletId ?? 0])->orderBy('name')->get()
            ->filter(fn (PaymentTerminal $t) => $this->driver($t->provider)->configured())->values();
    }

    /**
     * Send an amount to a terminal. $for: ['folio' => Folio] or ['outlet_id' => int] (a till sale) or [] (any other).
     */
    public function request(PaymentTerminal $terminal, float $amount, array $for, User $by, string $source = 'web', ?string $description = null): TerminalPayment
    {
        $amount = round($amount, 2);
        if (! $this->enabled() || ! $terminal->is_active) {
            throw new BookingException('This terminal is switched off.');
        }
        if ($amount <= 0) {
            throw new BookingException('Enter an amount greater than zero.');
        }
        $driver = $this->driver($terminal->provider);
        if (! $driver->configured()) {
            throw new BookingException("The {$terminal->provider_label} account isn't set up yet (Hotel Settings → Card Terminals).");
        }
        $folio = $for['folio'] ?? null;
        if ($folio instanceof Folio && ! $folio->isOpen()) {
            throw new BookingException('This bill is closed.');
        }
        $busy = TerminalPayment::open()->where('payment_terminal_id', $terminal->id)->where('created_at', '>=', now()->subMinutes((int) config('hotel.terminals.expire_minutes', 15)))->first();
        if ($busy) {
            throw new BookingException("{$terminal->name} is still waiting for a card for " . $this->folios->money((float) $busy->amount) . '. Cancel that one first.');
        }

        $p = TerminalPayment::create([
            'payment_terminal_id' => $terminal->id,
            'reference'           => 'TP' . now()->format('ymd') . strtoupper(Str::random(8)),
            'amount'              => $amount,
            'folio_id'            => $folio?->id,
            'pos_outlet_id'       => $for['outlet_id'] ?? null,
            'description'         => $description ?? ($folio ? 'Bill ' . $folio->number : 'Sale'),
            'requested_by'        => $by->id,
            'source'              => $source,
            'expires_at'          => now()->addMinutes((int) config('hotel.terminals.expire_minutes', 15)),
        ]);
        $p->setRelation('terminal', $terminal);

        try {
            [$ref, $id] = $driver->push($p);
        } catch (BookingException $e) {
            $p->forceFill(['status' => TerminalPayment::FAILED, 'message' => $e->getMessage()])->save();
            throw $e;
        }
        $p->forceFill(['status' => TerminalPayment::WAITING, 'provider_ref' => $ref ?: null, 'provider_id' => $id])->save();
        $terminal->forceFill(['last_seen_at' => now()])->save();
        Audit::record('billing.terminal_sent', "Sent {$this->folios->money($amount)} to card terminal {$terminal->name} ({$p->reference})", $folio, by: $by);

        return $p;
    }

    /** Ask the provider what happened (the screens call this every few seconds while waiting). */
    public function refresh(TerminalPayment $p): TerminalPayment
    {
        if (! $p->isOpen()) {
            return $p;
        }
        if ($p->expires_at && $p->expires_at->isPast()) {
            $this->driver($p->terminal->provider)->cancel($p);
            // One last look: a card paid in the last seconds still counts.
        }
        try {
            $s = $this->driver($p->terminal->provider)->status($p);
        } catch (BookingException $e) {
            $p->forceFill(['checked_at' => now(), 'message' => $e->getMessage()])->save();

            return $p;   // try again on the next check
        }
        $p->forceFill(['checked_at' => now(), 'raw' => $s['raw'] ?: $p->raw])->save();

        return match ($s['state']) {
            'paid'      => $this->settle($p, $s),
            'failed'    => $this->close($p, TerminalPayment::FAILED, $s['message'] ?: 'Declined'),
            'cancelled' => $this->close($p, TerminalPayment::CANCELLED, $s['message'] ?: 'Cancelled on the terminal'),
            default     => $p->expires_at && $p->expires_at->isPast() ? $this->close($p, TerminalPayment::EXPIRED, 'Not paid in time') : $p,
        };
    }

    public function cancel(TerminalPayment $p, User $by): TerminalPayment
    {
        $p = $this->refresh($p);   // paid already? then it stays paid
        if (! $p->isOpen()) {
            return $p;
        }
        $this->driver($p->terminal->provider)->cancel($p);

        return $this->close($p, TerminalPayment::CANCELLED, 'Cancelled by ' . $by->name);
    }

    /** The till finished the sale paid by this terminal payment. */
    public function linkSale(int $terminalPaymentId, string $orderUuid): void
    {
        TerminalPayment::whereKey($terminalPaymentId)->whereNull('pos_order_uuid')->update(['pos_order_uuid' => $orderUuid]);
    }

    /** Check every payment still waiting (terminals:check, every minute — catches the ones nobody is watching). */
    public function checkOpen(): int
    {
        $n = 0;
        TerminalPayment::open()->with('terminal')->where('created_at', '>=', now()->subDay())->get()
            ->each(function (TerminalPayment $p) use (&$n) {
                $n += $this->refresh($p)->status === TerminalPayment::PAID ? 1 : 0;
            });
        TerminalPayment::open()->where('created_at', '<', now()->subDay())->update(['status' => TerminalPayment::EXPIRED, 'message' => 'Not paid in time']);

        return $n;
    }

    /** A webhook named one of ours: look it up and ask the provider. */
    public function poke(string $provider, array $refs): void
    {
        $refs = array_values(array_filter(array_map('strval', $refs)));
        if (! $refs) {
            return;
        }
        $hotels = \App\Support\Tenancy\CurrentHotel::get();
        TerminalPayment::withoutGlobalScope('hotel')->open()
            ->where(fn ($q) => $q->whereIn('reference', $refs)->orWhereIn('provider_ref', $refs)->orWhereIn('provider_id', $refs))
            ->whereHas('terminal', fn ($q) => $q->withoutGlobalScope('hotel')->where('provider', $provider))
            ->get()->each(function (TerminalPayment $p) use ($hotels) {
                $hotels->adopt($p);
                try {
                    $this->refresh($p->load('terminal'));
                } catch (Throwable $e) {
                    Log::warning("Terminal payment {$p->reference} check failed: " . $e->getMessage());
                }
            });
    }

    /** Is the terminal reachable? (the set-up page's "Test" button) */
    public function test(PaymentTerminal $t): array
    {
        $driver = $this->driver($t->provider);
        if (! $driver->configured()) {
            return ['ok' => false, 'message' => "The {$t->provider_label} account isn't set up yet (Hotel Settings → Card Terminals)."];
        }
        try {
            $online = $driver->online($t);
        } catch (BookingException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $t->forceFill(['last_online' => $online, 'last_seen_at' => $online ? now() : $t->last_seen_at])->save();

        return ['ok' => $online !== false, 'message' => match ($online) {
            true    => 'Online and ready.',
            false   => 'The provider says this terminal is offline or busy. Switch it on and connect it to the internet.',
            default => "The {$t->provider_label} account answered. This provider doesn't say whether a terminal is on: send a small amount to try it.",
        }];
    }

    // ─────────────────────────────────────────────────────────────────

    private function settle(TerminalPayment $p, array $s): TerminalPayment
    {
        return DB::transaction(function () use ($p, $s) {
            $p = TerminalPayment::lockForUpdate()->find($p->id)->setRelation('terminal', $p->terminal);
            if (! $p->isOpen()) {
                return $p;
            }
            $paid = round((float) ($s['amount'] ?? $p->amount), 2) ?: (float) $p->amount;
            $p->forceFill([
                'status' => TerminalPayment::PAID, 'paid_at' => now(), 'paid_amount' => $paid,
                'approval' => $s['approval'] ?? null, 'card' => $s['card'] ?? null, 'message' => null,
            ])->save();

            if ($p->folio_id && ($folio = Folio::find($p->folio_id))) {
                $payment = $this->folios->receivePayment($folio, PaymentKind::Payment, PaymentMethod::Card, $paid,
                    $p->approval ?: $p->reference, 'Card terminal ' . $p->terminal->name . ($p->card ? ' · ' . $p->card : ''),
                    ['idempotency_key' => 'terminal:' . $p->reference]);
                if (! $payment->received_by && $p->requested_by) {
                    $payment->forceFill(['received_by' => $p->requested_by])->save();
                }
                $p->forceFill(['payment_id' => $payment->id])->save();
            }
            if (abs($paid - (float) $p->amount) > 0.009) {
                Audit::record('billing.terminal_amount_differs', "Card terminal {$p->terminal->name} took {$this->folios->money($paid)} for a request of {$this->folios->money((float) $p->amount)} ({$p->reference})", $p->folio, flagged: true);
            }

            return $p;
        });
    }

    private function close(TerminalPayment $p, string $status, ?string $message): TerminalPayment
    {
        $p->forceFill(['status' => $status, 'message' => $message ? mb_substr($message, 0, 255) : null])->save();

        return $p;
    }
}
