<?php

namespace App\Models\Pos;

use App\Enums\PaymentMethod;
use App\Models\Folio;
use App\Models\FolioCharge;
use App\Models\HotelBankAccount;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PosOrder extends Model
{
    public const PAID = 'paid';            // settled at the till (cash, card, transfer…)
    public const CHARGED = 'charged';      // added to a guest's bill
    public const ATTENTION = 'attention';  // the server couldn't settle it as sent — a supervisor must sort it out
    public const VOIDED = 'voided';

    public const ROOM = 'room';            // `method` value for charge-to-room

    protected $fillable = [
        'uuid', 'number', 'outlet_id', 'status', 'method', 'bank_account_id', 'reference', 'reservation_id', 'folio_id',
        'room_number', 'guest_name', 'table_label', 'subtotal', 'discount_amount', 'discount_reason', 'tax_amount',
        'tax_breakdown', 'total', 'tendered', 'change_due', 'items_count', 'cashier_id', 'synced_by', 'device_id',
        'ordered_at', 'synced_at', 'was_offline', 'sync_note',
    ];

    protected $casts = [
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'tax_breakdown'   => 'array',
        'total'           => 'decimal:2',
        'tendered'        => 'decimal:2',
        'change_due'      => 'decimal:2',
        'was_offline'     => 'boolean',
        'ordered_at'      => 'datetime',
        'synced_at'       => 'datetime',
        'resolved_at'     => 'datetime',
        'voided_at'       => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'outlet_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosOrderItem::class, 'order_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(HotelBankAccount::class, 'bank_account_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function syncedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'synced_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Lines this order put on a guest bill. */
    public function folioCharges(): MorphMany
    {
        return $this->morphMany(FolioCharge::class, 'source');
    }

    public function isVoided(): bool
    {
        return $this->status === self::VOIDED;
    }

    public function paymentMethod(): ?PaymentMethod
    {
        return PaymentMethod::tryFrom((string) $this->method);
    }

    public function getMethodLabelAttribute(): string
    {
        return $this->method === self::ROOM ? 'Room ' . ($this->room_number ?: 'charge') : ($this->paymentMethod()?->label() ?? '—');
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::PAID      => 'Paid',
            self::CHARGED   => 'Charged to room',
            self::ATTENTION => 'Needs attention',
            self::VOIDED    => 'Voided',
            default         => ucfirst($this->status),
        };
    }

    public function getStatusPillAttribute(): string
    {
        return match ($this->status) {
            self::PAID      => 'st-success',
            self::CHARGED   => 'st-violet',
            self::ATTENTION => 'st-warning',
            default         => 'st-muted',
        };
    }

    /** Money taken at the till (not room charges, not voided). */
    public function scopeTakings($query)
    {
        return $query->where('status', self::PAID);
    }
}
