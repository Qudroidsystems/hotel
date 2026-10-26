<?php

namespace App\Models\Events;

use App\Support\Tenancy\BelongsToHotel;
use App\Enums\PaymentMethod;
use App\Models\HotelBankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EventPayment extends Model
{
    use BelongsToHotel;

    protected $fillable = ['event_id', 'number', 'kind', 'method', 'amount', 'reference', 'bank_account_id', 'received_by', 'received_at'];

    protected $casts = ['method' => PaymentMethod::class, 'amount' => 'decimal:2', 'received_at' => 'datetime', 'voided_at' => 'datetime'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function bankAccount()
    {
        return $this->belongsTo(HotelBankAccount::class);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** + for money in, − for a refund. */
    public function signed(): float
    {
        return ($this->kind === 'refund' ? -1 : 1) * (float) $this->amount;
    }
}
