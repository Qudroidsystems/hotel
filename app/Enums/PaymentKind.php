<?php

namespace App\Enums;

enum PaymentKind: string
{
    case Payment = 'payment';
    case Deposit = 'deposit';   // received before check-in
    case Refund  = 'refund';    // money given back to the guest

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::Deposit => 'Deposit',
            self::Refund  => 'Refund',
        };
    }

    /** +1 for money in, −1 for money out. */
    public function sign(): int
    {
        return $this === self::Refund ? -1 : 1;
    }
}
