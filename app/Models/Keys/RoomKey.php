<?php

namespace App\Models\Keys;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A key for a stay: a door PIN, a key card, or the phone. See RoomKeyService. */
class RoomKey extends Model
{
    use BelongsToHotel;

    public const KINDS = ['pin' => 'Door code', 'card' => 'Key card', 'phone' => 'Phone key'];
    public const STATUSES = [
        'active'  => ['Works', 'st-success'],
        'revoked' => ['Cancelled', 'st-muted'],
        'expired' => ['Ended', 'st-muted'],
        'lost'    => ['Reported lost', 'st-danger'],
        'failed'  => ['Not set on the lock', 'st-danger'],
    ];

    protected $fillable = ['reservation_id', 'room_id', 'kind', 'secret', 'card_number', 'provider', 'provider_ref', 'valid_from', 'valid_until', 'status', 'note', 'issued_by'];

    protected $hidden = ['secret'];

    protected $casts = [
        'secret'       => 'encrypted',
        'valid_from'   => 'datetime',
        'valid_until'  => 'datetime',
        'revoked_at'   => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function reservation(): BelongsTo { return $this->belongsTo(Reservation::class); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }

    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }

    /** Works right now. */
    public function isLive(): bool
    {
        return $this->status === 'active' && $this->valid_from->lte(now()) && $this->valid_until->gt(now());
    }

    public function getKindLabelAttribute(): string { return self::KINDS[$this->kind] ?? $this->kind; }
    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? $this->status; }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }
}
