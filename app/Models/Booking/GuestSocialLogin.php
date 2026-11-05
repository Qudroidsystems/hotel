<?php

namespace App\Models\Booking;

use Illuminate\Database\Eloquent\Model;

/** A Google or Apple account a guest signs in with (see GuestAccountService::socialSignIn). Not tied to one hotel. */
class GuestSocialLogin extends Model
{
    protected $fillable = ['provider', 'subject', 'email', 'first_name', 'last_name', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];
}
