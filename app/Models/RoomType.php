<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RoomType extends Model
{
    protected $fillable = [
        'name', 'code', 'description', 'base_rate', 'weekend_rate',
        'max_adults', 'max_children', 'bed_type', 'size_sqm',
        'amenities', 'color', 'is_active',
    ];

    protected $casts = [
        'base_rate'    => 'decimal:2',
        'weekend_rate' => 'decimal:2',
        'amenities'    => 'array',
        'is_active'    => 'boolean',
    ];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RoomTypePhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function coverPhoto(): HasOne
    {
        return $this->hasOne(RoomTypePhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Nightly rate for a given night (Friday and Saturday nights use the weekend rate). */
    public function rateFor(CarbonInterface $night): float
    {
        $isWeekendNight = in_array($night->dayOfWeek, [CarbonInterface::FRIDAY, CarbonInterface::SATURDAY], true);

        return (float) ($isWeekendNight && $this->weekend_rate !== null ? $this->weekend_rate : $this->base_rate);
    }

    public function getCapacityLabelAttribute(): string
    {
        $label = $this->max_adults . ' adult' . ($this->max_adults == 1 ? '' : 's');
        if ($this->max_children) {
            $label .= ', ' . $this->max_children . ' child' . ($this->max_children == 1 ? '' : 'ren');
        }

        return $label;
    }
}
