<?php

namespace App\Services\Events;

use App\Enums\PaymentMethod;
use App\Exceptions\BookingException;
use App\Models\Company;
use App\Models\Events\Event;
use App\Models\Events\EventItem;
use App\Models\Events\EventPackage;
use App\Models\Events\EventPayment;
use App\Models\Events\Hall;
use App\Models\FolioCharge;
use App\Models\NightAudit;
use App\Services\Companies\CompanyLedger;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Event bookings. A provisional or confirmed event holds its hall: no other event can take the hall for an
 * overlapping time. The bill is the hall hire, the package for each guest, and any extras, less a discount,
 * with the hotel's taxes; it is settled by payments or moved to a company's account.
 */
class EventService
{
    public function __construct(private CompanyLedger $ledger)
    {
    }

    /** data: title, event_type, status, hall_id, starts_at, ends_at, setup, guests_expected, company_id, organiser_*, package_id, hall_rate_type, hall_charge (custom), discount, deposit_due, requirements, internal_notes */
    public function save(array $data, ?Event $event = null): Event
    {
        $hall = Hall::findOrFail($data['hall_id']);
        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        if ($end->lte($start)) {
            throw new BookingException('The event must end after it starts.');
        }
        if (! $event && $start->lt(now()->subDay())) {
            throw new BookingException('That date has passed.');
        }
        $guests = (int) ($data['guests_expected'] ?? 0);
        $cap = $hall->capacityFor($data['setup'] ?? null);
        if ($cap && $guests > $cap) {
            throw new BookingException("{$hall->name} takes {$cap} guests in that layout. Choose another layout or hall.");
        }
        $status = $event?->status ?? ($data['status'] ?? 'enquiry');

        return DB::transaction(function () use ($data, $event, $hall, $start, $end, $guests, $status) {
            Hall::whereKey($hall->id)->lockForUpdate()->first();
            if (in_array($status, Event::HOLDING, true)) {
                $this->assertHallFree($hall, $start, $end, $event?->id);
            }
            $package = ! empty($data['package_id']) ? EventPackage::find($data['package_id']) : null;
            $rateType = $data['hall_rate_type'] ?? 'full_day';

            $event ??= new Event(['created_by' => auth()->id(), 'status' => $status]);
            $before = $event->exists ? $event->only(['hall_id', 'starts_at', 'ends_at', 'guests_expected', 'total']) : null;
            $event->fill([
                'title' => $data['title'], 'event_type' => $data['event_type'] ?? 'meeting', 'hall_id' => $hall->id,
                'starts_at' => $start, 'ends_at' => $end, 'setup' => $data['setup'] ?? null, 'guests_expected' => $guests,
                'company_id' => $data['company_id'] ?? null,
                'organiser_name' => $data['organiser_name'], 'organiser_phone' => $data['organiser_phone'] ?? null, 'organiser_email' => $data['organiser_email'] ?? null,
                'package_id' => $package?->id,
                // An agreed per-person price is kept unless the package changes.
                'package_price' => $package ? round((float) (($data['package_price'] ?? '') !== '' ? $data['package_price'] : $package->price_per_person), 2) : 0,
                'hall_rate_type' => $rateType,
                'hall_charge' => $rateType === 'custom' ? round((float) ($data['hall_charge'] ?? 0), 2) : $this->hallCharge($hall, $rateType, $start, $end),
                'discount' => round((float) ($data['discount'] ?? 0), 2),
                'deposit_due' => round((float) ($data['deposit_due'] ?? 0), 2),
                'requirements' => $data['requirements'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null,
            ]);
            $event->save();
            if (! $event->number) {
                $event->forceFill(['number' => 'EV-' . str_pad((string) $event->id, 6, '0', STR_PAD_LEFT)])->save();
            }
            $this->recalculate($event);

            $before
                ? Audit::record('events.changed', "Changed event {$event->number}: {$event->title}", $event, $before, $event->only(array_keys($before)))
                : Audit::record('events.added', "New event {$event->number} ({$event->status_label}): {$event->title}, {$hall->name}, {$event->when}", $event);

            return $event->fresh(['hall', 'items', 'payments']);
        });
    }

    /** Hall hire for the time booked, from the hall's rates. */
    public function hallCharge(Hall $hall, string $type, Carbon $start, Carbon $end): float
    {
        $hours = max(1, (int) ceil($start->diffInMinutes($end) / 60));
        $days = max(1, (int) $start->copy()->startOfDay()->diffInDays($end->copy()->subSecond()->startOfDay()) + 1);

        return round(match ($type) {
            'hourly'   => (float) $hall->hourly_rate * $hours,
            'half_day' => (float) $hall->half_day_rate * $days,
            default    => (float) $hall->full_day_rate * $days,
        }, 2);
    }

    /** Subtotal, tax and total from the hall, package, extras and discount. */
    public function recalculate(Event $event): void
    {
        $items = (float) $event->items()->sum('amount');
        $subtotal = max(0, round((float) $event->hall_charge + $event->package_total + $items - (float) $event->discount, 2));
        $tax = FolioCharge::computeTax($subtotal, true);
        $event->forceFill(['subtotal' => $subtotal, 'tax_amount' => $tax['inclusive'] ? array_sum(array_column($tax['breakdown'], 'amount')) : $tax['tax'], 'total' => $tax['total']])->save();
    }

    public function setStatus(Event $event, string $status, ?string $reason = null): void
    {
        $allowed = [
            'enquiry'   => ['tentative', 'confirmed', 'cancelled'],
            'tentative' => ['confirmed', 'enquiry', 'cancelled'],
            'confirmed' => ['completed', 'tentative', 'cancelled'],
            'completed' => ['confirmed'],
            'cancelled' => ['enquiry'],
        ];
        if (! in_array($status, $allowed[$event->status] ?? [], true)) {
            throw new BookingException("A {$event->status_label} event can't be marked " . strtolower(Event::STATUSES[$status][0] ?? $status) . '.');
        }
        if ($status === 'cancelled' && blank($reason)) {
            throw new BookingException('Say why the event is cancelled.');
        }
        DB::transaction(function () use ($event, $status, $reason) {
            if (in_array($status, Event::HOLDING, true)) {
                Hall::whereKey($event->hall_id)->lockForUpdate()->first();
                $this->assertHallFree($event->hall, $event->starts_at, $event->ends_at, $event->id);
            }
            $old = $event->status;
            $event->forceFill([
                'status' => $status,
                'confirmed_at' => $status === 'confirmed' ? ($event->confirmed_at ?? now()) : $event->confirmed_at,
                'cancelled_at' => $status === 'cancelled' ? now() : null,
                'cancel_reason' => $status === 'cancelled' ? $reason : null,
            ])->save();
            Audit::record('events.status', "Event {$event->number} {$event->title}: " . Event::STATUSES[$old][0] . ' → ' . Event::STATUSES[$status][0] . ($reason ? ". {$reason}" : ''), $event, ['status' => $old], ['status' => $status], $reason);
        });
    }

    public function addItem(Event $event, array $data): EventItem
    {
        $this->assertEditable($event);
        $qty = round((float) $data['quantity'], 2);
        $price = round((float) $data['unit_price'], 2);
        $item = $event->items()->create([
            'kind' => $data['kind'] ?? 'other', 'description' => $data['description'], 'quantity' => $qty, 'unit_price' => $price,
            'amount' => round($qty * $price, 2), 'created_by' => auth()->id(),
        ]);
        $this->recalculate($event);
        Audit::record('events.item_added', "Added to event {$event->number}: {$item->description} ({$this->ledger->money((float) $item->amount)})", $event);

        return $item;
    }

    public function removeItem(Event $event, EventItem $item): void
    {
        $this->assertEditable($event);
        Audit::record('events.item_removed', "Removed from event {$event->number}: {$item->description} ({$this->ledger->money((float) $item->amount)})", $event);
        $item->delete();
        $this->recalculate($event);
    }

    // ── Money ─────────────────────────────────────────────────────────

    public function receivePayment(Event $event, string $kind, PaymentMethod $method, float $amount, ?string $reference = null, ?int $bankAccountId = null): EventPayment
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new BookingException('Enter an amount greater than zero.');
        }
        if ($method === PaymentMethod::Company) {
            throw new BookingException('Use “Bill to company” to put the event on a company account.');
        }
        if ($kind === 'refund' && $amount > $event->paid() + 0.001) {
            throw new BookingException('You can’t refund more than has been paid (' . $this->ledger->money($event->paid()) . ').');
        }
        $p = $event->payments()->create([
            'kind' => $kind, 'method' => $method, 'amount' => $amount, 'reference' => $reference, 'bank_account_id' => $bankAccountId,
            'received_by' => auth()->id(), 'received_at' => now(),
        ]);
        $p->forceFill(['number' => 'EP-' . str_pad((string) $p->id, 6, '0', STR_PAD_LEFT)])->save();
        Audit::record('events.payment', ($kind === 'refund' ? 'Refund' : 'Payment') . " {$p->number} of {$this->ledger->money($amount)} by {$method->label()} for event {$event->number}", $event, [], ['amount' => $amount]);

