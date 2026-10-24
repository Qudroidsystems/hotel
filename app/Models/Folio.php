<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A guest bill. Totals are always computed from non-voided lines. */
class Folio extends Model
{
    protected $fillable = ['reservation_id', 'guest_id', 'status', 'credit_limit'];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'closed_at'    => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(FolioCharge::class)->orderBy('service_date')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_at')->orderBy('id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    // ── Money ─────────────────────────────────────────────────────────

    public function chargesTotal(): float
    {
        return round((float) $this->charges()->whereNull('voided_at')->sum('total'), 2);
    }

    public function taxTotal(): float
    {
        return round((float) $this->charges()->whereNull('voided_at')->sum('tax_amount'), 2);
    }

    /** Money received minus refunds. */
    public function paidTotal(): float
    {
        $in = (float) $this->payments()->whereNull('voided_at')->where('kind', '!=', 'refund')->sum('amount');
        $out = (float) $this->payments()->whereNull('voided_at')->where('kind', 'refund')->sum('amount');

        return round($in - $out, 2);
    }

    /** Positive = guest owes the hotel; negative = hotel owes the guest (credit). */
    public function balance(): float
    {
        return round($this->chargesTotal() - $this->paidTotal(), 2);
    }

    /** Totals for a list of folios in two queries (for list pages). */
    public static function balances(array $folioIds): array
    {
        if (! $folioIds) {
            return [];
        }

        $charges = FolioCharge::whereIn('folio_id', $folioIds)->whereNull('voided_at')
            ->groupBy('folio_id')->selectRaw('folio_id, sum(total) as t')->pluck('t', 'folio_id');
        $paid = Payment::whereIn('folio_id', $folioIds)->whereNull('voided_at')
            ->groupBy('folio_id')->selectRaw("folio_id, sum(case when kind = 'refund' then -amount else amount end) as t")->pluck('t', 'folio_id');

        $out = [];
        foreach ($folioIds as $id) {
            $out[$id] = [
                'charges' => round((float) ($charges[$id] ?? 0), 2),
                'paid'    => round((float) ($paid[$id] ?? 0), 2),
                'balance' => round((float) ($charges[$id] ?? 0) - (float) ($paid[$id] ?? 0), 2),
            ];
        }

        return $out;
    }
}
