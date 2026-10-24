<?php

namespace App\Services\Booking;

use App\Exceptions\BookingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paystack (https://paystack.com/docs/api/). Keys come from config/services.php
 * (PAYSTACK_SECRET_KEY / PAYSTACK_PUBLIC_KEY in .env). Card details never touch
 * this system: the guest pays on Paystack's own page, and every result is
 * confirmed by asking Paystack directly (verify), never by trusting the
 * browser redirect alone.
 */
class PaystackService
{
    private const BASE = 'https://api.paystack.co';

    public function enabled(): bool
    {
        return (bool) config('services.paystack.active', true) && $this->secret() !== '';
    }

    public function isTestMode(): bool
    {
        return str_starts_with($this->secret(), 'sk_test_');
    }

    /** Start a payment; returns the URL of Paystack's payment page. */
    public function initialize(string $email, float $amount, string $currency, string $reference, string $callbackUrl, array $metadata = []): string
    {
        $res = $this->call('post', '/transaction/initialize', [
            'email'        => $email,
            'amount'       => (int) round($amount * 100),        // kobo / lowest unit
            'currency'     => strtoupper($currency),
            'reference'    => $reference,
            'callback_url' => $callbackUrl,
            'metadata'     => $metadata,
        ]);

        return $res['data']['authorization_url'] ?? throw new BookingException('The payment page could not be opened. Please try again.');
    }

    /**
     * Ask Paystack what happened to a payment.
     * @return array{status: string, amount: float, currency: string, channel: ?string, id: ?string, paid_at: ?string, message: ?string}
     */
    public function verify(string $reference): array
    {
        $d = $this->call('get', '/transaction/verify/' . rawurlencode($reference))['data'] ?? [];

        return [
            'status'   => (string) ($d['status'] ?? 'unknown'),           // success, failed, abandoned, ongoing…
            'amount'   => ((int) ($d['amount'] ?? 0)) / 100,
            'currency' => (string) ($d['currency'] ?? ''),
            'channel'  => $d['channel'] ?? null,
            'id'       => isset($d['id']) ? (string) $d['id'] : null,
            'paid_at'  => $d['paid_at'] ?? null,
            'message'  => $d['gateway_response'] ?? null,
        ];
    }

    /** Webhooks are signed with HMAC-SHA512 of the raw body using the secret key. */
    public function validSignature(string $rawBody, ?string $signature): bool
    {
        return $signature !== null && $signature !== '' && $this->secret() !== ''
            && hash_equals(hash_hmac('sha512', $rawBody, $this->secret()), $signature);
    }

    private function call(string $method, string $path, array $body = []): array
    {
        try {
            $res = Http::withToken($this->secret())->acceptJson()->timeout(20)->connectTimeout(10)
                ->{$method}(self::BASE . $path, $method === 'get' ? null : $body);
        } catch (ConnectionException $e) {
            Log::warning('Paystack unreachable: ' . $e->getMessage());
            throw new BookingException('The payment service could not be reached. Please try again in a moment.');
        }
        if (! $res->successful() || ! ($res->json('status') ?? false)) {
            Log::warning('Paystack error', ['path' => $path, 'status' => $res->status(), 'message' => $res->json('message')]);
            throw new BookingException('Payment service: ' . ($res->json('message') ?: 'request failed') . '.');
        }

        return $res->json();
    }

    private function secret(): string
    {
        return (string) config('services.paystack.secret_key', '');
    }
}
