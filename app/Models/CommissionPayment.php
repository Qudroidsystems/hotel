<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Commission a hotel paid the group for a month's group-site bookings (managed by the group, not scoped). */
class CommissionPayment extends Model
{
    protected $fillable = ['hotel_id', 'period', 'amount', 'received_on', 'reference', 'note', 'recorded_by'];

    protected $casts = ['amount' => 'decimal:2', 'received_on' => 'date'];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
