<?php

namespace App\Models\Network;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reading of an internet line (speed, delay, signal, data moved). */
class NetworkSample extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $fillable = ['link_id', 'sampled_at', 'status', 'signal', 'down_mbps', 'up_mbps', 'latency_ms', 'packet_loss', 'obstruction_pct', 'used_mb'];

    protected $casts = [
        'sampled_at'      => 'datetime',
        'down_mbps'       => 'float',
        'up_mbps'         => 'float',
        'packet_loss'     => 'float',
        'obstruction_pct' => 'float',
        'used_mb'         => 'float',
    ];

    public function link(): BelongsTo { return $this->belongsTo(NetworkLink::class, 'link_id'); }
}
