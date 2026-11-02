<?php

namespace App\Models\Energy;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Where power comes from: the grid, a generator, solar or an inverter bank. */
class EnergySource extends Model
{
    use BelongsToHotel;

    public const KINDS = ['grid' => 'Grid (public power)', 'generator' => 'Generator', 'solar' => 'Solar', 'inverter' => 'Inverter / batteries'];

    protected $fillable = ['name', 'kind', 'capacity_kw', 'litres_per_hour', 'tank_litres', 'fuel_litres', 'fuel_at', 'running', 'running_since', 'is_active', 'device_id'];

    protected $casts = [
        'running' => 'boolean', 'is_active' => 'boolean', 'capacity_kw' => 'integer', 'litres_per_hour' => 'float',
        'tank_litres' => 'integer', 'fuel_litres' => 'float', 'fuel_at' => 'datetime', 'running_since' => 'datetime',
    ];

    /** Its switch on the equipment board (a hub can report it running). */
    public function device()
    {
        return $this->belongsTo(\App\Models\Facility\FacilityDevice::class, 'device_id');
    }

    public function burnsFuel(): bool
    {
        return $this->kind === 'generator';
    }

    /**
     * Diesel per hour at a load (kW). Generators burn a fixed part even lightly loaded, so a big set carrying a
     * small load wastes fuel: roughly 30% of the full-load use at idle, rising in a straight line to 100%.
     */
    public function litresAt(float $kw): float
    {
        if (! $this->burnsFuel() || $this->capacity_kw <= 0) {
            return 0.0;
        }
        $full = $this->litres_per_hour ?: $this->capacity_kw * 0.27;   // about 0.27 L per kWh at full load
        $f = min(1.0, max(0.0, $kw / $this->capacity_kw));

        return round($full * (0.3 + 0.7 * $f), 2);
    }

    /** Hours left in the tank at a load. */
    public function hoursLeft(float $kw): ?float
    {
        $lph = $this->litresAt($kw);

        return $this->fuel_litres !== null && $lph > 0 ? round($this->fuel_litres / $lph, 1) : null;
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst($this->kind);
    }
}
