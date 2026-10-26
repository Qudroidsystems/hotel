<?php

namespace App\Models\It;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItTicketUpdate extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['ticket_id', 'user_id', 'note', 'status', 'is_internal'];

    protected $casts = ['is_internal' => 'boolean', 'created_at' => 'datetime'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
