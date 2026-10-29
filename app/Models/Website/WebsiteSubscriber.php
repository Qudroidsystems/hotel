<?php

namespace App\Models\Website;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Someone who signed up for the hotel's news on the website or in the guest app. */
class WebsiteSubscriber extends Model
{
    use BelongsToHotel;

    protected $fillable = ['email', 'name', 'source', 'unsubscribed_at'];

    protected $casts = ['unsubscribed_at' => 'datetime'];
}
