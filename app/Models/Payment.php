<?php

namespace App\Models;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['folio_id', 'idempotency_key', 'kind', 'method', 'amount', 'reference', 'bank_account_id', 'notes', 'received_by', 'received_at'];

    protected $casts = [
        'kind'        => PaymentKind::class,
        'method'      => PaymentMethod::class,
        'amount'      => 'decimal:2',
        'received_at' => 'datetime',
        'voided_at'   => 'datetime',
    ];

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(HotelBankAccount::class, 'bank_account_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** Signed amount: refunds count as money out. */
    public function getSignedAmountAttribute(): float
    {
        return (float) $this->amount * $this->kind->sign();
    }

    public function scopeValid($query)
    {
        return $query->whereNull('voided_at');
    }
}
