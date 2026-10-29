<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Model;

/** One WebRTC signalling message (offer, answer, ICE candidate, hang-up) for a call. */
class GuestCallSignal extends Model
{
    public $timestamps = false;

    protected $fillable = ['call_id', 'side', 'kind', 'payload', 'created_at'];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];
}
