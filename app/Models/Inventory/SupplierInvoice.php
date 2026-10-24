<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A bill from a supplier, matched to the delivery it charges for (spec §8.3). */
class SupplierInvoice extends Model
{
    public const STATUSES = [
        'unpaid'    => ['Unpaid', 'st-warning'],
        'part_paid' => ['Part paid', 'st-info'],
        'paid'      => ['Paid', 'st-success'],
        'void'      => ['Void', 'st-muted'],
    ];

    public const METHODS = ['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque', 'pos' => 'Card / POS'];

    protected $fillable = ['supplier_id', 'invoice_number', 'stock_receipt_id', 'purchase_order_id', 'invoice_date', 'due_date', 'amount', 'notes', 'recorded_by'];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date'     => 'date',
        'amount'       => 'decimal:2',
        'paid'         => 'decimal:2',
    ];

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function receipt(): BelongsTo { return $this->belongsTo(StockReceipt::class, 'stock_receipt_id'); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function payments(): HasMany { return $this->hasMany(SupplierPayment::class)->orderBy('paid_on')->orderBy('id'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }

    public function scopeOpen($q) { return $q->whereIn('status', ['unpaid', 'part_paid']); }

    public function balance(): float { return $this->status === 'void' ? 0.0 : round((float) $this->amount - (float) $this->paid, 2); }
    public function isOverdue(): bool { return in_array($this->status, ['unpaid', 'part_paid'], true) && $this->due_date->lt(today()); }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? ucfirst($this->status); }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
}
