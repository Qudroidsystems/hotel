<?php

namespace App\Services\Companies;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingException;
use App\Models\Company;
use App\Models\CompanyLedgerEntry;
use App\Models\Folio;
use App\Models\Payment;
use App\Services\Billing\FolioService;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Company accounts (city ledger). A guest's bill moves to the company as a folio "payment" by Company
 * account (so the guest's bill settles and the money trail stays on the folio), matched by an invoice on the
 * company's ledger. Companies pay against statements; payments and credit notes are applied to the oldest
 * invoices first for the ageing. Nothing is deleted: a mistake is voided with a reason.
 */
class CompanyLedger
{
    public const BUCKETS = ['current' => 'Not yet due', 'd30' => '1–30 days late', 'd60' => '31–60 days late', 'd90' => '61–90 days late', 'older' => 'Over 90 days late'];

    public function __construct(private FolioService $folios)
    {
    }

    public function create(array $data): Company
    {
        $company = Company::create($data + ['created_by' => auth()->id()]);
        $company->forceFill(['account_number' => 'CA-' . str_pad((string) $company->id, 4, '0', STR_PAD_LEFT)])->save();
        Audit::record('companies.added', "Opened company account {$company->account_number} for {$company->name}"
            . ((float) $company->credit_limit > 0 ? ' (credit limit ' . $this->money((float) $company->credit_limit) . ')' : ''), $company);

        return $company;
    }

    // ── Moving bills to the company ───────────────────────────────────

    /** Move all (or part) of a guest's bill to the company's account. */
    public function transferFolio(Folio $folio, Company $company, float $amount, ?string $note = null): CompanyLedgerEntry
    {
        $amount = round($amount, 2);
        $owed = round($folio->balance(), 2);
        if (! $company->is_active) {
            throw new BookingException("{$company->name}'s account is closed.");
        }
        if ($amount <= 0) {
            throw new BookingException('Enter an amount greater than zero.');
        }
        if ($amount > $owed + 0.001) {
            throw new BookingException('The guest only owes ' . $this->money(max(0, $owed)) . '.');
        }
        $this->assertCredit($company, $amount);

        return DB::transaction(function () use ($folio, $company, $amount, $note) {
            Company::whereKey($company->id)->lockForUpdate()->first();
            $this->assertCredit($company, $amount);   // again, inside the lock

            $r = $folio->reservation;
            $payment = $this->folios->receivePayment($folio, PaymentKind::Payment, PaymentMethod::Company, $amount, $company->account_number,
                'Billed to ' . $company->name . ($note ? " — {$note}" : ''));
            $entry = $this->entry($company, 'invoice', $amount, $r
                ? sprintf('Stay %s: %s, %s – %s', $r->reference, $r->guest?->full_name, $r->check_in_date->format('j M'), $r->check_out_date->format('j M Y'))
                : 'Bill ' . $folio->number, [
                    'reservation_id' => $r?->id, 'folio_id' => $folio->id, 'payment_id' => $payment->id,
                    'due_date' => today()->addDays((int) $company->payment_terms_days), 'reference' => $note,
                ]);
            $r?->log('company_billed', "{$this->money($amount)} moved to {$company->name}'s account ({$entry->number}).");
            Audit::record('companies.billed', "Moved {$this->money($amount)} of bill {$folio->number} to {$company->name} ({$entry->number})", $company, [], ['amount' => $amount, 'invoice' => $entry->number]);

            return $entry;
        });
    }

    /** Bill the company directly for something not on a guest bill (an event, say). */
    public function invoice(Company $company, float $amount, string $description, array $extra = []): CompanyLedgerEntry
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new BookingException('Enter an amount greater than zero.');
        }
        $this->assertCredit($company, $amount);

        return DB::transaction(function () use ($company, $amount, $description, $extra) {
            $entry = $this->entry($company, 'invoice', $amount, $description, $extra + ['due_date' => today()->addDays((int) $company->payment_terms_days)]);
            Audit::record('companies.billed', "Invoiced {$company->name} {$this->money($amount)}: {$description} ({$entry->number})", $company, [], ['amount' => $amount, 'invoice' => $entry->number]);

            return $entry;
        });
    }

    // ── Payments, credits, adjustments ────────────────────────────────

    public function receivePayment(Company $company, float $amount, PaymentMethod $method, ?string $reference, ?string $date = null, ?string $note = null): CompanyLedgerEntry
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new BookingException('Enter an amount greater than zero.');
        }
        if ($method === PaymentMethod::Company) {
            throw new BookingException('Choose how the company paid.');
        }
        $entry = $this->entry($company, 'payment', -$amount, 'Payment by ' . strtolower($method->label()) . ($note ? " — {$note}" : ''), [
            'method' => $method->value, 'reference' => $reference, 'entry_date' => $date ?: today()->toDateString(),
        ]);
        Audit::record('companies.payment', "Received {$this->money($amount)} from {$company->name} by {$method->label()}" . ($reference ? " (ref {$reference})" : '') . " — {$entry->number}", $company, [], ['amount' => $amount]);

        return $entry;
    }

    /** A credit note (money off what the company owes) or an adjustment either way. */
    public function adjust(Company $company, string $type, float $amount, string $reason): CompanyLedgerEntry
    {
        $amount = round($amount, 2);
        if (abs($amount) < 0.01) {
            throw new BookingException('Enter an amount.');
        }
        $signed = $type === 'credit' ? -abs($amount) : $amount;
        $entry = $this->entry($company, $type === 'credit' ? 'credit' : 'adjustment', $signed, $reason);
        Audit::record('companies.adjusted', ($type === 'credit' ? 'Credit note' : 'Adjustment') . " of {$this->money($signed)} on {$company->name}'s account: {$reason}", $company, [], ['amount' => $signed], $reason, abs($amount) >= (float) config('hotel.audit.flag_amount', 50000));

        return $entry;
    }

    /** Undo an entry. A moved bill goes back to the guest (the folio payment is voided too). */
    public function void(CompanyLedgerEntry $entry, string $reason): void
    {
        if ($entry->isVoided()) {
            throw new BookingException('This entry is already voided.');
        }
        DB::transaction(function () use ($entry, $reason) {
            $entry->forceFill(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => $reason])->save();
            if ($entry->payment && ! $entry->payment->isVoided()) {
                $this->folios->voidPayment($entry->payment, "Company invoice {$entry->number} voided: {$reason}");
            }
            Audit::record('companies.voided', "Voided {$entry->type_label} {$entry->number} ({$this->money((float) $entry->amount)}) on {$entry->company->name}'s account", $entry->company, [], [], $reason, true);
        });
    }

    /** Called when a folio payment by company account is voided on the bill itself. */
    public function folioPaymentVoided(Payment $payment): void
    {
        $entry = CompanyLedgerEntry::where('payment_id', $payment->id)->whereNull('voided_at')->first();
        $entry?->forceFill(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => 'The payment on bill ' . $payment->folio?->number . ' was voided: ' . $payment->void_reason])->save();
    }

    // ── Reading the account ───────────────────────────────────────────

    /**
     * Open invoices with what is left on each after payments and credits (oldest first), and the ageing.
     *
     * @return array{open: Collection, buckets: array<string, float>, overdue: float, unapplied: float}
     */
    public function ageing(Company $company, ?Carbon $asOf = null): array
    {
        $asOf ??= today();
        $entries = $company->entries()->live()->where('entry_date', '<=', $asOf->toDateString() . ' 23:59:59')->orderBy('entry_date')->orderBy('id')->get();
        $credit = -1 * (float) $entries->where('amount', '<', 0)->sum('amount');
        $open = collect();
        foreach ($entries->where('amount', '>', 0) as $inv) {
            $take = min($credit, (float) $inv->amount);
            $credit -= $take;
            $left = round((float) $inv->amount - $take, 2);
            if ($left >= 0.01) {
                $due = $inv->due_date ?? $inv->entry_date;
                $late = $due->lt($asOf) ? (int) $due->diffInDays($asOf) : 0;
                $open->push((object) ['entry' => $inv, 'left' => $left, 'late' => $late, 'bucket' => match (true) {
                    $late <= 0 => 'current', $late <= 30 => 'd30', $late <= 60 => 'd60', $late <= 90 => 'd90', default => 'older',
                }]);
            }
        }
        $buckets = collect(self::BUCKETS)->map(fn ($l, $k) => round((float) $open->where('bucket', $k)->sum('left'), 2))->all();

        return ['open' => $open, 'buckets' => $buckets, 'overdue' => round(array_sum($buckets) - $buckets['current'], 2), 'unapplied' => round($credit, 2)];
    }

    /** Statement for a period: opening balance, the entries, closing balance. */
    public function statement(Company $company, Carbon $from, Carbon $to): array
    {
        $opening = (float) $company->entries()->live()->where('entry_date', '<', $from->toDateString())->sum('amount');
        $entries = $company->entries()->live()->with('reservation.guest')
            ->where('entry_date', '>=', $from->toDateString())->where('entry_date', '<=', $to->toDateString() . ' 23:59:59')
            ->orderBy('entry_date')->orderBy('id')->get();
        $running = $opening;
        foreach ($entries as $e) {
            $running += (float) $e->amount;
            $e->running = round($running, 2);
        }

        return ['opening' => round($opening, 2), 'entries' => $entries, 'closing' => round($running, 2)];
    }

    // ── Internals ─────────────────────────────────────────────────────

    private function assertCredit(Company $company, float $amount): void
    {
        $limit = (float) $company->credit_limit;
        if ($limit > 0 && $company->balance() + $amount > $limit + 0.001) {
            throw new BookingException("{$company->name} would go over its credit limit of {$this->money($limit)} (it owes {$this->money($company->balance())} now). Ask a manager to raise the limit, or take payment from the guest.");
        }
    }

    private function entry(Company $company, string $type, float $amount, string $description, array $extra = []): CompanyLedgerEntry
    {
        $entry = $company->entries()->create($extra + [
            'type' => $type, 'amount' => $amount, 'description' => mb_substr($description, 0, 255),
            'entry_date' => $extra['entry_date'] ?? today()->toDateString(), 'created_by' => auth()->id(),
        ]);
        $prefix = match ($type) { 'invoice' => 'CI', 'payment' => 'CP', 'credit' => 'CN', default => 'CA' };
        $entry->forceFill(['number' => $prefix . '-' . str_pad((string) $entry->id, 6, '0', STR_PAD_LEFT)])->save();

        return $entry;
    }

    public function money(float $amount): string
    {
        return ($amount < 0 ? '−' : '') . config('hotel.currency_symbol') . number_format(abs($amount), 2);
    }
}
