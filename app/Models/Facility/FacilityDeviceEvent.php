<?php

namespace App\Models\Facility;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A change of a device's state (who or what switched it, and when). */
class FacilityDeviceEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['device_id', 'from_state', 'state', 'reading', 'source', 'user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function device(): BelongsTo { return $this->belongsTo(FacilityDevice::class, 'device_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
