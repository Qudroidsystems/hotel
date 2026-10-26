<?php

namespace App\Models\Inventory;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use BelongsToHotel;

    protected $fillable = ['supplier_invoice_id', 'amount', 'method', 'reference', 'paid_on', 'paid_by', 'notes'];

    protected $casts = ['amount' => 'decimal:2', 'paid_on' => 'date'];

    public function invoice(): BelongsTo { return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id'); }
    public function paidBy(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }

    public function getMethodLabelAttribute(): string { return SupplierInvoice::METHODS[$this->method] ?? ucfirst($this->method); }
}
