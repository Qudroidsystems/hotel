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
 * Paystack Terminal (https://paystack.com/docs/terminal/push-payment-requests/):
 * a payment request is created for the amount, then pushed to the terminal as
 * an "invoice" event; the guest pays on the terminal. The same Paystack secret
 * key as online payments (Hotel Settings → Online Payments).
 */
class PaystackTerminal implements TerminalDriver
{
    private const BASE = 'https://api.paystack.co';

    public function configured(): bool
    {
        return $this->secret() !== '';
    }

    public function push(TerminalPayment $p): array
    {
        $req = $this->call('post', '/paymentrequest', [
            'customer'    => $this->customer($p),
            'amount'      => (int) round((float) $p->amount * 100),
            'currency'    => strtoupper((string) config('hotel.currency', 'NGN')),
            'description' => $p->description ?: 'Payment ' . $p->reference,
            'metadata'    => ['reference' => $p->reference],
        ])['data'] ?? [];
        if (empty($req['id']) || empty($req['offline_reference'])) {
            throw new BookingException('Paystack did not create the payment request.');
        }
        $this->call('post', '/terminal/' . rawurlencode($p->terminal->identifier) . '/event', [
            'type' => 'invoice', 'action' => 'process', 'data' => ['id' => $req['id'], 'reference' => $req['offline_reference']],
        ]);

        return [(string) ($req['request_code'] ?? ''), (string) $req['id']];
    }

    public function status(TerminalPayment $p): array
    {
        $d = $this->call('get', '/paymentrequest/verify/' . rawurlencode((string) $p->provider_ref))['data'] ?? [];
        $paid = ($d['paid'] ?? false) === true || ($d['status'] ?? '') === 'success';
        $tx = collect($d['payments'] ?? [])->last() ?? [];
        $auth = $tx['authorization'] ?? [];

        return [
            'state'    => $paid ? 'paid' : (in_array($d['status'] ?? '', ['cancelled', 'failed'], true) ? ($d['status'] === 'cancelled' ? 'cancelled' : 'failed') : 'pending'),
            'approval' => $tx['reference'] ?? ($d['offline_reference'] ?? null),
            'card'     => isset($auth['last4']) ? trim(strtoupper((string) ($auth['card_type'] ?? 'card')) . ' •••• ' . $auth['last4']) : null,
            'amount'   => $paid ? ((int) ($d['amount'] ?? 0)) / 100 : null,
            'message'  => in_array($d['status'] ?? '', ['cancelled', 'failed'], true) ? ucfirst($d['status']) : null,
            'raw'      => $d,
        ];
    }

    public function online(PaymentTerminal $t): ?bool
    {
        $d = $this->call('get', '/terminal/' . rawurlencode($t->identifier) . '/presence')['data'] ?? [];

        return isset($d['online']) ? (bool) $d['online'] && (bool) ($d['available'] ?? true) : null;
    }

    public function cancel(TerminalPayment $p): void
    {
        if ($p->provider_ref) {
            try {
                $this->call('post', '/paymentrequest/archive/' . rawurlencode($p->provider_ref));
            } catch (BookingException) {
                // Already paid or gone; the status check says which.
            }
        }
    }

    /** Paystack needs a customer on every payment request: the guest's e-mail, or the hotel's walk-in customer. */
    private function customer(TerminalPayment $p): string
    {
        $email = $p->folio?->reservation?->guest?->email ?: (string) config('hotel.terminals.paystack_customer_email', 'walk-in@hotel.local');

        return Cache::remember('paystack-customer:' . md5($email), now()->addDays(30), function () use ($email) {
            return (string) ($this->call('post', '/customer', ['email' => $email])['data']['customer_code'] ?? throw new BookingException('Paystack did not create the customer.'));
        });
    }

    private function call(string $method, string $path, array $body = []): array
    {
        try {
            $res = Http::withToken($this->secret())->acceptJson()->timeout(20)->connectTimeout(8)
                ->{$method}(self::BASE . $path, $method === 'get' ? null : $body);
        } catch (ConnectionException $e) {
            throw new BookingException('Paystack could not be reached. Check the internet connection, or take the card on the terminal and type its approval code.');
        }
        if (! $res->successful() || $res->json('status') === false) {
            Log::warning('Paystack terminal error', ['path' => $path, 'status' => $res->status(), 'message' => $res->json('message')]);
            throw new BookingException('Paystack: ' . ($res->json('message') ?: 'request failed') . '.');
        }

        return $res->json() ?? [];
    }

    private function secret(): string
    {
        return (string) config('services.paystack.secret_key', '');
    }
}
