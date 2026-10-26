<?php

namespace App\Models\Channels;

use App\Support\Tenancy\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ChannelSyncLog extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $fillable = ['provider', 'action', 'ok', 'changes', 'message', 'created_at'];

    protected $casts = ['ok' => 'boolean', 'created_at' => 'datetime'];

    public static function write(string $provider, string $action, bool $ok, int $changes = 0, ?string $message = null): self
    {
        return self::create(['provider' => $provider, 'action' => $action, 'ok' => $ok, 'changes' => $changes,
            'message' => $message ? mb_substr($message, 0, 500) : null, 'created_at' => now()]);
    }
}
