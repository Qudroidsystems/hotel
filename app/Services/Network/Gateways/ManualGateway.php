<?php

namespace App\Services\Network\Gateways;

use App\Models\Network\NetworkLink;

/** Nothing connected yet: lines are listed by hand and Wi-Fi codes are typed into the router by staff. */
class ManualGateway implements Gateway
{
    public function label(): string { return 'Not connected'; }

    public function live(): bool { return false; }

    public function readLink(NetworkLink $link): array
    {
        return ['status' => 'unknown', 'signal' => null, 'down_mbps' => null, 'up_mbps' => null, 'latency_ms' => null,
            'packet_loss' => null, 'obstruction_pct' => null, 'used_mb' => 0, 'message' => 'No router connected: readings are not available.'];
    }

    public function readPasses(iterable $passes): array { return []; }

    public function pushPass($pass): void {}

    public function removePass($pass): void {}
}
