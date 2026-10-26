<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToHotel;
use App\Support\HotelSettings;
use Illuminate\Database\Eloquent\Model;

/** A hotel setting saved by the administrator. See App\Support\HotelSettings. */
class HotelSetting extends Model
{
    use BelongsToHotel;

    protected $fillable = ['key', 'value', 'updated_by'];

    protected $casts = ['value' => 'json'];

    protected static function booted(): void
    {
        // Every change clears the cached values so the next request uses them.
        static::saved(function (self $s) {
            HotelSettings::forget();
            // The hotel's name in Hotel Settings is also its name on the Hotels list and the booking site.
            if ($s->key === 'name' && $s->hotel_id && is_string($s->value) && trim($s->value) !== '') {
                \App\Models\Hotel::whereKey($s->hotel_id)->update(['name' => trim($s->value)]);
            }
        });
        static::deleted(fn () => HotelSettings::forget());
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
