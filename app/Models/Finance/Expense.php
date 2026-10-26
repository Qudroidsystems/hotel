<?php

namespace App\Models\Finance;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\CashShift;
use App\Models\HotelBankAccount;
use App\Models\Inventory\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A running cost: recorded → approved (automatically when small enough) →
 * paid. Rejected and voided expenses stay on record but count nowhere.
 */
class Expense extends Model
{
    use BelongsToHotel;

    public const STATUSES = [
        'pending'  => ['Waiting for approval', 'st-warning'],
        'approved' => ['Approved, not paid', 'st-info'],
        'paid'     => ['Paid', 'st-success'],
        'rejected' => ['Rejected', 'st-muted'],
        'void'     => ['Void', 'st-muted'],
    ];

    /** Statuses that count as a cost (accrual: approved counts before it is paid). */
    public const COUNTED = ['approved', 'paid'];

    public const METHODS = [
        'cash'     => 'Cash',
        'transfer' => 'Bank transfer',
        'card'     => 'Card / POS',
        'cheque'   => 'Cheque',
        'other'    => 'Other',
    ];

    protected $fillable = ['category_id', 'department', 'description', 'supplier_id', 'payee', 'amount', 'tax_amount', 'expense_date', 'invoice_ref', 'notes', 'recorded_by'];

    protected $casts = [
        'amount'       => 'decimal:2',
        'tax_amount'   => 'decimal:2',
        'expense_date' => 'date',
        'attachments'  => 'array',
        'approved_at'  => 'datetime',
        'paid_at'      => 'datetime',
        'voided_at'    => 'datetime',
    ];

    public function category(): BelongsTo { return $this->belongsTo(ExpenseCategory::class, 'category_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function payer(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }
    public function voider(): BelongsTo { return $this->belongsTo(User::class, 'voided_by'); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(HotelBankAccount::class, 'bank_account_id'); }
    public function cashShift(): BelongsTo { return $this->belongsTo(CashShift::class, 'cash_shift_id'); }

    public function scopeCounted($q) { return $q->whereIn('status', self::COUNTED); }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? $this->status; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
    public function getPayeeNameAttribute(): ?string { return $this->supplier?->name ?? $this->payee; }
    public function getMethodLabelAttribute(): ?string { return $this->method ? (self::METHODS[$this->method] ?? $this->method) : null; }

    /** The cost for profit & loss: what was paid less any VAT it included. */
    public function getNetAmountAttribute(): float { return (float) $this->amount - (float) $this->tax_amount; }

    /** Over the approval limit (0 = every expense needs approval). */
    public static function needsApproval(float $amount): bool
    {
        $limit = (float) config('hotel.expenses.approval_limit', 50000);

        return $limit <= 0 || $amount > $limit;
    }
}
