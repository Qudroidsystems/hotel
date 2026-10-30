<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;

/**
 * One WebRTC signalling message (offer, answer, ICE candidate) for a call: for the leg it was sent on,
 * and, for a supervisor listening in, the listen (sides 'tap' from the hotel side and 'monitor' back).
 */
class GuestCallSignal extends Model
{
    public $timestamps = false;

    protected $fillable = ['call_id', 'leg', 'listen_id', 'side', 'kind', 'payload', 'created_at'];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];
}
