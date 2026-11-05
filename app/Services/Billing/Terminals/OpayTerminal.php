<?php

namespace App\Services\Billing\Terminals;

use App\Exceptions\BookingException;
use App\Models\Billing\PaymentTerminal;
use App\Models\Billing\TerminalPayment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OPay POS (OPay Business → Developer Tools; documentation.opayweb.com, POS
 * Integration (API)): an order is created for the POS by its serial number,
 * the guest pays on it, and the order is read back. Every request is
 * encrypted with OPay's public key and signed with the hotel's private key
 * (RSA, SHA256withRSA); OPay's answers are encrypted with the hotel's public
 * key and read with the hotel's private key.
 */
class OpayTerminal implements TerminalDriver
{
    public function configured(): bool
    {
        return filled($this->cfg('head_merchant_id')) && filled($this->cfg('merchant_id')) && filled($this->cfg('client_auth_key'))
            && filled($this->cfg('opay_public_key')) && filled($this->cfg('merchant_private_key'));
    }

    public function push(TerminalPayment $p): array
    {
        $d = $this->call('createOrder', [
            'headMerchantId'  => $this->cfg('head_merchant_id'),
            'merchantId'      => $this->cfg('merchant_id'),
            'outOrderNo'      => $p->reference,
            'amount'          => number_format((float) $p->amount, 2, '.', ''),
            'currency'        => 'NGN',
            'orderExpireTime' => max(60, (int) config('hotel.terminals.expire_minutes', 15) * 60),
            'isSplit'         => 'N',
            'sceneEnum'       => 'CASH_API',
            'subSceneEnum'    => (string) $this->cfg('sub_scene', ''),
            'sn'              => $p->terminal->identifier,
            'remark'          => mb_substr((string) $p->description, 0, 60),
        ]);

        return [(string) ($d['orderNo'] ?? ''), null];
    }

    public function status(TerminalPayment $p): array
    {
        $d = $this->call('qryOrderDetail', [
            'headMerchantId' => $this->cfg('head_merchant_id'),
            'merchantId'     => $this->cfg('merchant_id'),
            'outOrderNo'     => $p->reference,
        ]);
        $status = strtoupper((string) ($d['status'] ?? ''));

        return [
            'state'    => match (true) {
                $status === 'SUCCESS'                                   => 'paid',
                in_array($status, ['FAIL', 'FAILED', 'ERROR'], true)    => 'failed',
                in_array($status, ['CLOSE', 'CLOSED', 'CANCEL', 'CANCELLED', 'EXPIRED'], true) => 'cancelled',
                default                                                 => 'pending',
            },
            'approval' => ($d['payNo'] ?? null) ?: ($d['orderNo'] ?? null),
            'card'     => isset($d['senderAccount']) ? trim(($d['payType'] ?? 'Card') . ' ' . $d['senderAccount']) : null,
            'amount'   => isset($d['amount']) ? (float) $d['amount'] : null,
            'message'  => $d['transactionName'] ?? $status ?: null,
            'raw'      => $d,
        ];
    }

    public function online(PaymentTerminal $t): ?bool
    {
        return null;
    }

    public function cancel(TerminalPayment $p): void
    {
        // The order closes by itself when it expires; the cashier cancels on the POS.
    }

    // ── RSA envelope ──────────────────────────────────────────────────

    private function call(string $action, array $params): array
    {
        $timestamp = (string) (int) round(microtime(true) * 1000);
        $paramContent = $this->encrypt(json_encode($this->sorted($params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (! openssl_sign($paramContent . $timestamp, $sig, $this->key('merchant_private_key', true), OPENSSL_ALGO_SHA256)) {
            throw new BookingException('The OPay private key could not sign the request (Hotel Settings → Card Terminals).');
        }
        try {
            $res = Http::acceptJson()->timeout(20)->connectTimeout(8)->withHeaders([
                'clientAuthKey' => (string) $this->cfg('client_auth_key'),
                'version'       => 'V1.0.1',
                'bodyFormat'    => 'JSON',
                'timestamp'     => $timestamp,
            ])->post($this->base() . '/' . $action, ['paramContent' => $paramContent, 'sign' => base64_encode($sig)]);
        } catch (ConnectionException) {
            throw new BookingException('OPay could not be reached. Check the internet connection, or take the card on the POS and type its approval code.');
        }
        if (! $res->successful() || (string) $res->json('code') !== '00000') {
            Log::warning('OPay terminal error', ['action' => $action, 'status' => $res->status(), 'code' => $res->json('code'), 'message' => $res->json('message')]);
            throw new BookingException('OPay: ' . ($res->json('message') ?: 'request failed') . '.');
        }
        $data = $res->json('data');
        if (is_string($data) && $data !== '') {
            $data = json_decode($this->decrypt($data), true);
        }

        return is_array($data) ? $data : [];
    }

    private function sorted(array $a): array
    {
        ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = $this->sorted($v);
            }
        }

        return $a;
    }

    private function encrypt(string $plain): string
    {
        $key = $this->key('opay_public_key', false);
        $size = (openssl_pkey_get_details($key)['bits'] ?? 1024) / 8 - 11;   // PKCS#1 v1.5
        $out = '';
        foreach (str_split($plain, (int) $size) as $chunk) {
            if (! openssl_public_encrypt($chunk, $enc, $key, OPENSSL_PKCS1_PADDING)) {
                throw new BookingException('The OPay public key could not encrypt the request (Hotel Settings → Card Terminals).');
            }
            $out .= $enc;
        }

        return base64_encode($out);
    }

    private function decrypt(string $b64): string
    {
        $key = $this->key('merchant_private_key', true);
        $size = (int) ((openssl_pkey_get_details($key)['bits'] ?? 1024) / 8);
        $out = '';
        foreach (str_split((string) base64_decode($b64), $size) as $chunk) {
            if (! openssl_private_decrypt($chunk, $dec, $key, OPENSSL_PKCS1_PADDING)) {
                throw new BookingException('OPay\'s answer could not be read with the hotel\'s private key.');
            }
            $out .= $dec;
        }

        return $out;
    }

    /** A PEM key from the settings; a bare base64 key is wrapped. */
    private function key(string $name, bool $private)
    {
        $pem = trim((string) $this->cfg($name));
        if (! str_contains($pem, '-----BEGIN')) {
            $pem = ($private ? "-----BEGIN PRIVATE KEY-----\n" : "-----BEGIN PUBLIC KEY-----\n") . chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n") . ($private ? '-----END PRIVATE KEY-----' : '-----END PUBLIC KEY-----');
        }
        $key = $private ? openssl_pkey_get_private($pem) : openssl_pkey_get_public($pem);
        if (! $key) {
            throw new BookingException('The OPay ' . ($private ? 'private' : 'public') . ' key is not valid (Hotel Settings → Card Terminals).');
        }

        return $key;
    }

    private function cfg(string $k): mixed
    {
        return config("services.opay_pos.{$k}");
    }

    private function base(): string
    {
        return rtrim((string) ($this->cfg('base_url') ?: 'https://payapi.opayweb.com/openApi/order/checkout'), '/');
    }
}
