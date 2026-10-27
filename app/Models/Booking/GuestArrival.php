<?php

namespace App\Models\Booking;

use App\Models\Reservation;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A guest sharing their way to the hotel (see ArrivalService). */
class GuestArrival extends Model
{
    use BelongsToHotel;

    protected $fillable = ['reservation_id', 'status', 'latitude', 'longitude', 'accuracy_m', 'start_distance_m', 'distance_m', 'eta_minutes', 'started_at', 'last_seen_at'];

    protected $casts = [
        'latitude' => 'float', 'longitude' => 'float',
        'started_at' => 'datetime', 'last_seen_at' => 'datetime', 'near_alerted_at' => 'datetime', 'here_alerted_at' => 'datetime', 'stopped_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function isSharing(): bool
    {
        return $this->status === 'sharing';
    }

    /** "12 min" / "1 h 20 min" */
    public function getEtaLabelAttribute(): ?string
    {
        $m = $this->eta_minutes;
        if ($m === null) {
            return null;
        }

        return $m < 1 ? 'any moment' : ($m < 60 ? "{$m} min" : intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : ''));
    }

    public function getDistanceLabelAttribute(): ?string
    {
        $d = $this->distance_m;

        return $d === null ? null : ($d < 1000 ? (int) (round($d / 10) * 10) . ' m' : number_format($d / 1000, $d < 10000 ? 1 : 0) . ' km');
    }
}
