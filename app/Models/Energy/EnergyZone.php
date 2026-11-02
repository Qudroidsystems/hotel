<?php

namespace App\Models\Energy;

use App\Models\Events\Hall;
use App\Models\Floor;
use App\Models\Pos\PosOutlet;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A place other than a guest room that uses power: lobby, a floor's corridor, a hall, the kitchen, the pool … */
class EnergyZone extends Model
{
    use BelongsToHotel;

    public const KINDS = [
        'common' => 'Lobby / public area', 'corridor' => 'Corridor (a floor)', 'hall' => 'Hall', 'outlet' => 'Restaurant / bar',
        'service' => 'Kitchen, laundry, plant', 'outdoor' => 'Outdoor, pool, garden', 'office' => 'Office', 'other' => 'Other',
    ];

    protected $fillable = ['name', 'kind', 'floor_id', 'hall_id', 'outlet_id', 'watts', 'low_watts', 'opens', 'closes', 'always_on', 'notes', 'is_active', 'sort_order'];

    protected $casts = ['always_on' => 'boolean', 'is_active' => 'boolean', 'watts' => 'integer', 'low_watts' => 'integer'];

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function hall()
    {
        return $this->belongsTo(Hall::class);
    }

    public function outlet()
    {
        return $this->belongsTo(PosOutlet::class, 'outlet_id');
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst($this->kind);
    }

    public function getHoursLabelAttribute(): ?string
    {
        return $this->opens && $this->closes ? date('g:i A', strtotime($this->opens)) . ' – ' . date('g:i A', strtotime($this->closes)) : null;
    }
}
