<?php

namespace App\Models\Keys;

use App\Models\Room;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One opening of a door (or a try): from the phone, a staff remote unlock, or the lock's own record. */
class DoorEvent extends Model
{
    use BelongsToHotel;

    public const KINDS = ['phone' => 'Phone key', 'remote' => 'Opened by staff', 'pin' => 'Door code', 'card' => 'Key card', 'key' => 'Metal key', 'other' => 'Other'];

    protected $fillable = ['room_id', 'key_id', 'kind', 'who', 'ok', 'note', 'provider_ref', 'at', 'user_id'];

    protected $casts = ['ok' => 'boolean', 'at' => 'datetime'];

    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function key(): BelongsTo { return $this->belongsTo(RoomKey::class, 'key_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function getKindLabelAttribute(): string { return self::KINDS[$this->kind] ?? $this->kind; }
}
