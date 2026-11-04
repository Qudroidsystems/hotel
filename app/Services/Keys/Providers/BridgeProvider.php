<?php

namespace App\Services\Keys\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Any other lock system (Saflok, VingCard, Onity, Salto …) through a small bridge program at the hotel that talks
 * to the lock maker's server or encoder. This system POSTs JSON to the bridge, signed with an HMAC-SHA256 of the
 * body in X-Signature:
 *   POST {url}/pins     {lock_id, from, until, name}      → {pin, ref}
 *   PUT  {url}/pins/{ref} {lock_id, pin, from, until}      → {}
 *   DELETE {url}/pins/{ref}?lock_id=…                       → {}
 *   POST {url}/unlock   {lock_id}                          → {}
 *   GET  {url}/records?lock_id=…&since=ISO                 → [{ref, at, kind, who, ok, note}]
 * Any non-2xx answer with {"message": "…"} is shown to staff.
 */
class BridgeProvider implements LockProvider
{
    public function key(): string { return 'bridge'; }
    public function label(): string { return 'Another lock system, through a bridge at the hotel'; }
    public function ready(): bool { return filled(config('services.lock_bridge.url')) && filled(config('services.lock_bridge.secret')); }
    public function makesPins(): bool { return (bool) config('hotel.keys.bridge_pins', true); }
    public function opensDoors(): bool { return (bool) config('hotel.keys.gateway', true); }

    public function issuePin(string $lockId, Carbon $from, Carbon $until, string $name): array
    {
        $r = $this->send('post', '/pins', ['lock_id' => $lockId, 'from' => $from->toIso8601String(), 'until' => $until->toIso8601String(), 'name' => $name]);
        if (empty($r['pin'])) {
            throw new \RuntimeException('The lock bridge didn’t give a code.');
        }

        return ['pin' => (string) $r['pin'], 'ref' => isset($r['ref']) ? (string) $r['ref'] : null];
    }

    public function changePin(string $lockId, string $ref, string $pin, Carbon $from, Carbon $until): bool
    {
        $this->send('put', '/pins/' . rawurlencode($ref), ['lock_id' => $lockId, 'pin' => $pin, 'from' => $from->toIso8601String(), 'until' => $until->toIso8601String()]);

        return true;
    }

    public function revokePin(string $lockId, string $ref): void
    {
        $this->send('delete', '/pins/' . rawurlencode($ref) . '?lock_id=' . rawurlencode($lockId), []);
    }

    public function unlock(string $lockId): void
    {
        $this->send('post', '/unlock', ['lock_id' => $lockId]);
    }

    public function records(string $lockId, Carbon $since): array
    {
        $r = $this->send('get', '/records?lock_id=' . rawurlencode($lockId) . '&since=' . rawurlencode($since->toIso8601String()), []);

        return collect($r)->filter(fn ($x) => is_array($x) && isset($x['ref'], $x['at']))->map(fn ($x) => [
            'ref' => (string) $x['ref'], 'at' => Carbon::parse($x['at']), 'kind' => (string) ($x['kind'] ?? 'other'),
            'who' => $x['who'] ?? null, 'ok' => (bool) ($x['ok'] ?? true), 'note' => $x['note'] ?? null,
        ])->values()->all();
    }

    private function send(string $method, string $path, array $body): array
    {
        $json = $method === 'get' || $method === 'delete' ? '' : json_encode($body);
        $req = Http::timeout(15)->withHeaders(['X-Signature' => hash_hmac('sha256', $json, (string) config('services.lock_bridge.secret')), 'Accept' => 'application/json']);
        $url = rtrim((string) config('services.lock_bridge.url'), '/') . $path;
        $res = match ($method) {
            'get'    => $req->get($url),
            'delete' => $req->delete($url),
            'put'    => $req->withBody($json, 'application/json')->put($url),
            default  => $req->withBody($json, 'application/json')->post($url),
        };
        if (! $res->successful()) {
            throw new \RuntimeException('Lock bridge: ' . ($res->json('message') ?? ('HTTP ' . $res->status())));
        }

        return $res->json() ?? [];
    }
}
