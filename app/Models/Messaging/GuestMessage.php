<?php

namespace App\Models\Messaging;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One SMS / WhatsApp message to or from a guest. */
class GuestMessage extends Model
{
    use BelongsToHotel;

    public const STATUSES = [
        'sent'      => ['Sent', 'st-info'],
        'delivered' => ['Delivered', 'st-success'],
        'read'      => ['Read', 'st-success'],
        'failed'    => ['Not delivered', 'st-danger'],
        'skipped'   => ['Not sent', 'st-muted'],
        'received'  => ['Received', 'st-warning'],
    ];

    public const TEMPLATES = [
        'booking_confirmed' => 'Booking confirmed',
        'payment_link'      => 'Deposit needed',
        'arrival_reminder'  => 'Arrival reminder',
        'thank_you'         => 'Thank you',
        'request_update'    => 'Request update',
        'manual'            => 'Written by staff',
        'reply'             => 'Guest reply',
    ];

    protected $fillable = ['guest_id', 'reservation_id', 'direction', 'channel', 'to', 'from', 'body', 'template', 'status', 'provider_id', 'error', 'sent_by', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function guest(): BelongsTo { return $this->belongsTo(Guest::class); }
    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sent_by'); }

    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? ucfirst($this->status); }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
    public function getTemplateLabelAttribute(): string { return self::TEMPLATES[$this->template] ?? ucfirst(str_replace('_', ' ', (string) $this->template)); }
    public function getChannelLabelAttribute(): string { return $this->channel === 'whatsapp' ? 'WhatsApp' : 'SMS'; }
}
