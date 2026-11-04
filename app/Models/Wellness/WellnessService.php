<?php

namespace App\Models\Wellness;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A spa treatment: how long it takes and what it costs guests and visitors. */
class WellnessService extends Model
{
    use BelongsToHotel;

    protected $attributes = ['minutes' => 60, 'is_active' => true, 'sort_order' => 0];

    protected $fillable = ['venue_id', 'name', 'description', 'minutes', 'price', 'visitor_price', 'is_active', 'sort_order'];

    protected $casts = [
        'price'         => 'decimal:2',
        'visitor_price' => 'decimal:2',
        'is_active'     => 'boolean',
    ];

    public function venue(): BelongsTo { return $this->belongsTo(WellnessVenue::class, 'venue_id'); }

    public function priceFor(bool $visitor): float
    {
        return (float) ($visitor && $this->visitor_price !== null ? $this->visitor_price : $this->price);
    }
}
