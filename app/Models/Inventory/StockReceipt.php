<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Goods received note (GRN). */
class StockReceipt extends Model
{
    protected $fillable = ['supplier_id', 'purchase_order_id', 'location_id', 'supplier_invoice', 'received_on', 'total', 'notes', 'idempotency_key', 'received_by'];

    protected $casts = ['received_on' => 'date', 'total' => 'decimal:2'];

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function location(): BelongsTo { return $this->belongsTo(StockLocation::class, 'location_id'); }
    public function lines(): HasMany { return $this->hasMany(StockReceiptLine::class); }
    public function invoices(): HasMany { return $this->hasMany(SupplierInvoice::class, 'stock_receipt_id'); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
}
