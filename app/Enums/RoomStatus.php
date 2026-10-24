<?php

namespace App\Enums;

/**
 * Room lifecycle statuses (spec §3.1). "Occupied" and "Reserved" will be set
 * automatically by the reservations module; the rest are set by front desk,
 * housekeeping and maintenance.
 */
enum RoomStatus: string
{
    case Available     = 'available';
    case Reserved      = 'reserved';
    case Occupied      = 'occupied';
    case Dirty         = 'dirty';
    case Cleaning      = 'cleaning';
    case Maintenance   = 'maintenance';
    case OutOfService  = 'out_of_service';

    public function label(): string
    {
        return match ($this) {
            self::Available    => 'Available',
            self::Reserved     => 'Reserved',
            self::Occupied     => 'Occupied',
            self::Dirty        => 'Needs Cleaning',
            self::Cleaning     => 'Being Cleaned',
            self::Maintenance  => 'Under Maintenance',
            self::OutOfService => 'Out of Service',
        };
    }

    /** Hex colour used on the room board and status pills. */
    public function color(): string
    {
        return match ($this) {
            self::Available    => '#16a34a',
            self::Reserved     => '#2563eb',
            self::Occupied     => '#7c3aed',
            self::Dirty        => '#d97706',
            self::Cleaning     => '#0891b2',
            self::Maintenance  => '#dc2626',
            self::OutOfService => '#475569',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Available    => 'ri-checkbox-circle-line',
            self::Reserved     => 'ri-calendar-check-line',
            self::Occupied     => 'ri-user-3-line',
            self::Dirty        => 'ri-brush-line',
            self::Cleaning     => 'ri-hand-sanitizer-line',
            self::Maintenance  => 'ri-tools-line',
            self::OutOfService => 'ri-forbid-line',
        };
    }

    /** Statuses that block the room from being booked. */
    public function isSellable(): bool
    {
        return $this === self::Available;
    }

    /** Statuses that need a written reason when set by hand. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Maintenance, self::OutOfService], true);
    }

    /** [value => label] for selects. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
