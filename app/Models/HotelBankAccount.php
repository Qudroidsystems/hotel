<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HotelBankAccount extends Model
{
    protected $fillable = [
        'bank_name', 'account_name', 'account_number', 'branch', 'sort_code', 'currency',
        'purpose', 'show_on_invoice', 'accepts_transfers', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'show_on_invoice'   => 'boolean',
        'accepts_transfers' => 'boolean',
        'is_active'         => 'boolean',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'bank_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('bank_name');
    }

    /** Accounts offered when a transfer is recorded. */
    public static function forTransfers()
    {
        return static::active()->where('accepts_transfers', true)->ordered()->get();
    }

    /** Accounts printed on invoices. */
    public static function forInvoices()
    {
        return static::active()->where('show_on_invoice', true)->ordered()->get();
    }

    /** "GTBank · 0123456789" */
    public function getLabelAttribute(): string
    {
        return $this->bank_name . ' · ' . $this->account_number;
    }

    /** "GTBank ••6789" for receipts. */
    public function getShortLabelAttribute(): string
    {
        return $this->bank_name . ' ••' . substr($this->account_number, -4);
    }
}
