<?php

namespace App\Models\Booking;

use Illuminate\Database\Eloquent\Model;

/** A one-time sign-in code sent to a guest's e-mail or phone. Only its hash is kept. */
class GuestLoginCode extends Model
{
    protected $fillable = ['contact', 'code_hash', 'attempts', 'expires_at', 'used_at', 'ip'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
}
