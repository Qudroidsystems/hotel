<?php

namespace App\Enums;

enum ChargeType: string
{
    case Room       = 'room';
    case Food       = 'food';
    case Beverage   = 'beverage';
    case Minibar    = 'minibar';
    case Laundry    = 'laundry';
    case Service    = 'service';     // spa, airport pickup, extra bed…
    case Damage     = 'damage';
    case Fee        = 'fee';         // cancellation / late checkout fees
    case Other      = 'other';
    case Discount   = 'discount';    // negative amount
    case Adjustment = 'adjustment';  // corrections; not taxed

    public function label(): string
    {
        return match ($this) {
            self::Room       => 'Room',
            self::Food       => 'Food',
            self::Beverage   => 'Drinks / bar',
            self::Minibar    => 'Minibar',
            self::Laundry    => 'Laundry',
            self::Service    => 'Service',
            self::Damage     => 'Damage / loss',
            self::Fee        => 'Fee',
            self::Other      => 'Other',
            self::Discount   => 'Discount',
            self::Adjustment => 'Adjustment',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Room       => 'ri-hotel-bed-line',
            self::Food       => 'ri-restaurant-line',
            self::Beverage   => 'ri-goblet-line',
            self::Minibar    => 'ri-fridge-line',
            self::Laundry    => 'ri-t-shirt-line',
            self::Service    => 'ri-service-line',
            self::Damage     => 'ri-hammer-line',
            self::Fee        => 'ri-file-list-3-line',
            self::Other      => 'ri-more-line',
            self::Discount   => 'ri-price-tag-3-line',
            self::Adjustment => 'ri-scales-3-line',
        };
    }

    public function isTaxable(): bool
    {
        return $this !== self::Adjustment;
    }

    /** Types staff may post by hand from the folio screen. */
    public static function manual(): array
    {
        return [self::Food, self::Beverage, self::Minibar, self::Laundry, self::Service, self::Damage, self::Fee, self::Other, self::Discount, self::Adjustment];
    }
}