        return $p;
    }

    public function voidPayment(EventPayment $payment, string $reason): void
    {
        if ($payment->isVoided()) {
            throw new BookingException('This payment is already voided.');
        }
        if (NightAudit::isClosed($payment->received_at) && ! auth()->user()?->can('Change closed days')) {
            throw new BookingException('This payment is dated ' . $payment->received_at->format('D j M Y') . ', a day the night audit has closed. A manager who can change closed days must void it.');
        }
        $payment->forceFill(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $reason])->save();
        Audit::record('events.payment_voided', "Voided {$payment->number} ({$this->ledger->money((float) $payment->amount)}) on event {$payment->event->number}", $payment->event, [], [], $reason, true);
    }

    /** Put what is left of the bill on the company's account. */
    public function billToCompany(Event $event, Company $company, float $amount)
    {
        if ($amount > $event->balance() + 0.001) {
            throw new BookingException('Only ' . $this->ledger->money(max(0, $event->balance())) . ' is left to pay on this event.');
        }

        return $this->ledger->invoice($company, $amount, "Event {$event->number}: {$event->title}, " . $event->starts_at->format('j M Y'), ['event_id' => $event->id]);
    }

    // ── Rules ─────────────────────────────────────────────────────────

    private function assertHallFree(Hall $hall, Carbon $start, Carbon $end, ?int $except = null): void
    {
        $clash = Event::holding()->where('hall_id', $hall->id)->overlapping($start, $end)
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->first();
        if ($clash) {
            throw new BookingException("{$hall->name} is taken then by {$clash->title} ({$clash->status_label}, {$clash->when}).");
        }
    }

    private function assertEditable(Event $event): void
    {
        if (in_array($event->status, ['completed', 'cancelled'], true)) {
            throw new BookingException("This event is {$event->status_label}; its bill can't change.");
        }
    }
}
