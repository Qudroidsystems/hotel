<?php

namespace App\Models\Events;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Hall extends Model
{
    use BelongsToHotel;

    /** Room layouts and the capacity column for each. */
    public const SETUPS = [
        'theatre'   => ['Theatre (rows of chairs)', 'capacity_theatre'],
        'banquet'   => ['Banquet (round tables)', 'capacity_banquet'],
        'classroom' => ['Classroom (tables facing front)', 'capacity_classroom'],
        'boardroom' => ['Boardroom (one table)', 'capacity_boardroom'],
        'cocktail'  => ['Cocktail (standing)', 'capacity_theatre'],
    ];

    protected $attributes = ['hourly_rate' => 0, 'half_day_rate' => 0, 'full_day_rate' => 0, 'color' => '#0f766e', 'is_active' => true, 'sort_order' => 0];

    protected $fillable = ['name', 'capacity_theatre', 'capacity_banquet', 'capacity_classroom', 'capacity_boardroom', 'hourly_rate', 'half_day_rate', 'full_day_rate', 'description', 'color', 'is_active', 'sort_order'];

    protected $casts = ['hourly_rate' => 'decimal:2', 'half_day_rate' => 'decimal:2', 'full_day_rate' => 'decimal:2', 'is_active' => 'boolean'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function capacityFor(?string $setup): ?int
    {
        $col = self::SETUPS[$setup][1] ?? null;

        return $col ? $this->{$col} : null;
    }

    /** "Theatre 200 · Banquet 120" */
    public function getCapacityLabelAttribute(): string
    {
        return collect(['Theatre' => $this->capacity_theatre, 'Banquet' => $this->capacity_banquet, 'Classroom' => $this->capacity_classroom, 'Boardroom' => $this->capacity_boardroom])
            ->filter()->map(fn ($n, $l) => "{$l} {$n}")->implode(' · ') ?: '—';
    }
}
