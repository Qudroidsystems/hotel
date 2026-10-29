<?php

namespace App\Models\Website;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** The website's settings and texts for one hotel (one row). See App\Support\Website. */
class WebsiteSetting extends Model
{
    use BelongsToHotel;

    protected $fillable = ['data', 'updated_by'];

    protected $casts = ['data' => 'array'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\Website::forget());
    }
}
