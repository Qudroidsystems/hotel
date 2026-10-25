<?php

namespace App\Services\Messaging;

use App\Enums\ReservationStatus;
use App\Models\Booking\GuestRequest;
use App\Models\Reservation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

use function Illuminate\Support\defer;

/**
 * When the standard guest texts go out. Hooked to the records, so it works
 * whichever screen made the change; sent after the page has answered, so a
 * slow text never holds anyone up.
 */
class MessagingHooks
{
    public static function register(): void
    {
        Reservation::saved(function (Reservation $r) {
            if (! self::ready()) {
                return;
            }
            $became = fn (ReservationStatus $s) => $r->status === $s && ($r->wasRecentlyCreated || $r->wasChanged('status'));

            if ($became(ReservationStatus::Confirmed)) {
                self::later(fn ($m) => $m->aboutBooking($r->fresh(), 'booking_confirmed'));
            } elseif ($r->status === ReservationStatus::Pending && $r->hold_expires_at && $r->wasChanged('hold_expires_at')) {
                self::later(fn ($m) => $m->aboutBooking($r->fresh(), 'payment_link'));
            } elseif ($became(ReservationStatus::CheckedOut)) {
                self::later(fn ($m) => $m->aboutBooking($r->fresh(), 'thank_you'));
            }
        });

        GuestRequest::saved(function (GuestRequest $g) {
            if (self::ready() && ! $g->wasRecentlyCreated && $g->wasChanged('status') && in_array($g->status, ['in_progress', 'done'], true)) {
                self::later(fn ($m) => $m->aboutRequest($g->fresh('reservation'), $g->staff_note));
            }
        });
    }

    private static function later(callable $send): void
    {
        $messenger = app(GuestMessenger::class);
        if (! $messenger->enabled()) {
            return;
        }
        defer(function () use ($send, $messenger) {
            try {
                $send($messenger);
            } catch (\Throwable $e) {
                Log::warning('Guest message not sent: ' . $e->getMessage());
            }
        });
    }

    private static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('guest_messages');
    }
}
