<?php

namespace App\Services\Billing\Terminals;

use App\Models\Billing\PaymentTerminal;
use App\Models\Billing\TerminalPayment;

/**
 * One card-terminal provider. Every answer comes from the provider's own
 * server: a webhook only prompts us to ask (see TerminalService::refresh).
 */
interface TerminalDriver
{
    /** Whether the hotel's account for this provider is filled in. */
    public function configured(): bool;

    /** Send the amount to the terminal. Answers [provider_ref, provider_id] (either may be null). Throws BookingException. */
    public function push(TerminalPayment $p): array;

    /**
     * What happened to it.
     * @return array{state: string, approval: ?string, card: ?string, amount: ?float, message: ?string, raw: array}
     *         state: pending | paid | failed | cancelled
     */
    public function status(TerminalPayment $p): array;

    /** Online right now? null when the provider can't say. */
    public function online(PaymentTerminal $t): ?bool;

    /** Stop it on the terminal, where the provider allows. */
    public function cancel(TerminalPayment $p): void;
}
