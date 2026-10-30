<?php

namespace App\Services\Network\Gateways;

use App\Models\Network\NetworkLink;

/**
 * Made-up but believable readings, so the pages can be tried before a router is
 * connected. Starlink-like numbers that follow the time of day, with a short
 * outage now and then and some sky obstruction on one dish.
 */
class DemoGateway implements Gateway
{
    public function label(): string { return 'Demo data'; }

    public function live(): bool { return false; }

    public function readLink(NetworkLink $link): array
    {
        $r = $this->reading($link, now());
        mt_srand();   // back to real randomness for the rest of the request

        return $r;
    }

    /** The reading a line would give at a moment (also used to fill in demo history). */
    public function reading(NetworkLink $link, \DateTimeInterface $at): array
    {
        $minute = (int) floor($at->getTimestamp() / 60);
        $hour = (int) $at->format('G');
        mt_srand(crc32($link->id . '|' . $minute));

        // Busy evenings, quiet nights.
        $busy = [0.25, 0.2, 0.15, 0.12, 0.12, 0.18, 0.3, 0.45, 0.55, 0.55, 0.5, 0.5, 0.55, 0.55, 0.5, 0.5, 0.55, 0.65, 0.8, 0.9, 0.95, 0.85, 0.6, 0.4][$hour];
        if ($link->role === 'backup') {
            $busy *= 0.15;
        }

        // A short drop every few hours, at a different time for each line.
        $cycle = ($minute + $link->id * 97) % 360;
        if ($link->provider === 'starlink' && $cycle < 2) {
            return ['status' => 'offline', 'signal' => 0, 'down_mbps' => 0.0, 'up_mbps' => 0.0, 'latency_ms' => null,
                'packet_loss' => 100.0, 'obstruction_pct' => $this->obstruction($link), 'used_mb' => 0.0, 'message' => 'No signal from the dish (demo outage).'];
        }

        [$down, $up, $lat] = match ($link->provider) {
            'starlink' => [mt_rand(90, 230), mt_rand(9, 28), mt_rand(24, 55)],
            'fibre'    => [mt_rand(80, 100), mt_rand(40, 50), mt_rand(6, 14)],
            'lte'      => [mt_rand(12, 45), mt_rand(3, 12), mt_rand(40, 90)],
            default    => [mt_rand(20, 60), mt_rand(5, 20), mt_rand(30, 80)],
        };
        $obstruction = $this->obstruction($link);
        $loss = round(mt_rand(0, 40) / 100 + $obstruction / 4, 2);
        // Stormy patch: a weaker spell for an hour or so.
        $weak = $cycle >= 120 && $cycle < 150;
        if ($weak) {
            $down = (int) ($down * 0.35);
            $lat += mt_rand(60, 140);
            $loss += mt_rand(2, 5);
        }
        $signal = (int) max(5, min(100, 100 - $obstruction * 6 - ($lat - 25) / 2 - $loss * 4));
        $status = $loss >= 5 || $signal < 45 ? 'degraded' : 'online';

        return [
            'status'          => $status,
            'signal'          => $signal,
            'down_mbps'       => round($down * (0.6 + $busy * 0.4), 1),
            'up_mbps'         => round($up * (0.6 + $busy * 0.4), 1),
            'latency_ms'      => $lat,
            'packet_loss'     => $loss,
            'obstruction_pct' => $obstruction,
            // Data moved in a minute: capacity × how busy × 60 s ÷ 8 bits, in MB.
            'used_mb'         => round($down * $busy * 0.05 * 60 / 8, 1),
            'message'         => $weak ? 'Slower than usual: rain fade or heavy use (demo).' : null,
        ];
    }

    public function readPasses(iterable $passes): array
    {
        $out = [];
        $minute = (int) floor(now()->getTimestamp() / 60);
        foreach ($passes as $p) {
            mt_srand(crc32('p' . $p->id . '|' . $minute));
            $online = mt_rand(0, max(1, (int) $p->devices));
            $out[$p->id] = ['used_mb' => $online ? round(mt_rand(5, 120) / 10 * $online, 1) : 0.0, 'devices_online' => $online];
        }
        mt_srand();

        return $out;
    }

    public function pushPass($pass): void {}

    public function removePass($pass): void {}

    private function obstruction(NetworkLink $link): float
    {
        return $link->provider === 'starlink' ? ($link->id % 3 === 2 ? 2.4 : 0.3) : 0.0;
    }
}
