<?php

namespace App\Services\Keys\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * TTLock (the cloud behind many smart hotel locks sold in Nigeria: Sciener, TTHotel and other brands). Needs an
 * Open Platform app (client id and secret) and the hotel's TTLock account; locks open remotely through a TTLock
 * gateway (G2/G3) near the doors. Codes are "period" passcodes, worked out by the lock itself, so they reach the
 * lock even without a gateway.
 */
class TtlockProvider implements LockProvider
{
    public function key(): string { return 'ttlock'; }
    public function label(): string { return 'TTLock (smart locks with a gateway)'; }

    public function ready(): bool
    {
        return filled($this->c('client_id')) && filled($this->c('client_secret')) && filled($this->c('username')) && filled($this->c('password'));
    }

    public function makesPins(): bool { return true; }
    public function opensDoors(): bool { return (bool) config('hotel.keys.gateway', true); }

    public function issuePin(string $lockId, Carbon $from, Carbon $until, string $name): array
    {
        $r = $this->call('/v3/keyboardPwd/get', [
            'lockId' => $lockId, 'keyboardPwdVersion' => 4, 'keyboardPwdType' => 3,   // 3 = works for a period
            'keyboardPwdName' => mb_substr($name, 0, 40),
            'startDate' => $this->ms($from->copy()->startOfHour()), 'endDate' => $this->ms($until->copy()->addHour()->startOfHour()),
        ]);
        if (empty($r['keyboardPwd'])) {
            throw new \RuntimeException('TTLock didn’t give a code for this lock.');
        }

        return ['pin' => (string) $r['keyboardPwd'], 'ref' => isset($r['keyboardPwdId']) ? (string) $r['keyboardPwdId'] : null];
    }

    public function changePin(string $lockId, string $ref, string $pin, Carbon $from, Carbon $until): bool
    {
        $this->call('/v3/keyboardPwd/change', ['lockId' => $lockId, 'keyboardPwdId' => $ref, 'changeType' => 2,
            'startDate' => $this->ms($from->copy()->startOfHour()), 'endDate' => $this->ms($until->copy()->addHour()->startOfHour())]);

        return true;
    }

    public function revokePin(string $lockId, string $ref): void
    {
        $this->call('/v3/keyboardPwd/delete', ['lockId' => $lockId, 'keyboardPwdId' => $ref, 'deleteType' => 2]);   // 2 = through the gateway
    }

    public function unlock(string $lockId): void
    {
        $this->call('/v3/lock/unlock', ['lockId' => $lockId]);
    }

    public function records(string $lockId, Carbon $since): array
    {
        $r = $this->call('/v3/lockRecord/list', ['lockId' => $lockId, 'startDate' => $this->ms($since), 'endDate' => $this->ms(now()), 'pageNo' => 1, 'pageSize' => 100]);
        $kinds = [4 => 'pin', 7 => 'card', 8 => 'card', 1 => 'phone', 12 => 'remote', 28 => 'remote', 11 => 'key'];

        return collect($r['list'] ?? [])->map(fn ($x) => [
            'ref' => (string) ($x['recordId'] ?? md5(json_encode($x))),
            'at' => Carbon::createFromTimestampMs((int) ($x['lockDate'] ?? $x['serverDate'] ?? 0)),
            'kind' => $kinds[(int) ($x['recordType'] ?? 0)] ?? 'other',
            'who' => $x['username'] ?? null,
            'ok' => (int) ($x['success'] ?? 1) === 1,
            'note' => isset($x['keyboardPwd']) && $x['keyboardPwd'] !== '' ? 'Code ending ' . substr((string) $x['keyboardPwd'], -2) : null,
        ])->all();
    }

    // ── HTTP ──────────────────────────────────────────────────────────

    private function call(string $path, array $params): array
    {
        $res = Http::asForm()->timeout(15)->post($this->base() . $path, $params + [
            'clientId' => $this->c('client_id'), 'accessToken' => $this->token(), 'date' => $this->ms(now()),
        ]);
        $j = $res->json() ?? [];
        if (! $res->ok() || (isset($j['errcode']) && (int) $j['errcode'] !== 0)) {
            if ((int) ($j['errcode'] ?? 0) === 10004 || (int) ($j['errcode'] ?? 0) === 10003) {
                Cache::forget($this->tokenKey());   // token expired: the next call signs in again
            }
            throw new \RuntimeException('TTLock: ' . ($j['errmsg'] ?? $j['description'] ?? ('HTTP ' . $res->status())));
        }

        return $j;
    }

    private function token(): string
    {
        return Cache::remember($this->tokenKey(), now()->addDays(60), function () {
            $res = Http::asForm()->timeout(15)->post($this->base() . '/oauth2/token', [
                'clientId' => $this->c('client_id'), 'clientSecret' => $this->c('client_secret'),
                'username' => $this->c('username'), 'password' => md5((string) $this->c('password')),
            ]);
            $t = $res->json('access_token');
            if (! $res->ok() || ! $t) {
                throw new \RuntimeException('TTLock sign-in failed: ' . ($res->json('errmsg') ?? $res->json('description') ?? 'check the account in Hotel Settings → Room Keys'));
            }

            return (string) $t;
        });
    }

    private function tokenKey(): string
    {
        return 'ttlock-token:' . md5($this->c('client_id') . '|' . $this->c('username'));
    }

    private function base(): string
    {
        return rtrim((string) ($this->c('api') ?: 'https://euapi.ttlock.com'), '/');
    }

    private function c(string $k): mixed
    {
        return config("services.ttlock.{$k}");
    }

    private function ms(Carbon $t): int
    {
        return (int) ($t->getTimestamp() * 1000);
    }
}
