<?php

namespace App\Models\Events;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class EventItem extends Model
{
    use BelongsToHotel;

    public const KINDS = ['catering' => 'Catering', 'equipment' => 'Equipment', 'service' => 'Service', 'other' => 'Other'];

    protected $fillable = ['event_id', 'kind', 'description', 'quantity', 'unit_price', 'amount', 'created_by'];

    protected $casts = ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
