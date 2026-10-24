<?php

namespace App\Services\Billing;

use App\Enums\ChargeType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Folio;
use App\Models\FolioCharge;
use App\Models\Payment;
use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Guest bills. Lines are never edited or deleted: mistakes are voided with a
 * reason, and every money action is recorded on the reservation's activity log.
 */
class FolioService
{
    /** The reservation's folio, created on first use. */
    public function forReservation(Reservation $reservation): Folio
    {
        $folio = $reservation->folio()->first();
        if ($folio) {
            return $folio;
        }

        $folio = $reservation->folio()->create(['guest_id' => $reservation->guest_id, 'status' => 'open']);
        $folio->forceFill(['number' => 'F-' . str_pad((string) $folio->id, 6, '0', STR_PAD_LEFT)])->save();

        return $folio;
    }

    // ── Charges ───────────────────────────────────────────────────────

    public function postCharge(Folio $folio, ChargeType $type, string $description, float $quantity, float $unitPrice, $serviceDate = null, array $extra = []): FolioCharge
    {
        if ($quantity <= 0) {
            throw new BookingException('Quantity must be more than zero.');
        }
        // The same form arriving twice (slow network, double click) is recorded once.
        if (! empty($extra['idempotency_key']) && ($existing = FolioCharge::where('idempotency_key', $extra['idempotency_key'])->first())) {
            return $existing;
        }

        // Discounts are always money off.
        $unitPrice = $type === ChargeType::Discount ? -abs($unitPrice) : $unitPrice;
        $amount = Money::round($quantity * $unitPrice);
        $tax = FolioCharge::computeTax($amount, $type->isTaxable());

        try {
            $charge = $folio->charges()->create($extra + [
                'type'          => $type,
                'description'   => $description,
                'quantity'      => $quantity,
                'unit_price'    => $unitPrice,
                'amount'        => $amount,
                'tax_amount'    => $tax['tax'],
                'tax_breakdown' => $tax['breakdown'],
                'tax_inclusive' => $tax['inclusive'],
                'total'         => $tax['total'],
                'service_date'  => $serviceDate ?? today(),
                'posted_by'     => auth()->id(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // The twin of this request got in first.
            if (! empty($extra['idempotency_key']) && ($existing = FolioCharge::where('idempotency_key', $extra['idempotency_key'])->first())) {
                return $existing;
            }
            throw $e;
        }

        if ($type !== ChargeType::Room) {
            $folio->reservation?->log('charge', sprintf('%s posted: %s — %s.', $type->label(), $description, $this->money($tax['total'])));
        }

        // Posting to a settled, closed bill (e.g. minibar found after check-out) reopens it.
        $this->syncStatus($folio);

        return $charge;
    }

    public function voidCharge(FolioCharge $charge, string $reason): void
    {
        if ($charge->isVoided()) {
            throw new BookingException('This charge is already voided.');
        }
        if ($charge->source_type === Reservation::class) {
            throw new BookingException('This line follows the booking discount. Change the discount on the reservation instead.');
        }

        $charge->forceFill(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $reason])->save();
        $charge->folio->reservation?->log('charge_voided', sprintf('Voided charge “%s” (%s). Reason: %s', $charge->description, $this->money((float) $charge->total), $reason));
        $this->syncStatus($charge->folio);
    }

    /**
     * Post each room night that has been used (nights before $upTo, default today)
     * and keep the booking discount in step. Safe to call any number of times.
     */
    public function postRoomNights(Reservation $reservation, $upTo = null): int
    {
        if (! in_array($reservation->status, [ReservationStatus::CheckedIn, ReservationStatus::CheckedOut], true)) {
            return 0;
        }

        $folio = $this->forReservation($reservation);
        $upTo = Carbon::parse($upTo ?? today())->toDateString();
        $posted = 0;

        DB::transaction(function () use ($reservation, $folio, $upTo, &$posted) {
            $already = $folio->charges()->where('type', ChargeType::Room->value)->whereNotNull('reservation_room_id')
                ->get(['reservation_room_id', 'service_date'])
                ->map(fn ($c) => $c->reservation_room_id . '|' . $c->service_date->toDateString())->flip();

            foreach ($reservation->rooms()->with(['room', 'roomType'])->get() as $line) {
                foreach ($line->nightly_rates ?? [] as $night => $rate) {
                    if ($night >= $upTo || isset($already[$line->id . '|' . $night])) {
                        continue;
                    }
                    $this->postCharge($folio, ChargeType::Room, sprintf('%s%s — night of %s',
                        $line->room ? 'Room ' . $line->room->number . ', ' : '', $line->roomType->name, Carbon::parse($night)->format('D j M')),
                        1, (float) $rate, $night, ['reservation_room_id' => $line->id]);
                    $posted++;
                }
            }

            $this->syncBookingDiscount($reservation, $folio);
        });

        return $posted;
    }

    /** Post room nights for everyone in house (the "night audit"). */
    public function nightAudit(): int
    {
        return Reservation::inHouse()->get()->sum(fn (Reservation $r) => $this->postRoomNights($r));
    }

    /** The reservation's discount appears on the bill as one discount line (adjusted if it changes). */
    private function syncBookingDiscount(Reservation $reservation, Folio $folio): void
    {
        // Lines this method posted before (discounts and their corrections).
        $postedDiscount = -1 * (float) $folio->charges()->whereNull('voided_at')
            ->whereIn('type', [ChargeType::Discount->value, ChargeType::Adjustment->value])
            ->where('source_type', Reservation::class)->where('source_id', $reservation->id)->sum('amount');
        $diff = round((float) $reservation->discount_amount - $postedDiscount, 2);

        if (abs($diff) < 0.01 || ! $folio->charges()->where('type', ChargeType::Room->value)->exists()) {
            return;
        }

        $source = ['source_type' => Reservation::class, 'source_id' => $reservation->id];
        $diff > 0
            ? $this->postCharge($folio, ChargeType::Discount, 'Booking discount' . ($reservation->discount_reason ? ' — ' . $reservation->discount_reason : ''), 1, $diff, null, $source)
            : $this->postCharge($folio, ChargeType::Adjustment, 'Booking discount reduced', 1, abs($diff), null, $source);
    }

    // ── Payments ──────────────────────────────────────────────────────

    /** $extra: bank_account_id, idempotency_key, received_at. */
    public function receivePayment(Folio $folio, PaymentKind $kind, PaymentMethod $method, float $amount, ?string $reference = null, ?string $notes = null, array $extra = []): Payment
    {
        // The same form arriving twice (slow network, double click) is recorded once.
        if (! empty($extra['idempotency_key']) && ($existing = Payment::where('idempotency_key', $extra['idempotency_key'])->first())) {
            return $existing;
        }
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new BookingException('Enter an amount greater than zero.');
        }
        if ($kind === PaymentKind::Refund && $amount > $folio->paidTotal() + 0.001) {
            throw new BookingException('You can’t refund more than the guest has paid (' . $this->money($folio->paidTotal()) . ').');
        }

        try {
            return $this->createPayment($folio, $kind, $method, $amount, $reference, $notes, $extra);
        } catch (UniqueConstraintViolationException $e) {
            // The twin of this request got in first.
            if (! empty($extra['idempotency_key']) && ($existing = Payment::where('idempotency_key', $extra['idempotency_key'])->first())) {
                return $existing;
            }
            throw $e;
        }
    }

    private function createPayment(Folio $folio, PaymentKind $kind, PaymentMethod $method, float $amount, ?string $reference, ?string $notes, array $extra): Payment
    {
        return DB::transaction(function () use ($folio, $kind, $method, $amount, $reference, $notes, $extra) {
            $payment = $folio->payments()->create([
                'idempotency_key' => $extra['idempotency_key'] ?? null,
                'kind'            => $kind,
                'method'          => $method,
                'amount'          => $amount,
                'reference'       => $reference,
                'bank_account_id' => $extra['bank_account_id'] ?? null,
                'notes'           => $notes,
                'received_by'     => auth()->id(),
                'received_at'     => $extra['received_at'] ?? now(),
            ]);
            $payment->forceFill(['receipt_number' => 'R-' . str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)])->save();

            $folio->reservation?->log($kind === PaymentKind::Refund ? 'refund' : 'payment', sprintf('%s of %s by %s%s%s. Receipt %s.',
                $kind->label(), $this->money($amount), $method->label(), $reference ? " (ref {$reference})" : '',
                $payment->bankAccount ? ' into ' . $payment->bankAccount->short_label : '', $payment->receipt_number));

            $this->syncStatus($folio);

            return $payment;
        });
    }

