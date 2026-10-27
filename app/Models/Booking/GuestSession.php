<?php

namespace App\Models\Booking;

use Illuminate\Database\Eloquent\Model;

/** A guest signed in on the guest app (see GuestAccountService). Not tied to one hotel. */
class GuestSession extends Model
{
    protected $fillable = ['token_hash', 'contact', 'device', 'last_used_at', 'expires_at'];

    protected $casts = ['last_used_at' => 'datetime', 'expires_at' => 'datetime'];
}
