<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Floor extends Model
{
    protected $fillable = ['building_id', 'name', 'level'];

    protected $casts = ['level' => 'integer'];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('number');
    }

    /** Suggested name for a floor level: 0 → "Ground Floor", 2 → "Floor 2". */
    public static function defaultName(int $level): string
    {
        return match (true) {
            $level === 0 => 'Ground Floor',
            $level < 0   => 'Basement ' . abs($level),
            default      => 'Floor ' . $level,
        };
    }
}
