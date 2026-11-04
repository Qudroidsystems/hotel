<?php

namespace App\Services\Keys\Providers;

use Illuminate\Support\Carbon;

/**
 * A lock maker's system. Every call talks to the real locks (through the maker's cloud or a bridge at the hotel)
 * and throws \RuntimeException with a readable message when it can't.
 */
interface LockProvider
{
    public function key(): string;

    public function label(): string;

    /** Ready to use (set up, keys present). */
    public function ready(): bool;

    /** Door codes can be made for a lock, valid from → until. */
    public function makesPins(): bool;

    /** A door can be opened from here (the phone key, staff remote unlock). */
    public function opensDoors(): bool;

    /** @return array{pin: string, ref: ?string} */
    public function issuePin(string $lockId, Carbon $from, Carbon $until, string $name): array;

    /** Change how long a code works (stay extended or shortened). Returns false when the provider can't: the caller makes a new one. */
    public function changePin(string $lockId, string $ref, string $pin, Carbon $from, Carbon $until): bool;

    public function revokePin(string $lockId, string $ref): void;

    public function unlock(string $lockId): void;

    /**
     * The lock's own record of openings since $since.
     *
     * @return array<int, array{ref: string, at: Carbon, kind: string, who: ?string, ok: bool, note: ?string}>
     */
    public function records(string $lockId, Carbon $since): array;
}
