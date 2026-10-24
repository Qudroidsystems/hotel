<?php

namespace App\Models;

use App\Support\HotelSettings;
use Illuminate\Database\Eloquent\Model;

/** A hotel setting saved by the administrator. See App\Support\HotelSettings. */
class HotelSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = ['value' => 'json'];

    protected static function booted(): void
    {
        // Every change clears the cached values so the next request uses them.
        static::saved(fn () => HotelSettings::forget());
        static::deleted(fn () => HotelSettings::forget());
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
