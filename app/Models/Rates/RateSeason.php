<?php

namespace App\Models\Rates;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A date range when room rates go up (peak) or down (low season). */
class RateSeason extends Model
{
    use BelongsToHotel;

    protected $attributes = ['change_percent' => 0, 'change_amount' => 0, 'priority' => 0, 'color' => '#b7832f', 'is_active' => true];

    protected $fillable = ['name', 'starts_on', 'ends_on', 'change_percent', 'change_amount', 'room_type_ids', 'min_nights', 'priority', 'color', 'is_active'];

    protected $casts = [
        'starts_on'      => 'date',
        'ends_on'        => 'date',
        'change_percent' => 'decimal:2',
        'change_amount'  => 'decimal:2',
        'room_type_ids'  => 'array',
        'is_active'      => 'boolean',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function appliesToType(int $roomTypeId): bool
    {
        return empty($this->room_type_ids) || in_array($roomTypeId, array_map('intval', $this->room_type_ids), true);
    }

    public function covers(string $date): bool
    {
        return $this->starts_on->toDateString() <= $date && $this->ends_on->toDateString() >= $date;
    }

    /** "+25%", "−10% · − ₦5,000" */
    public function getChangeLabelAttribute(): string
    {
        $sym = config('hotel.currency_symbol');
        $bits = [];
        if ((float) $this->change_percent != 0) {
            $bits[] = ((float) $this->change_percent > 0 ? '+' : '−') . rtrim(rtrim(number_format(abs((float) $this->change_percent), 2), '0'), '.') . '%';
        }
        if ((float) $this->change_amount != 0) {
            $bits[] = ((float) $this->change_amount > 0 ? '+ ' : '− ') . $sym . number_format(abs((float) $this->change_amount));
        }

        return $bits ? implode(' · ', $bits) : 'No price change';
    }
}
