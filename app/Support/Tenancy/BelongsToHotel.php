<?php

namespace App\Support\Tenancy;

use App\Models\Hotel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that belongs to one hotel: it is only read for the current hotel, and new ones are given it.
 * withoutGlobalScope('hotel') (or CurrentHotel::across) reads every hotel's.
 */
trait BelongsToHotel
{
    public static function bootBelongsToHotel(): void
    {
        static::addGlobalScope('hotel', function (Builder $q) {
            if ($id = CurrentHotel::get()->id()) {
                $q->where($q->getModel()->qualifyColumn('hotel_id'), $id);
            }
        });
        static::creating(function ($model) {
            if (empty($model->hotel_id) && ($id = CurrentHotel::get()->id())) {
                $model->hotel_id = $id;
            }
        });
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
