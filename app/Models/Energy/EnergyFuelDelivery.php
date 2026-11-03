<?php

namespace App\Models\Energy;

use App\Models\Finance\Expense;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Diesel delivered into a generator's tank. */
class EnergyFuelDelivery extends Model
{
    use BelongsToHotel;

    protected $fillable = ['source_id', 'delivered_at', 'litres', 'price_per_litre', 'total', 'supplier_id', 'supplier_name', 'invoice_ref', 'level_after', 'expense_id', 'received_by', 'notes'];

    protected $casts = ['delivered_at' => 'datetime', 'litres' => 'float', 'price_per_litre' => 'float', 'total' => 'float', 'level_after' => 'float'];

    public function source()
    {
        return $this->belongsTo(EnergySource::class, 'source_id');
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function getSupplierLabelAttribute(): ?string
    {
        return $this->supplier_name;
    }
}
