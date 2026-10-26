<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** One hotel. A single-hotel system has just one; a group runs several from the same system. */
class Hotel extends Model
{
    protected $fillable = [
        'name', 'slug', 'city', 'state', 'country', 'address', 'phone', 'email', 'description', 'logo', 'cover_photo',
        'stars', 'latitude', 'longitude', 'is_active', 'is_listed', 'sort_order', 'commission_percent',
    ];

    protected $casts = ['is_active' => 'boolean', 'is_listed' => 'boolean', 'stars' => 'integer', 'latitude' => 'float', 'longitude' => 'float', 'commission_percent' => 'decimal:2'];

    /** The commission on bookings through the group site: the hotel's own rate, else the group's usual one. */
    public function commissionRate(): float
    {
        return (float) ($this->commission_percent ?? config('hotel.commission_percent', 0));
    }

    protected $attributes = ['is_active' => true, 'is_listed' => true, 'sort_order' => 0];

    protected static function booted(): void
    {
        static::creating(function (self $h) {
            $base = Str::slug($h->slug ?: $h->name) ?: 'hotel';
            $slug = $base;
            for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
                $slug = "{$base}-{$i}";
            }
            $h->slug = $slug;
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    public function getLocationAttribute(): string
    {
        return collect([$this->city, $this->state])->filter()->implode(', ');
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? (str_starts_with($this->logo, 'http') ? $this->logo : Storage::disk('public')->url($this->logo)) : null;
    }

    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover_photo ? (str_starts_with($this->cover_photo, 'http') ? $this->cover_photo : Storage::disk('public')->url($this->cover_photo)) : null;
    }
}
