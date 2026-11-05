<?php

namespace App\Services\Billing\Terminals;

use App\Exceptions\BookingException;
use App\Models\Billing\PaymentTerminal;
use App\Models\Billing\TerminalPayment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Moniepoint POS push payments (Moniepoint POS Apps → Push Payment Request):
 * the amount goes to the terminal by its serial number with our reference; the
 * result is read back by that reference. Needs terminal app 1.7.2+ and "ERP
 * integration" switched on for the terminal in the Moniepoint dashboard.
 */
class MoniepointTerminal implements TerminalDriver
{
    public function configured(): bool
    {
        return filled(config('services.moniepoint.client_id')) && filled(config('services.moniepoint.client_secret'));
    }

    public function push(TerminalPayment $p): array
    {
        $this->call('post', '/v1/transactions', [
            'terminalSerial'    => $p->terminal->identifier,
            'amount'            => $this->toProvider((float) $p->amount),
            'merchantReference' => $p->reference,
            'transactionType'   => 'PURCHASE',
            'paymentMethod'     => 'ANY',
        ]);

        return [$p->reference, null];
    }

    public function status(TerminalPayment $p): array
    {
        $d = $this->call('get', '/v1/transactions/merchants/' . rawurlencode($p->reference));
        $status = strtoupper((string) ($d['processingStatus'] ?? 'PENDING'));
        $code = (string) ($d['responseCode'] ?? '');
        $ok = $status === 'PROCESSED' && in_array($code, ['00', '000', '20000'], true);

        return [
            'state'    => match (true) {
                $ok                       => 'paid',
                $status === 'CANCELLED'   => 'cancelled',
                $status === 'PROCESSED'   => 'failed',
                default                   => 'pending',
            },
            'approval' => ($d['transactionReference'] ?? '') ?: null,
            'card'     => isset($d['actualPaymentMethod']) ? str_replace('_', ' ', ucfirst(strtolower((string) $d['actualPaymentMethod']))) : null,
            'amount'   => isset($d['actualAmount']) ? $this->fromProvider((float) $d['actualAmount']) : null,
            'message'  => $d['responseMessage'] ?? null,
            'raw'      => $d,
        ];
    }

    public function online(PaymentTerminal $t): ?bool
    {
        return null;   // Moniepoint does not report it
    }

    public function cancel(TerminalPayment $p): void
    {
        // Moniepoint has no cancel call: the cashier presses cancel on the terminal.
    }

    /** Amounts go to Moniepoint in kobo unless set otherwise (Hotel Settings → Card Terminals). */
    private function toProvider(float $naira): int
    {
        return (int) round(config('services.moniepoint.amount_in_kobo', true) ? $naira * 100 : $naira);
    }

    private function fromProvider(float $v): float
    {
        return config('services.moniepoint.amount_in_kobo', true) ? $v / 100 : $v;
    }

    /** HMAC-SHA256 of "id__timestamp__body" with the subscription secret, base64 (Moniepoint → Webhooks). */
    public static function validWebhook(?string $id, ?string $timestamp, string $body, ?string $signature): bool
    {
        $secret = (string) config('services.moniepoint.webhook_secret', '');
        if ($secret === '' || ! $id || ! $timestamp || ! $signature) {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', "{$id}__{$timestamp}__{$body}", $secret, true)), $signature);
    }

    private function token(): string
    {
        return Cache::remember('moniepoint-token:' . md5((string) config('services.moniepoint.client_id')), now()->addMinutes(20), function () {
            try {
                $res = Http::acceptJson()->timeout(15)->connectTimeout(8)->post($this->base() . '/v1/auth', [
                    'clientId' => config('services.moniepoint.client_id'), 'clientSecret' => config('services.moniepoint.client_secret'),
                ]);
            } catch (ConnectionException) {
                throw new BookingException('Moniepoint could not be reached. Check the internet connection, or take the card on the terminal and type its approval code.');
            }
            if (! $res->successful() || ! $res->json('accessToken')) {
                throw new BookingException('Moniepoint refused the API keys (Hotel Settings → Card Terminals).');
            }

            return (string) $res->json('accessToken');
        });
    }

    private function call(string $method, string $path, array $body = []): array
    {
        try {
            $res = Http::withToken($this->token())->acceptJson()->timeout(20)->connectTimeout(8)
                ->{$method}($this->base() . $path, $method === 'get' ? null : $body);
        } catch (ConnectionException) {
            throw new BookingException('Moniepoint could not be reached. Check the internet connection, or take the card on the terminal and type its approval code.');
        }
        if ($res->status() === 401) {
            Cache::forget('moniepoint-token:' . md5((string) config('services.moniepoint.client_id')));
        }
        if (! $res->successful()) {
            Log::warning('Moniepoint terminal error', ['path' => $path, 'status' => $res->status(), 'message' => $res->json('message')]);
            throw new BookingException('Moniepoint: ' . ($res->json('message') ?: 'request failed (' . $res->status() . ')') . '.');
        }

        return $res->json() ?? [];
    }

    private function base(): string
    {
        return rtrim((string) config('services.moniepoint.base_url', 'https://channel.moniepoint.com'), '/');
    }
}
