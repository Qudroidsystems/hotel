<?php

namespace App\Models\Transport;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A driver: on duty or not, their usual car, and where they were last seen (while on duty). */
class Driver extends Model
{
    use BelongsToHotel;

    protected $fillable = ['user_id', 'name', 'phone', 'licence_number', 'licence_expires', 'vehicle_id', 'on_duty', 'is_active'];

    protected $casts = ['on_duty' => 'boolean', 'is_active' => 'boolean', 'licence_expires' => 'date', 'seen_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function rides(): HasMany { return $this->hasMany(Ride::class); }

    /** A ride they are on now (assigned and not finished). */
    public function activeRide(): ?Ride
    {
        return $this->rides()->whereIn('status', Ride::ACTIVE)->orderByRaw("CASE status WHEN 'on_trip' THEN 0 WHEN 'arrived' THEN 1 WHEN 'on_the_way' THEN 2 ELSE 3 END")->orderBy('pickup_at')->first();
    }

    public function isFree(): bool
    {
        return $this->on_duty && $this->is_active && ! $this->rides()->whereIn('status', ['on_the_way', 'arrived', 'on_trip'])->exists();
    }

    public function getInitialsAttribute(): string
    {
        return collect(explode(' ', $this->name))->filter()->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
    }

    public function licenceExpired(): bool
    {
        return $this->licence_expires !== null && $this->licence_expires->lt(today());
    }
}
