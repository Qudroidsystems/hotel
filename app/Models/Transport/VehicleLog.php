<?php

namespace App\Models\Transport;

use App\Models\User;
use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pre-shift check, a fill-up, a service or a problem with one of the hotel's cars. */
class VehicleLog extends Model
{
    use BelongsToHotel;

    public const KINDS = ['check' => 'Pre-shift check', 'fuel' => 'Fuel', 'service' => 'Service / repair', 'issue' => 'Problem reported'];

    /** What a driver looks at before a shift. */
    public const CHECKS = [
        'tyres'     => 'Tyres and spare',
        'lights'    => 'Lights and indicators',
        'brakes'    => 'Brakes',
        'fluids'    => 'Oil, water, no leaks',
        'clean'     => 'Clean inside and out',
        'papers'    => 'Papers in the car',
        'safety'    => 'First-aid kit, triangle, extinguisher',
        'body'      => 'No new dents or scratches',
    ];

    protected $fillable = ['vehicle_id', 'driver_id', 'user_id', 'kind', 'odometer_km', 'litres', 'cost', 'checklist', 'ok', 'notes', 'photo_path', 'offline_id'];

    protected $casts = ['checklist' => 'array', 'ok' => 'boolean', 'litres' => 'float', 'cost' => 'decimal:2', 'odometer_km' => 'integer'];

    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function driver(): BelongsTo { return $this->belongsTo(Driver::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst($this->kind);
    }

    /** The items that failed, in words. */
    public function getFailedAttribute(): array
    {
        return collect((array) $this->checklist)->filter(fn ($ok) => ! $ok)->keys()->map(fn ($k) => self::CHECKS[$k] ?? $k)->values()->all();
    }
}
