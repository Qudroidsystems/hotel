<?php

namespace App\Services\Finance;

use App\Exceptions\BookingException;
use App\Models\Finance\Expense;
use App\Models\User;
use App\Services\Billing\CashShiftService;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Operating expenses (spec §9, §14). Expenses up to the approval limit are
 * approved when recorded; larger ones wait for someone else with "Approve
 * expenses" — nobody approves their own. Paying in cash "from my till" books
 * a paid-out on the payer's open cash shift so the cash-up still balances.
 */
class ExpenseService
{
    public const DISK = 'local';
    public const DIR = 'expenses';

    public function __construct(private CashShiftService $shifts)
    {
    }

    /** @param UploadedFile[] $files */
    public function record(array $data, array $files, User $by): Expense
    {
        $amount = Money::round((float) $data['amount']);
        $tax = Money::round((float) ($data['tax_amount'] ?? 0));
        if ($amount <= 0) {
            throw new BookingException('Enter an amount greater than zero.');
        }
        if ($tax < 0 || $tax >= $amount) {
            throw new BookingException('The VAT included must be less than the amount.');
        }

        return DB::transaction(function () use ($data, $files, $by, $amount, $tax) {
            $expense = Expense::create([
                'category_id'  => $data['category_id'],
                'department'   => $data['department'] ?? null,
                'description'  => $data['description'],
                'supplier_id'  => $data['supplier_id'] ?? null,
                'payee'        => empty($data['supplier_id']) ? ($data['payee'] ?? null) : null,
                'amount'       => $amount,
                'tax_amount'   => $tax,
                'expense_date' => $data['expense_date'],
                'invoice_ref'  => $data['invoice_ref'] ?? null,
                'notes'        => $data['notes'] ?? null,
                'recorded_by'  => $by->id,
            ]);
            $auto = ! Expense::needsApproval($amount);
            $expense->forceFill([
                'number'      => 'EXP-' . str_pad((string) $expense->id, 6, '0', STR_PAD_LEFT),
                'attachments' => $this->store($files) ?: null,
                'status'      => $auto ? 'approved' : 'pending',
                'approved_at' => $auto ? now() : null,
            ])->save();

            Audit::record('finance.expense_recorded', "Recorded {$expense->number} " . $this->money($amount) . " ({$expense->category->name}): {$expense->description}"
                . ($auto ? ' — within the approval limit' : ' — needs approval'), $expense, new: ['amount' => $amount, 'status' => $expense->status], by: $by);

            // "Paid already" on the form: settle straight away when it's approved.
            if ($auto && ! empty($data['pay_now'])) {
                $this->pay($expense, $data, $by);
            }

            return $expense->fresh();
        });
    }

