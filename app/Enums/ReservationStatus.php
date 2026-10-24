<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Pending    = 'pending';     // held, awaiting confirmation (e.g. deposit)
    case Confirmed  = 'confirmed';
    case CheckedIn  = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled  = 'cancelled';
    case NoShow     = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Pending',
            self::Confirmed  => 'Confirmed',
            self::CheckedIn  => 'Checked In',
            self::CheckedOut => 'Checked Out',
            self::Cancelled  => 'Cancelled',
            self::NoShow     => 'No-show',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending    => '#d97706',
            self::Confirmed  => '#2563eb',
            self::CheckedIn  => '#7c3aed',
            self::CheckedOut => '#16a34a',
            self::Cancelled  => '#64748b',
            self::NoShow     => '#dc2626',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending    => 'ri-time-line',
            self::Confirmed  => 'ri-calendar-check-line',
            self::CheckedIn  => 'ri-login-box-line',
            self::CheckedOut => 'ri-logout-box-r-line',
            self::Cancelled  => 'ri-close-circle-line',
            self::NoShow     => 'ri-user-unfollow-line',
        };
    }

    /** Statuses that hold rooms (count against availability). */
    public static function holding(): array
    {
        return [self::Pending->value, self::Confirmed->value, self::CheckedIn->value];
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::holding(), true);
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