    public function voidPayment(Payment $payment, string $reason): void
    {
        if ($payment->isVoided()) {
            throw new BookingException('This payment is already voided.');
        }

        DB::transaction(function () use ($payment, $reason) {
            $payment->forceFill(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $reason])->save();
            $payment->folio->reservation?->log('payment_voided', sprintf('Voided %s %s (%s). Reason: %s',
                strtolower($payment->kind->label()), $payment->receipt_number, $this->money((float) $payment->amount), $reason));
            $this->syncStatus($payment->folio);
        });
    }

    // ── Status ────────────────────────────────────────────────────────

    /** A folio closes when the stay is over and nothing is owed either way; it reopens if that changes. */
    public function syncStatus(Folio $folio): void
    {
        $folio->refresh();
        $stayOver = ! $folio->reservation || ! $folio->reservation->status->isOpen();
        $settled = abs($folio->balance()) < 0.01;

        if ($stayOver && $settled && $folio->isOpen()) {
            $folio->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id()])->save();
        } elseif ((! $stayOver || ! $settled) && ! $folio->isOpen()) {
            $folio->forceFill(['status' => 'open', 'closed_at' => null, 'closed_by' => null])->save();
        }
    }

    public function money(float $amount): string
    {
        return config('hotel.currency_symbol') . number_format($amount, 2);
    }
}
