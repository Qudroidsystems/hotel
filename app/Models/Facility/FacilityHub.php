<?php

namespace App\Models\Facility;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A hardware gateway (BMS, IoT hub, PLC bridge) allowed to report device states with its key. */
class FacilityHub extends Model
{
    use BelongsToHotel;

    protected $fillable = ['name', 'token_hash', 'token_hint', 'is_active', 'created_by'];

    protected $hidden = ['token_hash'];

    protected $casts = ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];
}
