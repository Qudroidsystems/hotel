<?php

namespace App\Models\Rates;

use App\Support\Tenancy\BelongsToHotel;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A way of selling a room: the night's rate (after seasons and the rate calendar) less a discount, plus an
 * extra (e.g. breakfast), or a fixed price per room type; with optional stay rules. No plan = the standard rate.
 */
class RatePlan extends Model
{
    use BelongsToHotel;

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'];

    protected $attributes = [
        'discount_percent' => 0, 'nightly_extra' => 0, 'online' => false, 'front_desk' => true,
        'non_refundable' => false, 'is_active' => true, 'sort_order' => 0,
    ];

    protected $fillable = [
        'name', 'code', 'description', 'inclusions', 'discount_percent', 'nightly_extra', 'fixed_prices',
        'min_nights', 'max_nights', 'min_advance_days', 'valid_from', 'valid_to', 'arrival_days', 'room_type_ids',
        'promo_code', 'online', 'front_desk', 'non_refundable', 'is_active', 'sort_order', 'created_by', 'company_id',
    ];

    protected $casts = [
        'discount_percent' => 'decimal:2',
        'nightly_extra'    => 'decimal:2',
        'fixed_prices'     => 'array',
        'arrival_days'     => 'array',
        'room_type_ids'    => 'array',
        'valid_from'       => 'date',
        'valid_to'         => 'date',
        'online'           => 'boolean',
        'front_desk'       => 'boolean',
        'non_refundable'   => 'boolean',
        'is_active'        => 'boolean',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function company()
    {
        return $this->belongsTo(\App\Models\Company::class);
    }

    public function appliesToType(int $roomTypeId): bool
    {
        return empty($this->room_type_ids) || in_array($roomTypeId, array_map('intval', $this->room_type_ids), true);
    }

    /** "15% off · + ₦8,000/night · min 2 nights" */
    public function getSummaryAttribute(): string
    {
        $sym = config('hotel.currency_symbol');
        $bits = [];
        if (! empty($this->fixed_prices)) {
            $bits[] = 'Set prices';
        }
        if ((float) $this->discount_percent > 0) {
            $bits[] = rtrim(rtrim(number_format((float) $this->discount_percent, 2), '0'), '.') . '% off';
        }
        if ((float) $this->nightly_extra != 0) {
            $bits[] = ((float) $this->nightly_extra > 0 ? '+ ' : '− ') . $sym . number_format(abs((float) $this->nightly_extra)) . '/night';
        }
        if ($this->min_nights) {
            $bits[] = 'min ' . $this->min_nights . ' night' . ($this->min_nights > 1 ? 's' : '');
        }
        if ($this->min_advance_days) {
            $bits[] = 'book ' . $this->min_advance_days . '+ days ahead';
        }
        if (! empty($this->arrival_days)) {
            $bits[] = 'arrive ' . collect(self::DAYS)->only(array_map('intval', $this->arrival_days))->implode('/');
        }

        return $bits ? implode(' · ', $bits) : 'Standard rate';
    }

    /** Where it can be sold, for lists. */
    public function getChannelsLabelAttribute(): string
    {
        return collect([
            $this->front_desk ? 'Front desk' : null,
            $this->online ? ($this->promo_code ? 'Online with code ' . $this->promo_code : 'Online') : null,
        ])->filter()->implode(' · ') ?: 'Not on sale';
    }
}
