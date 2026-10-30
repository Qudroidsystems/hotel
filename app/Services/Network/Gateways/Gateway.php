<?php

namespace App\Services\Network\Gateways;

use App\Models\Network\NetworkLink;
use App\Models\Network\NetworkPass;

/**
 * The box the internet lines plug into (MikroTik, UniFi, Peplink, pfSense…), or
 * the dishes themselves. Each make gets its own class; config hotel.network.driver
 * picks one.
 */
interface Gateway
{
    /** Short name shown on the page, e.g. "Demo data". */
    public function label(): string;

    /** Whether the readings are real (false for demo data or when nothing is connected). */
    public function live(): bool;

    /**
     * Read one internet line now.
     *
     * @return array{status: string, signal: ?int, down_mbps: ?float, up_mbps: ?float, latency_ms: ?int,
     *               packet_loss: ?float, obstruction_pct: ?float, used_mb: float, message: ?string}
     */
    public function readLink(NetworkLink $link): array;

    /**
     * Data used and devices online for the given Wi-Fi codes since they were last read.
     *
     * @param  iterable<NetworkPass>  $passes
     * @return array<int, array{used_mb: float, devices_online: int}>  keyed by pass id
     */
    public function readPasses(iterable $passes): array;

    /** Create or update the code on the guest network, with its speed limit and device count. */
    public function pushPass(NetworkPass $pass): void;

    /** Take the code off the guest network (and disconnect its devices). */
    public function removePass(NetworkPass $pass): void;
}
