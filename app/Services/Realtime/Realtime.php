<?php

namespace App\Services\Realtime;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real-time pushes to browsers and the apps over the Pusher protocol, spoken
 * by Laravel Reverb (on this server) and by hosted services alike. Sent
 * straight to the server's HTTP API, so nothing extra is needed in PHP.
 *
 * Everything that is pushed is also stored, and the apps fall back to asking
 * every few seconds whenever the socket is down — a push is a speed-up, never
 * the only way a message arrives.
 */
class Realtime
{
    public function enabled(): bool
    {
        $c = config('services.realtime');

        return (bool) ($c['enabled'] ?? false) && filled($c['app_id'] ?? null) && filled($c['key'] ?? null) && filled($c['secret'] ?? null);
    }

    /** What clients need to connect (never the secret). */
    public function clientConfig(): array
    {
        $c = config('services.realtime');
        if (! $this->enabled()) {
            return ['enabled' => false];
        }
        $host = $c['client_host'] ?: parse_url((string) config('app.url'), PHP_URL_HOST);
        $scheme = $c['client_scheme'] ?: 'https';
        $port = (int) ($c['client_port'] ?: ($scheme === 'https' ? 443 : 80));

        return [
            'enabled' => true, 'key' => $c['key'],
            'url' => ($scheme === 'https' ? 'wss' : 'ws') . "://{$host}" . (in_array($port, [80, 443], true) ? '' : ":{$port}") . "/app/{$c['key']}?protocol=7&client=smart-hotel&version=1.0",
            'auth' => url('/api/v1/realtime/auth'),
        ];
    }

    /** Send an event to channels, after the response has gone (a slow socket server never slows a page). */
    public function send(array|string $channels, string $event, array $data): void
    {
        if (! $this->enabled()) {
            return;
        }
        $channels = array_values(array_unique((array) $channels));
        $fire = function () use ($channels, $event, $data) {
            foreach (array_chunk($channels, 100) as $chunk) {
                $this->publish($chunk, $event, $data);
            }
        };
        function_exists('defer') ? defer($fire) : $fire();
    }

    public function publish(array $channels, string $event, array $data): bool
    {
        $c = config('services.realtime');
        $body = json_encode(['name' => $event, 'channels' => $channels, 'data' => json_encode($data)]);
        $path = "/apps/{$c['app_id']}/events";
        $query = ['auth_key' => $c['key'], 'auth_timestamp' => (string) time(), 'auth_version' => '1.0', 'body_md5' => md5($body)];
        ksort($query);
        $qs = http_build_query($query);
        $query['auth_signature'] = hash_hmac('sha256', "POST\n{$path}\n{$qs}", (string) $c['secret']);
        try {
            $res = Http::timeout(3)->withBody($body, 'application/json')
                ->post("{$c['scheme']}://{$c['host']}:{$c['port']}{$path}?" . http_build_query($query));

            return $res->successful();
        } catch (Throwable $e) {
            Log::notice('Realtime push failed (clients will poll): ' . $e->getMessage());

            return false;
        }
    }

    /** Sign a private channel for a socket (the Pusher protocol's auth). */
    public function authorize(string $socketId, string $channel): array
    {
        $c = config('services.realtime');

        return ['auth' => $c['key'] . ':' . hash_hmac('sha256', "{$socketId}:{$channel}", (string) $c['secret'])];
    }

    // ── Channel names ─────────────────────────────────────────────────

    /** A guest's stay (they prove the booking's private token). */
    public static function stay(string $token): string
    {
        return 'private-stay-' . substr(hash('sha256', $token), 0, 32);
    }

    public static function section(int $id): string
    {
        return 'private-section-' . $id;
    }

    public static function staff(int $userId): string
    {
        return 'private-staff-' . $userId;
    }
}
