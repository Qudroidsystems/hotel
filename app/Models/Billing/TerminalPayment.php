<?php

namespace App\Models\Billing;

use App\Models\Folio;
use App\Models\Payment;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An amount sent to a card terminal, and what became of it. */
class TerminalPayment extends Model
{
    use BelongsToHotel;

    public const SENDING = 'sending';
    public const WAITING = 'waiting';     // on the terminal, waiting for the card
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    public const OPEN = [self::SENDING, self::WAITING];

    /** status => [label, pill] */
    public const STATUSES = [
        self::SENDING   => ['Sending to the terminal', 'st-info'],
        self::WAITING   => ['Waiting for the card', 'st-warning'],
        self::PAID      => ['Paid', 'st-success'],
        self::FAILED    => ['Declined / failed', 'st-danger'],
        self::CANCELLED => ['Cancelled', 'st-muted'],
        self::EXPIRED   => ['Not paid in time', 'st-muted'],
    ];

    protected $fillable = ['payment_terminal_id', 'reference', 'amount', 'folio_id', 'pos_outlet_id', 'description', 'requested_by', 'source', 'expires_at'];

    protected $casts = [
        'amount'      => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'paid_at'     => 'datetime',
        'expires_at'  => 'datetime',
        'checked_at'  => 'datetime',
        'raw'         => 'array',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PaymentTerminal::class, 'payment_terminal_id');
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeOpen($q)
    {
        return $q->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }

    /** What the screens and the apps show while waiting. */
    public function toClient(): array
    {
        return [
            'id'          => $this->id,
            'reference'   => $this->reference,
            'terminal'    => $this->terminal?->name,
            'provider'    => $this->terminal?->provider,
            'amount'      => (float) $this->amount,
            'status'      => $this->status,
            'status_label' => $this->status_label,
            'open'        => $this->isOpen(),
            'paid'        => $this->status === self::PAID,
            'approval'    => $this->approval,
            'card'        => $this->card,
            'message'     => $this->message,
            'receipt'     => $this->payment?->receipt_number,
            'folio_id'    => $this->folio_id,
            'expires_at'  => $this->expires_at?->toIso8601String(),
            'paid_at'     => $this->paid_at?->toIso8601String(),
        ];
    }
}
