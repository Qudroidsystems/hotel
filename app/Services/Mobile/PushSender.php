<?php

namespace App\Services\Mobile;

use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Push notifications through Firebase Cloud Messaging (HTTP v1).
 *
 * Off until the Firebase service-account key is pasted under Hotel Settings →
 * Phone Notifications (stored encrypted), or FCM_CREDENTIALS in .env points to
 * the key file (Firebase console → Project settings → Service accounts →
 * Generate new private key). Keep that file outside the public folder.
 */
class PushSender
{
    public function enabled(): bool
    {
        if (filled(config('services.fcm.credentials_json'))) {
            return true;
        }
        $path = $this->credentialsPath();

        return $path !== null && is_readable($path);
    }

    /** Send one alert to every phone the person is signed in on. */
    public function toUser(User $user, array $data, ?string $notificationId = null): int
    {
        $devices = MobileDevice::where('user_id', $user->id)->get();
        if ($devices->isEmpty() || ! $this->enabled()) {
            return 0;
        }
        $unread = $user->unreadNotifications()->count();
        $payload = array_filter([
            'notification_id' => $notificationId,
            'url'             => $data['url'] ?? null,
            'category'        => $data['group'] ?? 'work',
            'channel_id'      => $data['group'] ?? 'work',
            'group'           => $data['group'] ?? 'work',
            'level'           => $data['level'] ?? 'normal',
            'thread_id'       => $data['thread'] ?? null,
            'title'           => $data['title'] ?? null,
            'body'            => $data['body'] ?? null,
            'actions'         => ! empty($data['actions']) ? json_encode($data['actions']) : null,
            'badge'           => (string) $unread,
        ], fn ($v) => $v !== null && $v !== '');

        $sent = 0;
        foreach ($devices as $device) {
            $sent += $this->send($device, (string) ($data['title'] ?? config('hotel.name')), (string) ($data['body'] ?? ''), $payload, $unread) ? 1 : 0;
        }

        return $sent;
    }

    private function send(MobileDevice $device, string $title, string $body, array $data, int $badge): bool
    {
        $creds = $this->credentials();
        $token = $this->accessToken($creds);
        $channel = $data['channel_id'] ?? 'general';

        $res = Http::withToken($token)->timeout(8)->acceptJson()->post(
            "https://fcm.googleapis.com/v1/projects/{$creds['project_id']}/messages:send",
            ['message' => [
                'token'        => $device->push_token,
                'notification' => ['title' => $title, 'body' => $body],
                'data'         => array_map('strval', $data),
                'android'      => ['priority' => ($data['level'] ?? '') === 'passive' ? 'NORMAL' : 'HIGH', 'notification' => ['channel_id' => $channel, 'tag' => $data['thread_id'] ?? null]],
                'apns'         => ['payload' => ['aps' => ['badge' => $badge, 'sound' => 'default', 'thread-id' => $data['thread_id'] ?? $channel]]],
            ]],
        );

        if ($res->successful()) {
            return true;
        }
        $code = data_get($res->json(), 'error.details.0.errorCode') ?? data_get($res->json(), 'error.status');
        if (in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true) || $res->status() === 404) {
            $device->delete();   // the app was removed or signed out on that phone
        } else {
            Log::warning("FCM push failed ({$res->status()}): " . mb_substr($res->body(), 0, 300));
        }

        return false;
    }

    /** A short-lived Google access token from the service-account key (signed JWT, RS256). */
    private function accessToken(array $creds): string
    {
        return Cache::remember('fcm.access_token.' . md5($creds['client_email']), now()->addMinutes(50), function () use ($creds) {
            $now = time();
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $b64(json_encode([
                'iss' => $creds['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]));
            openssl_sign("{$head}.{$claims}", $signature, $creds['private_key'], OPENSSL_ALGO_SHA256);
            $res = Http::asForm()->timeout(8)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => "{$head}.{$claims}." . $b64($signature),
            ])->throw();

            return (string) $res->json('access_token');
        });
    }

    private function credentials(): array
    {
        $raw = config('services.fcm.credentials_json') ?: (string) file_get_contents($this->credentialsPath());
        $json = json_decode((string) $raw, true);
        if (! is_array($json) || empty($json['project_id']) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new \RuntimeException('The Firebase key (Hotel Settings → Phone Notifications or FCM_CREDENTIALS) is not a service-account key file.');
        }

        return $json;
    }

    private function credentialsPath(): ?string
    {
        $path = config('services.fcm.credentials');
        if (! $path) {
            return null;
        }

        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) ? $path : base_path($path);
    }
}
