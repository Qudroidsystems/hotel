<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash put into or taken out of a drawer during a shift, with the reason. */
class CashShiftMovement extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'paid_in'  => ['Paid in',        +1, 'ri-add-circle-line'],      // change from the safe, float top-up
        'paid_out' => ['Paid out',       -1, 'ri-indeterminate-circle-line'], // petty cash, small supplier payment
        'drop'     => ['Drop to safe',   -1, 'ri-safe-2-line'],          // excess cash moved to the safe mid-shift
    ];

    protected $fillable = ['cash_shift_id', 'type', 'amount', 'reason', 'user_id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::TYPES[$this->type][0] ?? $this->type;
    }

    public function sign(): int
    {
        return self::TYPES[$this->type][1] ?? 1;
    }
}
