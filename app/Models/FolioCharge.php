<?php

namespace App\Models;

use App\Support\Money;
use App\Enums\ChargeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

class FolioCharge extends Model
{
    protected $fillable = [
        'folio_id', 'idempotency_key', 'type', 'description', 'quantity', 'unit_price', 'amount', 'tax_amount', 'tax_breakdown',
        'tax_inclusive', 'total', 'service_date', 'reservation_room_id', 'source_type', 'source_id', 'posted_by',
    ];

    protected $casts = [
        'type'          => ChargeType::class,
        'quantity'      => 'decimal:2',
        'unit_price'    => 'decimal:2',
        'amount'        => 'decimal:2',
        'tax_amount'    => 'decimal:2',
        'tax_breakdown' => 'array',
        'tax_inclusive' => 'boolean',
        'total'         => 'decimal:2',
        'service_date'  => 'date',
        'voided_at'     => 'datetime',
    ];

    public function setServiceDateAttribute($value): void
    {
        $this->attributes['service_date'] = Carbon::parse($value)->toDateString();
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Tax for a net amount using the configured rates.
     * Inclusive: the amount already contains tax; returns the tax portion (not added).
     * Exclusive: tax is added on top.
     *
     * @return array{tax: float, breakdown: array, inclusive: bool, total: float}
     */
    public static function computeTax(float $amount, bool $taxable = true): array
    {
        $inclusive = (bool) config('hotel.prices_include_tax', true);
        $taxes = $taxable ? config('hotel.taxes', []) : [];
        $rateSum = array_sum(array_column($taxes, 'rate'));

        $breakdown = [];
        if ($rateSum > 0) {
            // Inclusive: base = amount / (1 + Σrates); each tax = base × rate.
            $base = $inclusive ? $amount / (1 + $rateSum / 100) : $amount;
            foreach ($taxes as $t) {
                $breakdown[] = ['name' => $t['name'], 'rate' => (float) $t['rate'], 'amount' => Money::round($base * $t['rate'] / 100)];
            }
        }
        $tax = Money::round(array_sum(array_column($breakdown, 'amount')));

        return [
            'tax'       => $inclusive ? 0.0 : $tax,           // only exclusive tax is added to the bill
            'breakdown' => $breakdown,
            'inclusive' => $inclusive,
            'total'     => Money::round($inclusive ? $amount : $amount + $tax),
        ];
    }
}
