<?php

namespace App\Models\Channels;

use App\Models\Rates\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelRatePlan extends Model
{
    protected $fillable = ['room_type_id', 'rate_plan_id', 'external_id', 'markup_percent'];

    protected $casts = ['markup_percent' => 'decimal:2'];

    protected $attributes = ['markup_percent' => 0];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function getLabelAttribute(): string
    {
        return $this->ratePlan?->name ?? 'Standard rate';
    }
}
