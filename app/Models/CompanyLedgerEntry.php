<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** One line on a company's account: an invoice (+), a payment or credit (−), or an adjustment (±). */
class CompanyLedgerEntry extends Model
{
    public const TYPES = [
        'invoice'    => ['Invoice', 'st-info'],
        'payment'    => ['Payment', 'st-success'],
        'credit'     => ['Credit note', 'st-warning'],
        'adjustment' => ['Adjustment', 'st-muted'],
    ];

    protected $fillable = [
        'company_id', 'type', 'number', 'entry_date', 'due_date', 'description', 'amount', 'reservation_id', 'folio_id',
        'payment_id', 'method', 'reference', 'created_by', 'event_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'due_date'   => 'date',
        'amount'     => 'decimal:2',
        'voided_at'  => 'datetime',
    ];

    public function scopeLive(Builder $q): Builder
    {
        return $q->whereNull('voided_at');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function event()
    {
        return $this->belongsTo(\App\Models\Events\Event::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type][0] ?? ucfirst($this->type);
    }

    public function getTypeClassAttribute(): string
    {
        return self::TYPES[$this->type][1] ?? 'st-muted';
    }
}
