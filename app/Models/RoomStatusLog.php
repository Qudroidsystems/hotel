<?php

namespace App\Models;

use App\Enums\RoomStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['room_id', 'from_status', 'to_status', 'reason', 'user_id'];

    protected $casts = [
        'from_status' => RoomStatus::class,
        'to_status'   => RoomStatus::class,
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
