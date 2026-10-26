<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationRoom extends Model
{
    use BelongsToHotel;

    protected $fillable = ['reservation_id', 'room_type_id', 'room_id', 'adults', 'children', 'nightly_rates', 'amount'];

    protected $casts = [
        'nightly_rates' => 'array',
        'amount'        => 'decimal:2',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
