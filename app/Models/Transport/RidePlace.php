<?php

namespace App\Models\Transport;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A place the hotel drives to often, at a set fare (the airport, the bus station …). */
class RidePlace extends Model
{
    use BelongsToHotel;

    public const KINDS = ['airport' => 'Airport', 'station' => 'Station / park', 'other' => 'Place'];

    protected $fillable = ['name', 'kind', 'latitude', 'longitude', 'fare', 'sort_order', 'is_active'];

    protected $casts = ['latitude' => 'float', 'longitude' => 'float', 'fare' => 'decimal:2', 'is_active' => 'boolean'];
}
