<?php

namespace App\Models\Rates;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Model;

/** One room type on one night in the rate calendar: its own price, closed online, or a minimum stay. */
class RateDay extends Model
{
    use BelongsToHotel;

    protected $fillable = ['room_type_id', 'date', 'price', 'closed', 'min_nights', 'note', 'source', 'updated_by'];

    protected $casts = [
        'date'   => 'date',
        'price'  => 'decimal:2',
        'closed' => 'boolean',
    ];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
