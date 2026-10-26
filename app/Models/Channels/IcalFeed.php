<?php

namespace App\Models\Channels;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\Room;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class IcalFeed extends Model
{
    use BelongsToHotel;

    protected $fillable = ['room_id', 'name', 'import_url', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean', 'last_synced_at' => 'datetime'];

    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::creating(fn (self $f) => $f->export_token ??= Str::random(40));
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ChannelBooking::class);
    }

    /** Our calendar for this room, to paste into the site. */
    public function getExportUrlAttribute(): string
    {
        return route('channels.ical.export', $this->export_token);
    }
}
