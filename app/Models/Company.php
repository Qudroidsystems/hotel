<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A company with an account at the hotel (city ledger): its staff's stays and its events are billed to it. */
class Company extends Model
{
    protected $attributes = ['credit_limit' => 0, 'payment_terms_days' => 30, 'is_active' => true];

    protected $fillable = [
        'account_number', 'name', 'contact_name', 'email', 'phone', 'address', 'tax_number',
        'credit_limit', 'payment_terms_days', 'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'is_active'    => 'boolean',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $q : $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
            ->orWhere('account_number', 'like', "%{$term}%")->orWhere('contact_name', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
    }

    public function entries()
    {
        return $this->hasMany(CompanyLedgerEntry::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function guests()
    {
        return $this->hasMany(Guest::class);
    }

    public function ratePlans()
    {
        return $this->hasMany(Rates\RatePlan::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What the company owes now (voided entries left out). */
    public function balance(): float
    {
        return round((float) $this->entries()->whereNull('voided_at')->sum('amount'), 2);
    }

    /** Room left under the credit limit (null = no limit). */
    public function available(): ?float
    {
        return (float) $this->credit_limit > 0 ? round((float) $this->credit_limit - $this->balance(), 2) : null;
    }
}
