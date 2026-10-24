<?php

namespace App\Models\It;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A piece of IT equipment: router, switch, access point, server… (spec §11.1 device inventory). */
class ItAsset extends Model
{
    public const TYPES = [
        'router'       => ['Router', 'ri-router-line'],
        'switch'       => ['Network switch', 'ri-git-merge-line'],
        'access_point' => ['Wi-Fi access point', 'ri-wifi-line'],
        'firewall'     => ['Firewall', 'ri-shield-keyhole-line'],
        'internet'     => ['Internet link', 'ri-global-line'],
        'server'       => ['Server', 'ri-server-line'],
        'nas'          => ['Storage / NAS', 'ri-hard-drive-2-line'],
        'ups'          => ['UPS / power', 'ri-battery-charge-line'],
        'cctv'         => ['CCTV / NVR', 'ri-camera-lens-line'],
        'pos_terminal' => ['POS terminal', 'ri-bank-card-line'],
        'computer'     => ['Computer', 'ri-computer-line'],
        'printer'      => ['Printer', 'ri-printer-line'],
        'phone'        => ['Phone / intercom', 'ri-phone-line'],
        'other'        => ['Other', 'ri-device-line'],
    ];

    protected $fillable = ['name', 'type', 'ip_address', 'mac_address', 'location', 'make_model', 'serial_number', 'firmware', 'purchased_on', 'warranty_until', 'notes', 'is_active'];

    protected $casts = ['purchased_on' => 'date', 'warranty_until' => 'date', 'is_active' => 'boolean'];

    public function monitors(): HasMany { return $this->hasMany(ItMonitor::class, 'asset_id'); }
    public function tickets(): HasMany { return $this->hasMany(ItTicket::class, 'asset_id'); }

    public function getTypeLabelAttribute(): string { return self::TYPES[$this->type][0] ?? ucfirst($this->type); }
    public function getTypeIconAttribute(): string { return self::TYPES[$this->type][1] ?? 'ri-device-line'; }

    public function warrantyEnded(): bool { return $this->warranty_until && $this->warranty_until->isPast(); }
}
