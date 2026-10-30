<?php

namespace App\Models\Network;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A guest Wi-Fi package: speed, number of devices, daily data and price per day. */
class NetworkPlan extends Model
{
    use BelongsToHotel;

    protected $fillable = ['name', 'description', 'down_mbps', 'up_mbps', 'devices', 'data_cap_mb', 'price', 'is_default', 'is_active', 'sort_order'];

    protected $casts = [
        'down_mbps'  => 'float',
        'up_mbps'    => 'float',
        'price'      => 'float',
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function passes(): HasMany { return $this->hasMany(NetworkPass::class, 'plan_id'); }

    public function scopeOrdered($q) { return $q->orderBy('sort_order')->orderBy('price')->orderBy('name'); }
    public function scopeActive($q) { return $q->where('is_active', true); }

    public function getSpeedLabelAttribute(): string
    {
        return self::mbps($this->down_mbps) . ' down / ' . self::mbps($this->up_mbps) . ' up';
    }

    public function getCapLabelAttribute(): string
    {
        return $this->data_cap_mb ? self::size($this->data_cap_mb) . ' a day' : 'Unlimited data';
    }

    public static function mbps(?float $v): string
    {
        return $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . ' Mbps';
    }

    public static function size(?float $mb): string
    {
        $mb = (float) $mb;

        return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.') . ' GB' : number_format($mb) . ' MB';
    }
}
