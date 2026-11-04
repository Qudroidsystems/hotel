<?php

namespace App\Services\Keys\Providers;

use Illuminate\Support\Carbon;

/**
 * No connected locks: key cards are made on the lock maker's encoder at the desk and recorded here (card number,
 * valid until), so the hotel knows which cards are out, which were returned and which were lost.
 */
class ManualProvider implements LockProvider
{
    public function key(): string { return 'manual'; }
    public function label(): string { return 'Key cards made at the desk (not connected)'; }
    public function ready(): bool { return true; }
    public function makesPins(): bool { return false; }
    public function opensDoors(): bool { return false; }

    public function issuePin(string $lockId, Carbon $from, Carbon $until, string $name): array
    {
        throw new \RuntimeException('Door codes need connected locks (Hotel Settings → Room Keys).');
    }

    public function changePin(string $lockId, string $ref, string $pin, Carbon $from, Carbon $until): bool { return false; }
    public function revokePin(string $lockId, string $ref): void {}

    public function unlock(string $lockId): void
    {
        throw new \RuntimeException('Doors can’t be opened from here without connected locks.');
    }

    public function records(string $lockId, Carbon $since): array { return []; }
}
