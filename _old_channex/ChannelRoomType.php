<?php

namespace App\Models\Channels;

use App\Models\RoomType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelRoomType extends Model
{
    protected $fillable = ['room_type_id', 'external_id', 'sell_limit'];

    protected $casts = ['sell_limit' => 'integer'];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
