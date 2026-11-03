<?php

namespace App\Models\Energy;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** The hotel's power at a moment (taken every 15 minutes by energy:check). */
class EnergySample extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $fillable = ['at', 'kw', 'grid_on', 'source_id', 'litres_per_hour', 'rooms_in_use', 'waste_kw'];

    protected $casts = ['at' => 'datetime', 'kw' => 'float', 'grid_on' => 'boolean', 'litres_per_hour' => 'float', 'waste_kw' => 'float'];
}
