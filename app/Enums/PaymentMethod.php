<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash     = 'cash';
    case Transfer = 'transfer';
    case Card     = 'card';      // POS terminal
    case Online   = 'online';    // payment gateway (later)
    case Cheque   = 'cheque';
    case Other    = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash     => 'Cash',
            self::Transfer => 'Bank transfer',
            self::Card     => 'Card (POS)',
            self::Online   => 'Online',
            self::Cheque   => 'Cheque',
            self::Other    => 'Other',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Cash     => 'ri-money-dollar-box-line',
            self::Transfer => 'ri-bank-line',
            self::Card     => 'ri-bank-card-line',
            self::Online   => 'ri-global-line',
            self::Cheque   => 'ri-file-paper-2-line',
            self::Other    => 'ri-wallet-3-line',
        };
    }

    /** Methods where a reference (transfer ref, POS approval code) is expected. */
    public function needsReference(): bool
    {
        return in_array($this, [self::Transfer, self::Card, self::Online, self::Cheque], true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
