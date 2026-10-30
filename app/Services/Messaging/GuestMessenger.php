<?php

namespace App\Services\Messaging;

use App\Enums\ReservationStatus;
use App\Models\Booking\GuestRequest;
use App\Models\Guest;
use App\Models\Messaging\GuestMessage;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\OnlineBookingService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Text messages to guests by SMS or WhatsApp, through Twilio or Termii
 * (Hotel Settings → Guest Messages; the provider's account is in .env).
 *
 * Every message is logged, including the ones not sent and why (switched off,
 * the guest asked not to be texted, no phone number), so staff can see what a
 * guest was told. Replies from guests come back through the inbound webhook.
 */
class GuestMessenger
{
    public function enabled(): bool
    {
        return (bool) config('hotel.messaging.enabled', false);
    }

    public function channel(): string
    {
        return config('hotel.messaging.channel') === 'whatsapp' ? 'whatsapp' : 'sms';
    }

    /** twilio | termii */
    public function provider(): string
    {
        return config('hotel.messaging.provider') === 'termii' ? 'termii' : 'twilio';
    }

    public function providerLabel(): string
    {
        return $this->provider() === 'termii' ? 'Termii' : 'Twilio';
    }

    /** The provider's account is filled in for the chosen channel. */
    public function configured(): bool
    {
        if ($this->provider() === 'termii') {
            return filled(config('services.termii.api_key')) && filled($this->fromNumber());
        }

        return filled(config('services.twilio.sid')) && filled(config('services.twilio.auth_token')) && filled($this->fromNumber());
    }

    /** What is still missing (Hotel Settings → SMS & WhatsApp Accounts), for the warning on the Messages page. */
    public function missingSettings(): array
    {
        if ($this->provider() === 'termii') {
            return array_keys(array_filter([
                'Termii API key' => blank(config('services.termii.api_key')),
                $this->channel() === 'whatsapp' ? 'Termii WhatsApp device ID' : 'Termii sender ID' => blank($this->fromNumber()),
            ]));
        }

        return array_keys(array_filter([
            'Twilio Account SID' => blank(config('services.twilio.sid')),
            'Twilio Auth Token'  => blank(config('services.twilio.auth_token')),
            $this->channel() === 'whatsapp' ? 'Twilio WhatsApp sender' : 'Twilio phone number (SMS)' => blank($this->fromNumber()),
        ]));
    }

    private function fromNumber(): ?string
    {
        if ($this->provider() === 'termii') {
            return $this->channel() === 'whatsapp'
                ? config('services.termii.whatsapp_from')
                : (config('hotel.messaging.sender_name') ?: config('services.termii.sender_id'));
        }
        if ($this->channel() === 'whatsapp') {
            return config('services.twilio.whatsapp_from');
        }

        return config('hotel.messaging.sender_name') ?: config('services.twilio.phone_number');
    }

    /** A phone number as +<country><number>, or null when it can't be one. */
    public function normalize(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) < 8) {
            return null;
        }
        $cc = preg_replace('/\D+/', '', (string) config('hotel.messaging.country_code', '234'));
        if (str_starts_with($raw, '+')) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            return '+' . $cc . substr($digits, 1);
        }
        if ($cc !== '' && str_starts_with($digits, $cc) && strlen($digits) > 10) {
            return '+' . $digits;
        }

        return '+' . $cc . $digits;
    }

    // ── Automatic messages ────────────────────────────────────────────

    /**
     * Send one of the standard messages about a booking, once (a second call
     * for the same booking does nothing unless the first was not delivered).
     * Answers the logged message, or null when that message is switched off.
     */
    public function aboutBooking(Reservation $r, string $template, array $extra = []): ?GuestMessage
    {
        $toggle = match ($template) {
            'booking_confirmed' => 'send_confirmation',
            'payment_link'      => 'send_payment_link',
            'arrival_reminder'  => 'send_reminder',
            'thank_you'         => 'send_thank_you',
            'luggage_reminder'  => 'send_luggage_reminder',
            'feedback_request'  => 'send_feedback_request',
            default             => null,
        };
        if (! $this->enabled() || ($toggle && ! config("hotel.messaging.{$toggle}", true))) {
            return null;
        }
        if (GuestMessage::where('reservation_id', $r->id)->where('template', $template)->whereIn('status', ['sent', 'delivered', 'read'])->exists()) {
            return null;
        }
        $r->loadMissing('guest');
        $body = $this->render((string) config("hotel.messaging.templates.{$template}"), $r, $r->guest, $extra);

        return $this->send($r->guest, $r, $body, $template);
    }

    public function aboutRequest(GuestRequest $req, ?string $note): ?GuestMessage
    {
        if (! $this->enabled() || ! config('hotel.messaging.send_request_updates', false) || ! $req->reservation) {
            return null;
        }
        $r = $req->reservation->loadMissing('guest');
        $body = $this->render((string) config('hotel.messaging.templates.request_update'), $r, $r->guest, [
            'request' => $req->type_label, 'status' => $req->status_label, 'note' => (string) $note,
        ]);

        return $this->send($r->guest, $r, $body, 'request_update');
    }

    /** Fill in a template's {words}. */
    public function render(string $template, ?Reservation $r, ?Guest $g, array $extra = []): string
    {
        $vars = [
            'first_name'    => $g?->first_name ?? 'there',
            'guest'         => $g?->full_name ?? '',
            'hotel'         => (string) config('hotel.name'),
            'phone'         => (string) (config('hotel.booking.contact_phone') ?? ''),
            'points'        => number_format((int) ($g?->loyalty_points ?? 0)),
            'check_in_time' => \Carbon\Carbon::createFromFormat('H:i', (string) config('hotel.booking.check_in_time', '14:00'))->format('g:i A'),
        ];
        if ($r) {
            $vars += [
                'reference' => $r->reference,
                'check_in'  => $r->check_in_date->format('D j M'),
                'check_out' => $r->check_out_date->format('D j M'),
                'nights'    => (string) $r->nights,
                'link'      => route('stay.show', app(OnlineBookingService::class)->ensureToken($r)),
                'amount'    => config('hotel.currency_symbol') . number_format(app(OnlineBookingService::class)->depositDue($r), 2),
            ];
        }
        foreach ($extra as $k => $v) {
            $vars[$k] = (string) $v;
        }
        $text = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $template);

        return trim(preg_replace('/\s+([.,])/', '$1', preg_replace('/[ \t]+/', ' ', $text)));
    }

    // ── Sending ───────────────────────────────────────────────────────

    /** Send a text (logged whatever happens). Staff messages pass $by. */
    public function send(?Guest $guest, ?Reservation $r, string $body, string $template = 'manual', ?User $by = null): GuestMessage
    {
        $channel = $this->channel();
        $to = $this->normalize($guest?->phone);
        $msg = new GuestMessage([
            'guest_id' => $guest?->id, 'reservation_id' => $r?->id, 'direction' => 'out', 'channel' => $channel,
            'to' => $to, 'from' => $this->fromNumber(), 'body' => mb_substr($body, 0, 1600), 'template' => $template, 'status' => 'skipped', 'sent_by' => $by?->id,
        ]);

        $skip = match (true) {
            ! $this->enabled()          => 'Guest messages are switched off (Hotel Settings → Guest Messages).',
            ! $guest                    => 'No guest on this booking.',
            (bool) $guest->messages_opt_out => 'The guest asked not to be texted.',
            ! $to                       => 'No mobile number for this guest.',
            ! $this->configured()       => 'The ' . $this->providerLabel() . ' account is not set up yet (Hotel Settings → SMS & WhatsApp Accounts).',
            default                     => null,
        };
        if ($skip) {
            $msg->error = $skip;
            $msg->save();

            return $msg;
        }

        try {
            $this->provider() === 'termii' ? $this->sendTermii($msg) : $this->sendTwilio($msg);
        } catch (Throwable $e) {
            $msg->fill(['status' => 'failed', 'error' => mb_substr('Could not reach ' . $this->providerLabel() . ': ' . $e->getMessage(), 0, 255)]);
            Log::warning('Guest message not sent: ' . $e->getMessage());
        }
        $msg->save();
        if ($r && $msg->status === 'sent') {
            $r->log('message', $msg->channel_label . ' to guest (' . $msg->template_label . '): ' . str($msg->body)->limit(120));
        }

        return $msg;
    }

    private function sendTwilio(GuestMessage $msg): void
    {
        $sid = config('services.twilio.sid');
        $prefix = $msg->channel === 'whatsapp' ? 'whatsapp:' : '';
        $res = Http::asForm()->withBasicAuth($sid, config('services.twilio.auth_token'))->timeout(10)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", array_filter([
                'To'             => $prefix . $msg->to,
                'From'           => $prefix . $msg->from,
                'Body'           => $msg->body,
                'StatusCallback' => $this->publicUrl() ? route('twilio.status') : null,
            ]));
        if ($res->successful()) {
            $msg->fill(['status' => 'sent', 'provider_id' => $res->json('sid')]);
        } else {
            $msg->fill(['status' => 'failed', 'error' => mb_substr((string) ($res->json('message') ?? 'Twilio error ' . $res->status()), 0, 255)]);
        }
    }

    /**
     * Termii (termii.com): numbers without the +; SMS by the "dnd" route (reaches
     * numbers on Do-Not-Disturb, needs an approved sender ID) or "generic".
     */
    private function sendTermii(GuestMessage $msg): void
    {
        $base = rtrim((string) config('services.termii.base_url', 'https://api.ng.termii.com'), '/');
        $route = $msg->channel === 'whatsapp' ? 'whatsapp' : (config('hotel.messaging.termii_route') === 'generic' ? 'generic' : 'dnd');
        $res = Http::acceptJson()->asJson()->timeout(10)->post("{$base}/api/sms/send", [
            'api_key' => config('services.termii.api_key'),
            'to'      => ltrim((string) $msg->to, '+'),
            'from'    => $msg->from,
            'sms'     => $msg->body,
            'type'    => 'plain',
            'channel' => $route,
        ]);
        $id = $res->json('message_id');
        if ($res->successful() && filled($id)) {
            $msg->fill(['status' => 'sent', 'provider_id' => (string) $id]);
        } else {
            $msg->fill(['status' => 'failed', 'error' => mb_substr((string) ($res->json('message') ?? 'Termii error ' . $res->status()), 0, 255)]);
        }
    }

    /** Twilio can only call back to an address the internet can reach. */
    private function publicUrl(): bool
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host && ! in_array($host, ['localhost', '127.0.0.1'], true) && ! str_ends_with($host, '.test') && ! str_ends_with($host, '.local');
    }

    // ── Webhooks from Termii ──────────────────────────────────────────

    /** Termii signs the raw body with HMAC-SHA512 and the account's secret key. */
    public function validTermiiSignature(Request $request): bool
    {
        $secret = (string) config('services.termii.secret_key');
        $given = strtolower((string) $request->header('X-Termii-Signature'));

        return $secret !== '' && $given !== '' && hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $given);
    }

    /** One event from Termii: a delivery report for a text we sent, or a guest's reply. */
    public function termiiEvent(array $p): void
    {
        $status = strtolower(trim((string) ($p['status'] ?? '')));
        if ($status === 'received' || strtolower((string) ($p['type'] ?? '')) === 'inbound') {
            $this->inbound([
                'MessageSid' => 'termii:' . ($p['message_id'] ?? $p['id'] ?? sha1(json_encode($p))),
                'From'       => ($p['channel'] ?? '') === 'whatsapp' ? 'whatsapp:+' . ltrim((string) ($p['sender'] ?? ''), '+') : '+' . ltrim((string) ($p['sender'] ?? ''), '+'),
                'To'         => (string) ($p['receiver'] ?? ''),
                'Body'       => (string) ($p['message'] ?? ''),
            ]);

            return;
        }
        $this->statusUpdate([
            'MessageSid'    => (string) ($p['message_id'] ?? ''),
            'MessageStatus' => match ($status) {
                'delivered' => 'delivered',
                'message failed', 'rejected', 'expired', 'dnd active on phone number' => 'failed',
                default => '',
            },
            'reason' => $p['status'] ?? null,
        ]);
    }

    // ── Webhooks from Twilio ──────────────────────────────────────────

    public function validSignature(Request $request): bool
    {
        $token = (string) config('services.twilio.auth_token');
        $given = (string) $request->header('X-Twilio-Signature');
        if ($token === '' || $given === '') {
            return false;
        }
        $params = $request->post();
        ksort($params);
        $data = $request->fullUrl();
        foreach ($params as $k => $v) {
            $data .= $k . (is_array($v) ? implode('', $v) : $v);
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, $token, true)), $given);
    }

    /** Delivery report for a message we sent. */
    public function statusUpdate(array $p): void
    {
        $msg = GuestMessage::where('provider_id', $p['MessageSid'] ?? '')->first();
        if (! $msg) {
            return;
        }
        $status = match ($p['MessageStatus'] ?? '') {
            'delivered'                  => 'delivered',
            'read'                       => 'read',
            'failed', 'undelivered'      => 'failed',
            default                      => null,
        };
        // Never step back (a late "sent" after "delivered").
        if ($status && ! ($msg->status === 'read' || ($msg->status === 'delivered' && $status !== 'read'))) {
            $why = isset($p['ErrorCode']) ? ' (Twilio ' . $p['ErrorCode'] . ')' : (isset($p['reason']) ? ' (' . $p['reason'] . ')' : '');
            $msg->forceFill(['status' => $status, 'error' => $status === 'failed' ? 'Not delivered' . $why : $msg->error])->save();
        }
    }

    /** A guest replied. STOP turns their texts off; anything else is passed to the front desk. */
    public function inbound(array $p): GuestMessage
    {
        $channel = str_starts_with((string) ($p['From'] ?? ''), 'whatsapp:') ? 'whatsapp' : 'sms';
        $from = str_replace('whatsapp:', '', (string) ($p['From'] ?? ''));
        $body = trim((string) ($p['Body'] ?? ''));
        $guest = $this->guestByPhone($from);
        $r = $guest?->reservations()->reorder()->whereIn('status', [ReservationStatus::CheckedIn->value, ReservationStatus::Confirmed->value, ReservationStatus::Pending->value])
            ->orderByRaw("case when status = 'checked_in' then 0 else 1 end")->orderBy('check_in_date')->first();

        $msg = GuestMessage::firstOrCreate(['provider_id' => $p['MessageSid'] ?? null], [
            'guest_id' => $guest?->id, 'reservation_id' => $r?->id, 'direction' => 'in', 'channel' => $channel,
            'from' => $from, 'to' => str_replace('whatsapp:', '', (string) ($p['To'] ?? '')), 'body' => mb_substr($body, 0, 1600), 'template' => 'reply', 'status' => 'received',
        ]);
        if (! $msg->wasRecentlyCreated) {
            return $msg;
        }

        if ($guest && preg_match('/^\s*(stop|unsubscribe|cancel texts|stopall)\s*$/i', $body)) {
            $guest->forceFill(['messages_opt_out' => true])->save();
            Audit::record('guests.messages_opt_out', "{$guest->full_name} replied STOP: no more texts", $guest);
            $msg->forceFill(['read_at' => now()])->save();
        } elseif ($guest && preg_match('/^\s*(start|unstop)\s*$/i', $body)) {
            $guest->forceFill(['messages_opt_out' => false])->save();
            $msg->forceFill(['read_at' => now()])->save();
        } else {
            app(\App\Services\Mobile\StaffAlerts::class)->guestReply($msg);
        }

        return $msg;
    }

    public function guestByPhone(string $phone): ?Guest
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) < 9) {
            return null;
        }
        $tail = substr($digits, -9);
        $matches = Guest::whereNotNull('phone')
            ->whereRaw("replace(replace(replace(replace(replace(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') like ?", ['%' . $tail])
            ->latest('updated_at')->limit(5)->get()
            ->filter(fn ($g) => str_ends_with(preg_replace('/\D+/', '', (string) $g->phone), $tail));

        // The same person can have two profiles; prefer the one with a current booking.
        return $matches->sortByDesc(fn ($g) => $g->reservations()->reorder()->whereIn('status', ['checked_in', 'confirmed', 'pending'])->exists())->first();
    }
}
