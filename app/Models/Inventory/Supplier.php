<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['name', 'contact_person', 'phone', 'email', 'address', 'payment_terms_days', 'notes', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(StockReceipt::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    /** What the hotel still owes this supplier. */
    public function balance(): float
    {
        return round((float) $this->invoices()->open()->sum('amount') - (float) $this->invoices()->open()->sum('paid'), 2);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
