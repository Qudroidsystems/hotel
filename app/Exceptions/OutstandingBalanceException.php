<?php

namespace App\Exceptions;

/** Check-out was refused because the guest still owes money. */
class OutstandingBalanceException extends BookingException
{
    public function __construct(public readonly float $balance)
    {
        parent::__construct('The guest still owes ' . config('hotel.currency_symbol') . number_format($balance, 2)
            . '. Take payment first, or check out with an outstanding balance and give a reason.');
    }
}
