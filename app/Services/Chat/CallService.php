<?php

namespace App\Services\Chat;

use App\Exceptions\BookingException;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatSection;
use App\Models\Chat\GuestCall;
use App\Models\Chat\GuestCallSignal;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Mobile\StaffAlerts;
use App\Services\Realtime\Realtime;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Voice calls between a guest and a section of the hotel, over WebRTC: the
 * voice goes phone-to-phone (or through the TURN relay when networks don't
 * allow that); this server only introduces the two sides and keeps the
 * record. A guest's call rings every member of the section — on the website
 * and in the staff app — and the first to answer takes it. Unanswered after
 * 45 seconds it is a missed call: it shows in the chat with a call-back
 * button for the staff. A section's normal phone number is always offered
 * too, for when the internet isn't up to it.
 */
class CallService
{
    public function __construct(private Realtime $realtime, private ChatService $chat)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('hotel.booking.guest_calls', true) && app(ChatService::class)->enabled();
    }

    /** STUN and TURN servers for both phones (TURN logins last a day). */
    public function iceServers(string $who): array
    {
        $c = (array) config('services.webrtc', []);
        $servers = [];
        if (! empty($c['stun'])) {
            $servers[] = ['urls' => array_map('trim', explode(',', $c['stun']))];
        }
        if (! empty($c['turn_urls'])) {
            $urls = array_values(array_filter(array_map('trim', explode(',', $c['turn_urls']))));
            if (! empty($c['turn_secret'])) {
                $user = (time() + 86400) . ':' . Str::slug($who);
                $servers[] = ['urls' => $urls, 'username' => $user, 'credential' => base64_encode(hash_hmac('sha1', $user, $c['turn_secret'], true))];
            } elseif (! empty($c['turn_user'])) {
                $servers[] = ['urls' => $urls, 'username' => $c['turn_user'], 'credential' => (string) ($c['turn_pass'] ?? '')];
            }
        }

        return $servers;
    }

    // ── Starting and answering ────────────────────────────────────────

    /** A guest calls a section. */
    public function guestCalls(Reservation $r, ChatSection $section): GuestCall
    {
        if (! $this->enabled() || ! $section->calls || ! $section->is_active) {
            throw new BookingException("{$section->name} doesn’t take calls in the app." . ($section->phone ? " Call {$section->phone}." : ''));
        }
        if (! $section->isOpen()) {
            throw new BookingException("{$section->name} is closed now" . ($section->hours_label ? " (open {$section->hours_label})" : '') . '. Send a message and they’ll answer when they open.');
        }
        if ($why = $this->chat->blocker($r)) {
            throw new BookingException($why);
        }
        $this->expire();
        $conv = $this->chat->conversation($r, $section);
        if ($live = GuestCall::where('conversation_id', $conv->id)->whereIn('status', ['ringing', 'active'])->first()) {
            return $live;
        }
        $staff = $section->staff();
        if ($staff->isEmpty()) {
            throw new BookingException("Nobody can take calls for {$section->name} right now. Send a message instead.");
        }
        $call = GuestCall::create(['uuid' => (string) Str::uuid(), 'conversation_id' => $conv->id, 'direction' => 'in', 'status' => 'ringing']);
        $this->ring($call, $staff);

        return $call;
    }

    /** Staff call a guest back (rings the guest app / booking page while it's open). */
    public function callBack(ChatConversation $conv, User $by): GuestCall
    {
        $this->chat->authorize($conv, $by);
        $this->expire();
        if ($live = GuestCall::where('conversation_id', $conv->id)->whereIn('status', ['ringing', 'active'])->first()) {
            throw new BookingException($live->status === 'active' ? 'A call with this guest is already going on.' : 'A call to or from this guest is already ringing.');
        }
        $call = GuestCall::create(['uuid' => (string) Str::uuid(), 'conversation_id' => $conv->id, 'direction' => 'out', 'status' => 'ringing', 'started_by' => $by->id]);
        if ($token = $conv->reservation?->guest_token) {
            $this->realtime->send(Realtime::stay($token), 'call.ring', $this->callJson($call, 'guest'));
        }

        return $call;
    }

    /** Pick up: the first to answer takes the call; it stops ringing everywhere else. */
    public function answer(GuestCall $call, string $side, ?User $by = null): GuestCall
    {
        return DB::transaction(function () use ($call, $side, $by) {
            $call = GuestCall::whereKey($call->id)->lockForUpdate()->first();
            $this->expire($call);
            if ($call->status !== 'ringing') {
                throw new BookingException($call->status === 'active' ? 'Someone else has already answered.' : 'The call has ended.');
            }
            if ($side === 'staff') {
                if ($call->direction !== 'in') {
                    throw new BookingException('This is a call to the guest.');
                }
                $this->chat->authorize($call->conversation, $by);
            } elseif ($call->direction !== 'out') {
                throw new BookingException('This is your own call.');
            }
            $call->forceFill(['status' => 'active', 'answered_at' => now(), 'answered_by' => $side === 'staff' ? $by->id : $call->started_by])->save();
            $this->state($call);

            return $call;
        });
    }

    /** Hang up, decline, or give up waiting. */
    public function end(GuestCall $call, string $side, ?User $by = null, string $reason = 'hung_up'): GuestCall
    {
        if ($side === 'staff' && $by) {
            $this->chat->authorize($call->conversation, $by);
        }
        if (! $call->isLive()) {
            return $call;
        }
        $status = match (true) {
            $call->status === 'active' => 'ended',
            $reason === 'declined' => 'declined',
            default => 'missed',
        };
        // A guest giving up on a ringing call is a missed call for the hotel; staff declining is "busy".
        $call->forceFill(['status' => $status, 'ended_at' => now(), 'end_reason' => $reason])->save();
        $this->state($call);
        $this->log($call);

        return $call;
    }

    // ── Signalling (offer / answer / ICE candidates) ──────────────────

    public function signal(GuestCall $call, string $side, string $kind, ?array $payload, ?User $by = null): GuestCallSignal
    {
        if ($side === 'staff' && $by) {
            $this->chat->authorize($call->conversation, $by);
        }
        if (! $call->isLive()) {
            throw new BookingException('The call has ended.');
        }
        if (! in_array($kind, ['offer', 'answer', 'candidate'], true)) {
            throw new BookingException('Unknown call message.');
        }
        $s = GuestCallSignal::create(['call_id' => $call->id, 'side' => $side, 'kind' => $kind, 'payload' => $payload, 'created_at' => now()]);
        $data = ['call' => $call->uuid, 'id' => $s->id, 'kind' => $kind, 'payload' => $payload];
        if ($side === 'guest') {
            // To whoever answered (or, before answering, the whole section: the offer waits for them).
            $this->realtime->send($call->answered_by ? Realtime::staff($call->answered_by) : Realtime::section($call->conversation->section_id), 'call.signal', $data);
        } elseif ($token = $call->conversation->reservation?->guest_token) {
            $this->realtime->send(Realtime::stay($token), 'call.signal', $data);
        }

        return $s;
    }

    /** Signals from the other side after $afterId (the polling fallback). */
    public function signals(GuestCall $call, string $forSide, int $afterId = 0): Collection
    {
        return $call->signals()->where('side', $forSide === 'guest' ? 'staff' : 'guest')->where('id', '>', $afterId)->orderBy('id')->get();
    }

    /** Calls ringing for this person right now (the website's ringer and the staff app ask). */
    public function ringingFor(User $user): Collection
    {
        $this->expire();
        $sections = $this->chat->sectionsFor($user)->pluck('id');

        return GuestCall::where('status', 'ringing')->where('direction', 'in')
            ->whereHas('conversation', fn ($q) => $q->whereIn('section_id', $sections))
            ->with('conversation.section', 'conversation.reservation.guest', 'conversation.reservation.rooms.room')->get();
    }

    /** Ringing too long: missed. Answers how many. */
    public function expire(?GuestCall $only = null): int
    {
        $q = GuestCall::where('status', 'ringing')->where('created_at', '<', now()->subSeconds(GuestCall::RING_SECONDS));
        if ($only) {
            $q->whereKey($only->id);
        }
        $n = 0;
        foreach ($q->get() as $call) {
            $this->end($call, 'system', null, 'no_answer');
            $n++;
        }
        $only?->refresh();

        return $n;
    }

    // ── JSON ──────────────────────────────────────────────────────────

    public function callJson(GuestCall $call, string $for): array
    {
        $conv = $call->conversation;
        $r = $conv?->reservation;

        return [
            'id' => $call->id, 'uuid' => $call->uuid, 'direction' => $call->direction, 'status' => $call->status,
            'section_id' => $conv?->section_id, 'section' => $conv?->section?->name, 'conversation_id' => $conv?->id,
            'guest' => $for === 'staff' ? ($r?->guest?->full_name ?? 'Guest') : null,
            'room' => $for === 'staff' ? ($r?->rooms->pluck('room.number')->filter()->implode(', ') ?: null) : null,
            'answered_by' => $call->answeredBy ? ($for === 'guest' ? Str::of($call->answeredBy->name)->before(' ')->toString() : $call->answeredBy->name) : null,
            'started_at' => $call->created_at->toIso8601String(), 'answered_at' => $call->answered_at?->toIso8601String(),
            'ring_seconds' => GuestCall::RING_SECONDS,
            // The caller makes the offer: the guest for their calls, staff for a call back.
            'i_offer' => ($for === 'guest') === ($call->direction === 'in'),
            'ice_servers' => $call->isLive() ? $this->iceServers($for . '-' . $call->id) : [],
        ];
    }

    // ------------------------------------------------------------------

    private function ring(GuestCall $call, Collection $staff): void
    {
        $conv = $call->conversation->loadMissing('section', 'reservation.guest', 'reservation.rooms.room');
        $json = $this->callJson($call, 'staff');
        $this->realtime->send(Realtime::section($conv->section_id), 'call.ring', $json);
        $room = $conv->reservation?->rooms->pluck('room.number')->filter()->implode(', ');
        app(StaffAlerts::class)->toUsers($staff, [
            'title' => "Incoming call — {$conv->section->name}",
            'body' => ($conv->reservation?->guest?->full_name ?? 'A guest') . ($room ? " · room {$room}" : '') . ' is calling from the guest app.',
            'level' => 'urgent', 'url' => 'call:' . $call->uuid, 'thread' => "call:{$call->id}", 'kind' => 'call', 'item_id' => $call->id,
            'group' => 'calls',
        ]);
    }

    private function state(GuestCall $call): void
    {
        $conv = $call->conversation;
        $this->realtime->send(Realtime::section($conv->section_id), 'call.state', $this->callJson($call, 'staff'));
        if ($token = $conv->reservation?->guest_token) {
            $this->realtime->send(Realtime::stay($token), 'call.state', $this->callJson($call, 'guest'));
        }
    }

    /** A line in the chat for every finished call: "Missed call", "Call, 3 min 12 s". */
    private function log(GuestCall $call): void
    {
        $conv = $call->conversation;
        $s = $call->seconds();
        $text = match ($call->status) {
            'ended'    => '📞 Call' . ($call->answeredBy ? ' with ' . Str::of($call->answeredBy->name)->before(' ') : '') . ($s !== null ? ', ' . ($s >= 60 ? intdiv($s, 60) . ' min ' : '') . ($s % 60) . ' s' : ''),
            'declined' => '📞 The hotel couldn’t take the call just now — send a message and they’ll answer here.',
            default    => $call->direction === 'in' ? '📞 Missed call — the team will call you back or answer here.' : '📞 Missed call from the hotel.',
        };
        $this->chat->note($conv, $text);
        Audit::record('chat.call', "Guest call {$call->status} ({$conv->section?->name})", $conv);
    }
}
