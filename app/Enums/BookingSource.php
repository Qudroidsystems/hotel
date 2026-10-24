<?php

namespace App\Enums;

/** Where a booking came from (spec §4: booking source tracking). */
enum BookingSource: string
{
    case WalkIn    = 'walk_in';
    case Phone     = 'phone';
    case Email     = 'email';
    case Website   = 'website';
    case Corporate = 'corporate';
    case Agent     = 'agent';
    case Referral  = 'referral';
    case Ota       = 'ota';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn    => 'Walk-in',
            self::Phone     => 'Telephone',
            self::Email     => 'Email',
            self::Website   => 'Hotel website',
            self::Corporate => 'Corporate',
            self::Agent     => 'Travel agent',
            self::Referral  => 'Referral',
            self::Ota       => 'Online travel agency',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
