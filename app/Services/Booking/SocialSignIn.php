<?php

namespace App\Services\Booking;

use App\Exceptions\BookingException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * "Sign in with Google" and "Sign in with Apple" for guests (website and guest
 * app). The browser or the app gets an ID token from Google / Apple and sends
 * it here; we check its signature against the provider's published keys, that
 * it was made for one of our client IDs (Settings → Guest Sign-in) and that it
 * hasn't expired. No client secret is needed for that.
 */
class SocialSignIn
{
    public const PROVIDERS = [
        'google' => ['keys' => 'https://www.googleapis.com/oauth2/v3/certs', 'issuers' => ['accounts.google.com', 'https://accounts.google.com']],
        'apple'  => ['keys' => 'https://appleid.apple.com/auth/keys', 'issuers' => ['https://appleid.apple.com']],
    ];

    /** Client IDs an ID token may be made for, per provider. */
    public function audiences(string $provider): array
    {
        $c = config('hotel.guest_app', []);
        $ids = match ($provider) {
            'google' => [$c['google_web_client_id'] ?? null, $c['google_ios_client_id'] ?? null, $c['google_android_client_id'] ?? null],
            'apple'  => [$c['apple_service_id'] ?? null, $c['apple_bundle_id'] ?? null],
            default  => [],
        };

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $ids)));
    }

    public function enabled(string $provider): bool
    {
        return (bool) config("hotel.guest_app.{$provider}_sign_in") && $this->audiences($provider) !== [];
    }

    /** What the website and the apps need to show the buttons (all public values). */
    public function publicConfig(): array
    {
        $c = config('hotel.guest_app', []);

        return [
            'google' => $this->enabled('google') ? [
                'web_client_id' => $c['google_web_client_id'] ?? null, 'ios_client_id' => $c['google_ios_client_id'] ?? null,
            ] : null,
            'apple' => $this->enabled('apple') ? [
                'service_id' => $c['apple_service_id'] ?? null, 'redirect_uri' => url('/account/apple/callback'),
            ] : null,
        ];
    }

    /**
     * Check an ID token. Answers [sub, email, email_verified, first_name, last_name].
     *
     * @throws BookingException
     */
    public function verify(string $provider, string $idToken, ?string $nonce = null): array
    {
        if (! isset(self::PROVIDERS[$provider]) || ! $this->enabled($provider)) {
            throw new BookingException('That way of signing in isn’t switched on.');
        }
        $fail = fn () => new BookingException('We couldn’t confirm your ' . ucfirst($provider) . ' sign-in. Please try again.');

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw $fail();
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64($h), true);
        $claims = json_decode(self::b64($p), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw $fail();
        }

        $pem = $this->key($provider, $header['kid']);
        if (! $pem || openssl_verify("{$h}.{$p}", self::b64($s), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw $fail();
        }

        $aud = (array) ($claims['aud'] ?? []);
        $now = time();
        if (! in_array($claims['iss'] ?? '', self::PROVIDERS[$provider]['issuers'], true)
            || ! array_intersect($aud, $this->audiences($provider))
            || (int) ($claims['exp'] ?? 0) < $now - 60
            || (int) ($claims['iat'] ?? $now) > $now + 300) {
            throw $fail();
        }
        // Apple hashes the nonce the app sent; Google passes it as given.
        if ($nonce !== null && $nonce !== '' && ! in_array($claims['nonce'] ?? null, [$nonce, hash('sha256', $nonce)], true)) {
            throw $fail();
        }

        $email = mb_strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'sub' => (string) ($claims['sub'] ?? ''),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'email_verified' => $verified,
            'first_name' => $claims['given_name'] ?? null,
            'last_name' => $claims['family_name'] ?? null,
        ];
    }

    /** The provider's public key with this id, as PEM (keys are cached for six hours, refreshed when a new id shows up). */
    private function key(string $provider, string $kid): ?string
    {
        $cacheKey = "social_sign_in.keys.{$provider}";
        $keys = Cache::get($cacheKey);
        if (! is_array($keys) || ! isset($keys[$kid])) {
            try {
                $res = Http::timeout(8)->get(self::PROVIDERS[$provider]['keys']);
                $keys = collect($res->json('keys') ?? [])->filter(fn ($k) => ($k['kty'] ?? '') === 'RSA' && isset($k['kid'], $k['n'], $k['e']))
                    ->mapWithKeys(fn ($k) => [$k['kid'] => self::pem($k['n'], $k['e'])])->all();
            } catch (Throwable) {
                $keys = [];
            }
            if ($keys) {
                Cache::put($cacheKey, $keys, now()->addHours(6));
            }
        }

        return $keys[$kid] ?? null;
    }

    private static function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** An RSA public key (JWK modulus and exponent) as a PEM SubjectPublicKeyInfo. */
    public static function pem(string $n, string $e): string
    {
        $int = function (string $bytes) {
            $bytes = ltrim($bytes, "\0");
            if (ord($bytes[0] ?? "\0") > 0x7f) {
                $bytes = "\0" . $bytes;
            }

            return "\x02" . self::len(strlen($bytes)) . $bytes;
        };
        $rsa = $int(self::b64($n)) . $int(self::b64($e));
        $rsa = "\x30" . self::len(strlen($rsa)) . $rsa;
        $bits = "\x03" . self::len(strlen($rsa) + 1) . "\0" . $rsa;
        $algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";   // rsaEncryption, NULL
        $spki = "\x30" . self::len(strlen($algo . $bits)) . $algo . $bits;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\0");

        return chr(0x80 | strlen($b)) . $b;
    }
}
