<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone signed in to the staff mobile app, for push notifications. */
class MobileDevice extends Model
{
    protected $fillable = ['user_id', 'push_token', 'platform', 'device_name', 'app_version', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
