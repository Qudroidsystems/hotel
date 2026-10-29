<?php

namespace App\Models\Website;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A message sent from the website's forms: contact, event enquiry or table booking. */
class WebsiteMessage extends Model
{
    use BelongsToHotel;

    public const KINDS = [
        'contact' => ['Message', 'ri-mail-line', 'st-info'],
        'event'   => ['Event enquiry', 'ri-calendar-event-line', 'st-info'],
        'dining'  => ['Table booking', 'ri-restaurant-line', 'st-warning'],
    ];

    public const STATUSES = [
        'new'     => ['New', 'st-danger'],
        'replied' => ['Answered', 'st-success'],
        'closed'  => ['Closed', 'st-muted'],
    ];

    protected $fillable = ['kind', 'name', 'email', 'phone', 'subject', 'message', 'details', 'status', 'read_at', 'handled_by', 'handled_at', 'note', 'ip'];

    protected $casts = ['details' => 'array', 'read_at' => 'datetime', 'handled_at' => 'datetime'];

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind][0] ?? 'Message';
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function getStatusPillAttribute(): string
    {
        return self::STATUSES[$this->status][1] ?? 'st-muted';
    }

    /** The form's extra answers in words, e.g. ['Date' => 'Sat 12 Dec 2026', 'Guests' => '80']. */
    public function getDetailLinesAttribute(): array
    {
        $labels = ['date' => 'Date', 'time' => 'Time', 'guests' => 'Guests', 'event_type' => 'Type of event', 'venue' => 'Venue', 'hall' => 'Hall', 'source' => 'Sent from'];
        $out = [];
        foreach ((array) $this->details as $k => $v) {
            if (filled($v)) {
                $out[$labels[$k] ?? ucfirst(str_replace('_', ' ', $k))] = $k === 'date' ? rescue(fn () => \Illuminate\Support\Carbon::parse($v)->format('D j M Y'), $v, false) : $v;
            }
        }

        return $out;
    }
}
