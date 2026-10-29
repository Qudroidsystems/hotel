<?php

namespace App\Models\Events;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\Company;
use App\Models\CompanyLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A booking of a hall: from the first enquiry, to the day, to settling the bill. */
class Event extends Model
{
    use BelongsToHotel;

    public const TYPES = [
        'meeting' => 'Meeting', 'conference' => 'Conference / seminar', 'training' => 'Training',
        'wedding' => 'Wedding / reception', 'birthday' => 'Birthday / party', 'burial' => 'Funeral reception',
        'launch' => 'Product launch', 'religious' => 'Religious gathering', 'other' => 'Other',
    ];

    /** status => [label, pill class, holds the hall?] */
    public const STATUSES = [
        'enquiry'   => ['Enquiry', 'st-muted', false],
        'tentative' => ['Provisional', 'st-warning', true],
        'confirmed' => ['Confirmed', 'st-success', true],
        'completed' => ['Done', 'st-info', false],
        'cancelled' => ['Cancelled', 'st-danger', false],
    ];

    public const HOLDING = ['tentative', 'confirmed'];

    protected $attributes = ['status' => 'enquiry', 'event_type' => 'meeting', 'hall_rate_type' => 'full_day', 'discount' => 0, 'package_price' => 0, 'hall_charge' => 0, 'deposit_due' => 0, 'guests_expected' => 0];

    protected $fillable = [
        'number', 'title', 'event_type', 'status', 'hall_id', 'starts_at', 'ends_at', 'setup', 'guests_expected', 'company_id',
        'organiser_name', 'organiser_phone', 'organiser_email', 'package_id', 'package_price', 'hall_rate_type', 'hall_charge',
        'discount', 'subtotal', 'tax_amount', 'total', 'deposit_due', 'requirements', 'internal_notes', 'cancel_reason',
        'confirmed_at', 'cancelled_at', 'created_by', 'is_ticketed', 'ticket_info',
    ];

    protected $casts = [
        'starts_at' => 'datetime', 'ends_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime',
        'checked_in_at' => 'datetime', 'checked_out_at' => 'datetime', 'is_ticketed' => 'boolean', 'ticket_sales_open' => 'boolean',
        'package_price' => 'decimal:2', 'hall_charge' => 'decimal:2', 'discount' => 'decimal:2', 'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2', 'total' => 'decimal:2', 'deposit_due' => 'decimal:2',
    ];

    public function scopeHolding(Builder $q): Builder
    {
        return $q->whereIn('status', self::HOLDING);
    }

    public function scopeOverlapping(Builder $q, $from, $to): Builder
    {
        return $q->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    public function hall()
    {
        return $this->belongsTo(Hall::class);
    }

    public function package()
    {
        return $this->belongsTo(EventPackage::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function items()
    {
        return $this->hasMany(EventItem::class)->orderBy('id');
    }

    public function payments()
    {
        return $this->hasMany(EventPayment::class)->orderBy('received_at');
    }

    public function companyInvoices()
    {
        return $this->hasMany(CompanyLedgerEntry::class)->whereNull('voided_at');
    }

    public function ticketTypes()
    {
        return $this->hasMany(EventTicketType::class)->orderBy('sort_order')->orderBy('id');
    }

    public function ticketOrders()
    {
        return $this->hasMany(EventTicketOrder::class)->latest('id');
    }

    public function tickets()
    {
        return $this->hasMany(EventTicket::class);
    }

    public function checkedInBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy()
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    /** Where the event is on the day: booked, in the hall, or the hall given back. */
    public function getStageAttribute(): string
    {
        return match (true) {
            $this->checked_out_at !== null => 'out',
            $this->checked_in_at !== null  => 'in',
            default                        => 'booked',
        };
    }

    /** People the tickets may admit in all: the hall's capacity for the layout, else the guests expected. */
    public function ticketCapacity(): int
    {
        return (int) ($this->hall?->capacityFor($this->setup) ?: $this->guests_expected);
    }

    /** People admitted by tickets sold (a table for ten counts ten). */
    public function ticketSeatsSold(): int
    {
        return (int) $this->tickets()->where('event_tickets.status', '!=', 'void')
            ->join('event_ticket_types', 'event_ticket_types.id', '=', 'event_tickets.ticket_type_id')->sum('event_ticket_types.admits');
    }

    public function ticketRevenue(): float
    {
        return round((float) $this->ticketOrders()->where('status', 'paid')->sum('total'), 2);
    }

    /** Why tickets can't be sold now, or null. */
    public function ticketSalesBlocker(): ?string
    {
        return match (true) {
            ! $this->is_ticketed => 'This event has no tickets.',
            $this->status !== 'confirmed' => 'Tickets are sold only for confirmed events.',
            $this->ends_at->isPast() => 'This event is over.',
            ! $this->ticket_sales_open => 'Ticket sales are closed.',
            default => null,
        };
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function getStatusClassAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->event_type] ?? ucfirst($this->event_type);
    }

    public function getPackageTotalAttribute(): float
    {
        return round((float) $this->package_price * (int) $this->guests_expected, 2);
    }

    public function paid(): float
    {
        return round($this->payments->whereNull('voided_at')->sum(fn ($p) => $p->signed()), 2);
    }

    public function billedToCompany(): float
    {
        return round((float) $this->companyInvoices()->sum('amount'), 2);
    }

    public function balance(): float
    {
        return round((float) $this->total - $this->paid() - $this->billedToCompany(), 2);
    }

    /** "Sat 14 Dec, 10:00 – 16:00" or across days. */
    public function getWhenAttribute(): string
    {
        return $this->starts_at->isSameDay($this->ends_at)
            ? $this->starts_at->format('D j M Y, g:i A') . ' – ' . $this->ends_at->format('g:i A')
            : $this->starts_at->format('D j M, g:i A') . ' – ' . $this->ends_at->format('D j M Y, g:i A');
    }
}
