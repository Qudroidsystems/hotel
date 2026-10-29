<?php

namespace App\Services\Mobile;

use App\Models\User;
use Carbon\Carbon;

/** A member of staff's own choices for the mobile app's push notifications. */
class AppPreferences
{
    /** The kinds of alert, as the app's notification settings list them. */
    public const GROUPS = [
        'work'      => 'My work (rooms, repairs, tickets)',
        'guests'    => 'Guest requests',
        'approvals' => 'Approvals',
        'it'        => 'IT alerts',
        'chat'      => 'Guest chat messages',
        'calls'     => 'Calls from guests',
    ];

    private function __construct(private User $user, private array $prefs)
    {
    }

    public static function for(User $user): self
    {
        $raw = $user->app_preferences;
        $prefs = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        return new self($user, $prefs);
    }

    public function pushOn(): bool
    {
        return ($this->prefs['push'] ?? true) !== false;
    }

    public function groupOn(string $group): bool
    {
        return ($this->prefs['groups'][$group] ?? true) !== false;
    }

    /** Urgent alerts still come through during quiet hours. */
    public function wantsPush(string $group, string $level = 'normal'): bool
    {
        if (! $this->pushOn() || ! $this->groupOn($group)) {
            return false;
        }

        return $level === 'urgent' || ! $this->inQuietHours();
    }

    public function inQuietHours(?Carbon $at = null): bool
    {
        $from = $this->prefs['quiet_from'] ?? null;
        $to = $this->prefs['quiet_to'] ?? null;
        if (! $from || ! $to) {
            return false;
        }
        $now = ($at ?? now())->format('H:i');

        return $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }

    /** GET /notifications/settings */
    public function settings(): array
    {
        return [
            'groups'         => collect(self::GROUPS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'on' => $this->groupOn($key)])->values(),
            'quiet_from'     => $this->prefs['quiet_from'] ?? null,
            'quiet_to'       => $this->prefs['quiet_to'] ?? null,
            'in_quiet_hours' => $this->inQuietHours(),
            'digest'         => false,
        ];
    }

    /** GET /me/preferences */
    public function preferences(): array
    {
        return [
            'push_enabled'      => $this->pushOn(),
            'reminders_enabled' => false,
            'reminder_minutes'  => 15,
            'push_available'    => app(PushSender::class)->enabled(),
        ];
    }

    /** Save what changed: push (bool), groups ({key: bool}), quiet_from / quiet_to ("22:00" or null). */
    public function update(array $changes): self
    {
        $p = $this->prefs;
        if (array_key_exists('push', $changes)) {
            $p['push'] = (bool) $changes['push'];
        }
        foreach ((array) ($changes['groups'] ?? []) as $key => $on) {
            if (isset(self::GROUPS[$key])) {
                $p['groups'][$key] = (bool) $on;
            }
        }
        if (array_key_exists('quiet_from', $changes) || array_key_exists('quiet_to', $changes)) {
            $p['quiet_from'] = $changes['quiet_from'] ?? null;
            $p['quiet_to'] = $changes['quiet_to'] ?? null;
        }
        $this->user->forceFill(['app_preferences' => json_encode($p)])->saveQuietly();
        $this->prefs = $p;

        return $this;
    }
}
