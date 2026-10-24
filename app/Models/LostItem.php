<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Something found on the property, from logging to handover (spec §12.3). */
class LostItem extends Model
{
    public const CATEGORIES = [
        'phone' => 'Phone', 'electronics' => 'Electronics / charger', 'documents' => 'Documents / ID', 'jewellery' => 'Jewellery / watch',
        'money' => 'Money / cards', 'bag' => 'Bag / wallet', 'clothing' => 'Clothing / shoes', 'keys' => 'Keys', 'other' => 'Other',
    ];

    public const STATUSES = [
        'stored'   => ['In storage', 'st-warning'],
        'claimed'  => ['Returned to owner', 'st-success'],
        'disposed' => ['Disposed', 'st-muted'],
    ];

    protected $fillable = ['description', 'category', 'valuable', 'found_at', 'found_location', 'room_id', 'found_by', 'storage_location', 'photo_path'];

    protected $casts = [
        'valuable'       => 'boolean',
        'found_at'       => 'datetime',
        'handed_over_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function finder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'found_by');
    }

    public function handedOverBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_by');
    }

    public function getCategoryLabelAttribute(): string { return self::CATEGORIES[$this->category] ?? ucfirst($this->category); }
    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status][0] ?? ucfirst($this->status); }
    public function getStatusPillAttribute(): string { return self::STATUSES[$this->status][1] ?? 'st-muted'; }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }
}
