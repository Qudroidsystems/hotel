<?php

namespace App\Models\Transport;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One of the hotel's cars. */
class Vehicle extends Model
{
    use BelongsToHotel;

    public const KINDS = ['sedan' => 'Saloon car', 'suv' => 'SUV', 'van' => 'Van / minibus', 'bus' => 'Bus'];

    protected $fillable = ['name', 'plate', 'color', 'kind', 'seats', 'status', 'notes', 'is_active',
        'odometer_km', 'insurance_expires', 'roadworthy_expires', 'service_every_km', 'last_service_km', 'service_due_on'];

    protected $casts = [
        'is_active' => 'boolean', 'seats' => 'integer', 'odometer_km' => 'integer', 'service_every_km' => 'integer', 'last_service_km' => 'integer',
        'insurance_expires' => 'date', 'roadworthy_expires' => 'date', 'service_due_on' => 'date', 'checked_at' => 'datetime',
    ];

    public function logs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VehicleLog::class)->latest('id');
    }

    /** Papers that have run out: ['Insurance', …]. A car with any may not take guests. */
    public function expiredPapers(): array
    {
        return array_values(array_filter([
            $this->insurance_expires && $this->insurance_expires->lt(today()) ? 'Insurance' : null,
            $this->roadworthy_expires && $this->roadworthy_expires->lt(today()) ? 'Roadworthiness' : null,
        ]));
    }

    /** Why it is due a service ("at 45,000 km", "by 3 Mar"), or null. */
    public function serviceDue(int $warnKm = 500, int $warnDays = 7): ?string
    {
        if ($this->service_every_km && $this->odometer_km !== null) {
            $next = (int) ($this->last_service_km ?? 0) + $this->service_every_km;
            if ($this->odometer_km >= $next - $warnKm) {
                return ($this->odometer_km >= $next ? 'Service overdue (was due at ' : 'Service due at ') . number_format($next) . ' km' . ($this->odometer_km >= $next ? ')' : '');
            }
        }
        if ($this->service_due_on && $this->service_due_on->lte(today()->addDays($warnDays))) {
            return ($this->service_due_on->lt(today()) ? 'Service overdue since ' : 'Service due by ') . $this->service_due_on->format('j M');
        }

        return null;
    }

    /** Papers running out within $days: ['Insurance ends 3 Mar', …]. */
    public function papersEnding(int $days = 14): array
    {
        $soon = today()->addDays($days);

        return array_values(array_filter([
            $this->insurance_expires && $this->insurance_expires->gte(today()) && $this->insurance_expires->lte($soon) ? 'Insurance ends ' . $this->insurance_expires->format('j M') : null,
            $this->roadworthy_expires && $this->roadworthy_expires->gte(today()) && $this->roadworthy_expires->lte($soon) ? 'Roadworthiness ends ' . $this->roadworthy_expires->format('j M') : null,
        ]));
    }

    public function getLabelAttribute(): string
    {
        return trim(($this->color ? $this->color . ' ' : '') . $this->name) . ' · ' . $this->plate;
    }

    public function scopeUsable($q)
    {
        return $q->where('is_active', true)->where('status', 'available');
    }
}
