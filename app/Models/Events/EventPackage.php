<?php

namespace App\Models\Events;

use Illuminate\Database\Eloquent\Model;

/** Per-person catering or day-delegate package, e.g. "Full day: tea break, lunch, water". */
class EventPackage extends Model
{
    protected $attributes = ['is_active' => true];

    protected $fillable = ['name', 'price_per_person', 'description', 'is_active'];

    protected $casts = ['price_per_person' => 'decimal:2', 'is_active' => 'boolean'];
}
