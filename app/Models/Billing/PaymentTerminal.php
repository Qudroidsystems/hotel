<?php

namespace App\Models\Billing;

use App\Models\Pos\PosOutlet;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A card terminal linked to the system (see TerminalService). */
class PaymentTerminal extends Model
{
    use BelongsToHotel;

    /** key => [label, what the identifier is] */
    public const PROVIDERS = [
        'paystack'   => ['Paystack Terminal', 'Terminal ID (Paystack → Terminals)'],
        'moniepoint' => ['Moniepoint POS', 'Terminal serial number'],
        'opay'       => ['OPay POS', 'POS serial number (SN)'],
        'demo'       => ['Demo terminal (pays itself, for trying out)', 'Any name'],
    ];

    protected $fillable = ['name', 'provider', 'identifier', 'pos_outlet_id', 'location', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'last_online' => 'boolean', 'last_seen_at' => 'datetime'];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TerminalPayment::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function getProviderLabelAttribute(): string
    {
        return self::PROVIDERS[$this->provider][0] ?? ucfirst($this->provider);
    }

    /** "Front desk 1 · Moniepoint" */
    public function getLabelAttribute(): string
    {
        return $this->name . ' · ' . explode(' ', $this->provider_label)[0];
    }
}