    public function approve(Expense $expense, User $by): void
    {
        $this->expect($expense, ['pending']);
        if ($expense->recorded_by === $by->id) {
            throw new BookingException('You recorded this expense, so someone else must approve it.');
        }
        $expense->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $by->id])->save();
        Audit::record('finance.expense_approved', "Approved {$expense->number} " . $this->money($expense->amount) . ": {$expense->description}", $expense, by: $by);
    }

    public function reject(Expense $expense, User $by, string $reason): void
    {
        $this->expect($expense, ['pending']);
        $expense->forceFill(['status' => 'rejected', 'approved_by' => $by->id, 'approved_at' => now(), 'rejection_reason' => $reason])->save();
        Audit::record('finance.expense_rejected', "Rejected {$expense->number} " . $this->money($expense->amount), $expense, reason: $reason, by: $by);
    }

    /** data: method, bank_account_id?, payment_ref?, from_till? */
    public function pay(Expense $expense, array $data, User $by): void
    {
        $this->expect($expense, ['approved']);
        $method = $data['method'] ?? null;
        if (! array_key_exists((string) $method, Expense::METHODS)) {
            throw new BookingException('Choose how it was paid.');
        }

        DB::transaction(function () use ($expense, $data, $by, $method) {
            $shiftId = null;
            if ($method === 'cash' && ! empty($data['from_till'])) {
                $shift = $this->shifts->current($by);
                if (! $shift) {
                    throw new BookingException('You have no open cash shift to take this from. Untick "from my till" if it was paid from the safe or petty cash box.');
                }
                $this->shifts->addMovement($shift, 'paid_out', (float) $expense->amount, "Expense {$expense->number}: {$expense->description}");
                $shiftId = $shift->id;
            }
            $expense->forceFill([
                'status'          => 'paid',
                'paid_at'         => now(),
                'paid_by'         => $by->id,
                'method'          => $method,
                'bank_account_id' => in_array($method, ['transfer', 'cheque'], true) ? ($data['bank_account_id'] ?? null) : null,
                'cash_shift_id'   => $shiftId,
                'payment_ref'     => $data['payment_ref'] ?? null,
            ])->save();
            Audit::record('finance.expense_paid', "Paid {$expense->number} " . $this->money($expense->amount) . ' by ' . Expense::METHODS[$method]
                . ($shiftId ? ' from the till' : '') . ": {$expense->description}", $expense, new: ['method' => $method], by: $by,
                flagged: (float) $expense->amount >= (float) config('hotel.audit.flag_amount', 50000));
        });
    }

    /** Cancel an expense recorded in error. A till payout is not reversed automatically — pay the cash back in if it was returned. */
    public function void(Expense $expense, User $by, string $reason): void
    {
        $this->expect($expense, ['pending', 'approved', 'paid']);
        $was = $expense->status;
        $expense->forceFill(['status' => 'void', 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason])->save();
        Audit::record('finance.expense_voided', "Voided {$expense->number} " . $this->money($expense->amount) . " (was {$was})", $expense,
            old: ['status' => $was], new: ['status' => 'void'], reason: $reason, flagged: $was === 'paid', by: $by);
    }

    /** Edit a pending expense (its recorder or an approver). Amount changes re-check the limit. */
    public function update(Expense $expense, array $data, User $by): void
    {
        $this->expect($expense, ['pending', 'approved']);
        $amount = Money::round((float) $data['amount']);
        $tax = Money::round((float) ($data['tax_amount'] ?? 0));
        if ($amount <= 0 || $tax < 0 || $tax >= $amount) {
            throw new BookingException('Check the amount and VAT.');
        }
        $expense->fill([
            'category_id' => $data['category_id'], 'department' => $data['department'] ?? null, 'description' => $data['description'],
            'supplier_id' => $data['supplier_id'] ?? null, 'payee' => empty($data['supplier_id']) ? ($data['payee'] ?? null) : null,
            'amount' => $amount, 'tax_amount' => $tax, 'expense_date' => $data['expense_date'], 'invoice_ref' => $data['invoice_ref'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);
        [$old, $new] = Audit::diff($expense);
        // A bigger amount on an approved expense goes back for approval.
        if ($expense->status === 'approved' && $expense->isDirty('amount') && Expense::needsApproval($amount) && $amount > (float) $expense->getOriginal('amount')) {
            $expense->forceFill(['status' => 'pending', 'approved_at' => null, 'approved_by' => null]);
            $new['status'] = 'pending';
        }
        $expense->save();
        if ($new) {
            Audit::record('finance.expense_changed', "Changed {$expense->number}", $expense, $old, $new, by: $by);
        }
    }

    /** @param UploadedFile[] $files */
    public function attach(Expense $expense, array $files): void
    {
        $expense->forceFill(['attachments' => array_values(array_merge($expense->attachments ?? [], $this->store($files)))])->save();
    }

    // ------------------------------------------------------------------

    private function expect(Expense $expense, array $statuses): void
    {
        if (! in_array($expense->status, $statuses, true)) {
            throw new BookingException("{$expense->number} is " . strtolower($expense->status_label) . ' — that can’t be done now.');
        }
    }

    /** Receipts are financial records: kept on the private disk and served only to signed-in staff. */
    private function store(array $files): array
    {
        $saved = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $saved[] = $file->store(self::DIR . '/' . now()->format('Y/m'), self::DISK);
            }
        }

        return $saved;
    }

    private function money($v): string
    {
        return config('hotel.currency_symbol') . number_format((float) $v, 2);
    }
}
