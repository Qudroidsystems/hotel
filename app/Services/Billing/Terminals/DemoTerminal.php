<?php

namespace App\Services\Billing\Terminals;

use App\Models\Billing\PaymentTerminal;
use App\Models\Billing\TerminalPayment;

/** For trying it out: the "card" is paid 8 seconds after it reaches the terminal (an amount ending in .13 is declined). */
class DemoTerminal implements TerminalDriver
{
    public function configured(): bool
    {
        return true;
    }

    public function push(TerminalPayment $p): array
    {
        return ['DEMO-' . $p->reference, null];
    }

    public function status(TerminalPayment $p): array
    {
        $ready = $p->created_at && $p->created_at->lte(now()->subSeconds(8));
        $declined = str_ends_with(number_format((float) $p->amount, 2, '.', ''), '.13');

        return [
            'state'    => ! $ready ? 'pending' : ($declined ? 'failed' : 'paid'),
            'approval' => $ready && ! $declined ? 'DEMO' . str_pad((string) $p->id, 6, '0', STR_PAD_LEFT) : null,
            'card'     => $ready && ! $declined ? 'VISA •••• 4081' : null,
            'amount'   => $ready && ! $declined ? (float) $p->amount : null,
            'message'  => $declined && $ready ? 'Declined by the bank (demo)' : null,
            'raw'      => [],
        ];
    }

    public function online(PaymentTerminal $t): ?bool
    {
        return true;
    }

    public function cancel(TerminalPayment $p): void
    {
    }
}
