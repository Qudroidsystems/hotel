<?php

namespace App\Support\Auth;

/**
 * Time-based one-time codes (RFC 6238), the six-digit codes shown by
 * Google Authenticator, Microsoft Authenticator, Authy and similar apps.
 * 30-second steps, SHA-1, six digits: the defaults every app supports.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;

    /** A new random secret, base32 encoded (160 bits). */
    public static function secret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($chunk) => self::ALPHABET[bindec(str_pad($chunk, 5, '0'))], str_split($bits, 5)));
    }

    /** The code for a time step (defaults to now). */
    public static function code(string $secret, ?int $step = null): string
    {
        $step ??= self::step();
        $hash = hash_hmac('sha1', pack('N*', 0, $step), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The time step a code matches, allowing one step either side for phone clocks
     * that drift; null if it matches none. Callers refuse a step already used.
     */
    public static function match(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) {
            return null;
        }
        $now = self::step();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $now + $i), $code)) {
                return $now + $i;
            }
        }

        return null;
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /** The otpauth:// link an authenticator app reads from the QR code. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC3986);
    }

    private static function decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($byte) => chr(bindec($byte)), array_filter(str_split($bits, 8), fn ($b) => strlen($b) === 8)));
    }
}
