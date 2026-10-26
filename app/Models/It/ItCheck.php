<?php

namespace App\Models\It;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One automated check result. */
class ItCheck extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $fillable = ['monitor_id', 'status', 'latency_ms', 'message', 'checked_at'];

    protected $casts = ['checked_at' => 'datetime'];
}
