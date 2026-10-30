<?php

namespace App\Services\Network;

use App\Enums\ReservationStatus as S;
use App\Models\Reservation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Ties guest Wi-Fi codes to stays: given at check-in, stopped at check-out. */
class NetworkHooks
{
    public static function register(): void
    {
        Reservation::updated(function (Reservation $r) {
            if (! $r->wasChanged('status') || ! self::ready()) {
                return;
            }
            try {
                match ($r->status) {
                    S::CheckedIn  => app(NetworkService::class)->issueForReservation($r),
                    S::CheckedOut => app(NetworkService::class)->endForReservation($r, 'checked out'),
                    S::Cancelled, S::NoShow => app(NetworkService::class)->endForReservation($r, 'booking ' . $r->status->value),
                    default => null,
                };
            } catch (Throwable $e) {
                // Wi-Fi must never stop a check-in or check-out.
                Log::warning("Guest Wi-Fi for {$r->reference}: " . $e->getMessage());
            }
        });
    }

    private static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('network_passes');
    }
}
