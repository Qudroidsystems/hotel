<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note on an incident's timeline (actions taken, escalations, status changes). */
class IncidentUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['incident_id', 'user_id', 'status', 'note'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
