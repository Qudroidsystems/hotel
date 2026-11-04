<?php

namespace App\Models\Wellness;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone who gives treatments. One treatment at a time each, so they set how many can be booked together. */
class WellnessTherapist extends Model
{
    use BelongsToHotel;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['venue_id', 'name', 'user_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function venue(): BelongsTo { return $this->belongsTo(WellnessVenue::class, 'venue_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
