<?php

namespace App\Models\Channels;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelBooking extends Model
{
    use BelongsToHotel;

    public const RESULTS = [
        'pending'   => ['Waiting', 'st-neutral'],
        'imported'  => ['Booked in', 'st-success'],
        'updated'   => ['Changed', 'st-info'],
        'cancelled' => ['Cancelled', 'st-danger'],
        'failed'    => ['Needs attention', 'st-warning'],
        'skipped'   => ['Nothing to do', 'st-neutral'],
    ];

    protected $fillable = [
        'provider', 'external_id', 'revision_id', 'ota_name', 'ota_code', 'status', 'reservation_id', 'ical_feed_id',
        'payload', 'result', 'error', 'overbooked', 'received_at', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array', 'overbooked' => 'boolean', 'received_at' => 'datetime', 'processed_at' => 'datetime',
    ];

    protected $attributes = ['result' => 'pending', 'overbooked' => false];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(IcalFeed::class, 'ical_feed_id');
    }

    public function getResultLabelAttribute(): string
    {
        return self::RESULTS[$this->result][0] ?? ucfirst($this->result);
    }

    public function getResultClassAttribute(): string
    {
        return self::RESULTS[$this->result][1] ?? 'st-neutral';
    }
}
