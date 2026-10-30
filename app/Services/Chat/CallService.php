<?php

namespace App\Services\Chat;

use App\Exceptions\BookingException;
use App\Models\Chat\CallListen;
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
                // A call back rings the guest; a transferred one (leg 2 on) rings the hotel again.
                if ($call->direction !== 'in' && $call->leg < 2) {
                    throw new BookingException('This is a call to the guest.');
                }
                $this->authorizeCall($call, $by, answering: true);
            } elseif ($call->direction !== 'out' || $call->leg > 1) {
                throw new BookingException('This is your own call.');
            }
            $call->forceFill(['status' => 'active', 'answered_at' => $call->answered_at ?? now(), 'answered_by' => $side === 'staff' ? $by->id : $call->started_by])->save();
            $this->state($call);
            if ($side === 'staff' && ! $this->chat->sectionsFor($by)->contains('id', $call->conversation->section_id) && $call->transfer_to_user !== $by->id) {
                Audit::record('calls.pick_up', "Picked up a {$call->conversation->section?->name} call from the call console", $call->conversation, by: $by);
            }

            return $call;
        });
    }

    /** Hang up, decline, or give up waiting. */
    public function end(GuestCall $call, string $side, ?User $by = null, string $reason = 'hung_up'): GuestCall
    {
        if ($side === 'staff' && $by) {
            $this->authorizeCall($call, $by);
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
        $this->closeListens($call, 'call_ended');
        $this->state($call);
        $this->log($call);

        return $call;
    }

    // ── Signalling (offer / answer / ICE candidates) ──────────────────

    public function signal(GuestCall $call, string $side, string $kind, ?array $payload, ?User $by = null, ?int $leg = null): GuestCallSignal
    {
        if ($side === 'staff' && $by) {
            $this->authorizeCall($call, $by);
        }
        if (! $call->isLive()) {
            throw new BookingException('The call has ended.');
        }
        if ($leg !== null && $leg !== $call->leg) {
            throw new BookingException('The call has been put through to someone else.');
        }
        if (! in_array($kind, ['offer', 'answer', 'candidate'], true)) {
            throw new BookingException('Unknown call message.');
        }
        $s = GuestCallSignal::create(['call_id' => $call->id, 'leg' => $call->leg, 'side' => $side, 'kind' => $kind, 'payload' => $payload, 'created_at' => now()]);
        $data = ['call' => $call->uuid, 'id' => $s->id, 'leg' => $call->leg, 'kind' => $kind, 'payload' => $payload];
        if ($side === 'guest') {
            // To whoever answered (or, before answering, whoever it rings: the offer waits for them).
            $this->realtime->send($this->ringChannels($call), 'call.signal', $data);
        } elseif ($token = $call->conversation->reservation?->guest_token) {
            $this->realtime->send(Realtime::stay($token), 'call.signal', $data);
        }

        return $s;
    }

    /** Signals from the other side after $afterId (the polling fallback). */
    public function signals(GuestCall $call, string $forSide, int $afterId = 0): Collection
    {
        return $call->signals()->whereNull('listen_id')->where('leg', $call->leg)
            ->where('side', $forSide === 'guest' ? 'staff' : 'guest')->where('id', '>', $afterId)->orderBy('id')->get();
    }

    /** Calls ringing for this person right now (the website's ringer and the staff app ask). */
    public function ringingFor(User $user): Collection
    {
        $this->expire();
        $sections = $this->chat->sectionsFor($user)->pluck('id');

        return GuestCall::where('status', 'ringing')
            ->where(function ($q) use ($sections, $user) {
                // Calls in, to a section this person answers (a transferred call: only the person or section it was put through to).
                $q->where(fn ($q) => $q->where('direction', 'in')->where('leg', 1)->whereHas('conversation', fn ($c) => $c->whereIn('section_id', $sections)))
                    ->orWhere(fn ($q) => $q->where('leg', '>', 1)->where(fn ($t) => $t->where('transfer_to_user', $user->id)->orWhereIn('transfer_to_section', $sections)));
            })
            ->with('conversation.section', 'conversation.reservation.guest', 'conversation.reservation.rooms.room', 'transferredBy:id,name')->get();
    }

    /** Ringing too long: missed. Answers how many. */
    public function expire(?GuestCall $only = null): int
    {
        $q = GuestCall::where('status', 'ringing')->whereRaw('coalesce(rang_at, created_at) < ?', [now()->subSeconds(GuestCall::RING_SECONDS)->toDateTimeString()]);
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

    // ── The call console: who may handle a call, transfers, listening in ──

    public function monitoringOn(): bool
    {
        return (bool) config('hotel.call_console.monitoring', true);
    }

    /** Console operators (pick up / put through any call). */
    public function isOperator(User $user): bool
    {
        return $user->can('Answer any guest call');
    }

    /**
     * May this person handle the call? Its section's staff; whoever it was put through to;
     * the person on it; and console operators. Answering a transferred call is only for
     * whoever it was put through to (or an operator).
     */
    public function authorizeCall(GuestCall $call, User $user, bool $answering = false): void
    {
        if ($this->isOperator($user) || ($call->answered_by && $call->answered_by === $user->id)) {
            return;
        }
        $staffs = fn (?ChatSection $s) => $s && $s->staffedBy($user);
        $ok = $call->leg > 1 && ($answering || $call->status === 'ringing')
            ? ($call->transfer_to_user === $user->id || $staffs($call->transferToSection))
            : $staffs($call->conversation?->section) || $call->transfer_to_user === $user->id || $staffs($call->transferToSection);
        if (! $ok) {
            throw new AuthorizationException($call->leg > 1 ? 'That call was put through to someone else.' : 'That call belongs to a section you don’t answer.');
        }
    }

    /**
     * Put a call through to a person or a section. It rings them (the guest hears it ring, so
     * they know they're held); when they answer, the guest's phone connects to them afresh.
     * The person on the call or a console operator can do it, ringing calls too.
     */
    public function transfer(GuestCall $call, User $by, ?User $toUser, ?ChatSection $toSection, ?string $note = null): GuestCall
    {
        return DB::transaction(function () use ($call, $by, $toUser, $toSection, $note) {
            $call = GuestCall::whereKey($call->id)->lockForUpdate()->first();
            $this->expire($call);
            if (! $call->isLive()) {
                throw new BookingException('The call has ended.');
            }
            if (! $this->isOperator($by) && $call->answered_by !== $by->id) {
                throw new AuthorizationException('Only the person on the call (or the call console) can put it through.');
            }
            if (! $toUser && ! $toSection) {
                throw new BookingException('Choose who to put the call through to.');
            }
            if ($toUser) {
                if ($toUser->is_disabled || ! $toUser->canAny(['Chat with guests', 'Answer any guest call'])) {
                    throw new BookingException("{$toUser->name} can’t take guest calls.");
                }
                if ($toUser->id === $call->answered_by) {
                    throw new BookingException("{$toUser->name} is already on this call.");
                }
                $toSection = null;
            } elseif (! $toSection->is_active || $toSection->staff()->isEmpty()) {
                throw new BookingException("Nobody can take calls for {$toSection->name} right now.");
            }
            $from = $call->answeredBy?->name;
            $call->forceFill([
                'leg' => $call->leg + 1, 'status' => 'ringing', 'rang_at' => now(), 'answered_by' => null,
                'transfer_to_user' => $toUser?->id, 'transfer_to_section' => $toSection?->id,
                'transferred_by' => $by->id, 'transferred_at' => now(), 'transfer_note' => $note ? Str::limit($note, 155) : null,
            ])->save();
            $call->unsetRelation('transferToUser')->unsetRelation('transferToSection')->unsetRelation('answeredBy');
            $this->closeListens($call, 'transferred');
            $to = $toUser?->name ?? $toSection->name;
            $this->chat->note($call->conversation, '📞 Your call is being put through to ' . ($toSection?->name ?? Str::of($toUser->name)->before(' ')) . '.');
            Audit::record('calls.transfer', "Put a guest call through to {$to}" . ($from && $from !== $by->name ? " (was with {$from})" : ''), $call->conversation,
                new: ['to' => $to, 'leg' => $call->leg], reason: $note, by: $by);
            $this->ring($call, $toUser ? collect([$toUser]) : $toSection->staff(), $by);
            $this->state($call);

            return $call;
        });
    }

    /** A supervisor asks to listen in. The hotel side's browser sends them the call's sound. */
    public function listen(GuestCall $call, User $by, string $reason): CallListen
    {
        if (! $this->monitoringOn()) {
            throw new BookingException('Listening in is switched off for this hotel.');
        }
        if (! $by->can('Listen to guest calls')) {
            throw new AuthorizationException('Listening to calls isn’t part of your work.');
        }
        $this->expire($call);
        if ($call->status !== 'active' || ! $call->answered_by) {
            throw new BookingException('You can listen once someone has answered.');
        }
        if ($call->answered_by === $by->id) {
            throw new BookingException('You are on this call.');
        }
        $listen = CallListen::where('call_id', $call->id)->where('user_id', $by->id)->where('leg', $call->leg)->whereIn('status', ['waiting', 'live'])->first()
            ?? CallListen::create(['call_id' => $call->id, 'user_id' => $by->id, 'leg' => $call->leg, 'status' => 'waiting', 'reason' => Str::limit(trim($reason), 250)]);
        if ($listen->wasRecentlyCreated) {
            Audit::record('calls.listen', "Listened in on a {$call->conversation->section?->name} call with {$call->answeredBy?->name}", $call->conversation,
                reason: $listen->reason, flagged: true, by: $by);
            $this->realtime->send(Realtime::staff($call->answered_by), 'call.tap', ['call' => $call->uuid, 'listen' => $listen->id]);
        }

        return $listen;
    }

    /** One signalling message between the hotel side's browser ('tap') and the listener ('monitor'). */
    public function listenSignal(CallListen $listen, string $side, string $kind, ?array $payload, User $by): GuestCallSignal
    {
        $call = $listen->call;
        if (! $listen->isOpen() || ! $call->isLive() || $call->leg !== $listen->leg) {
            throw new BookingException('Listening has stopped.');
        }
        $mine = $side === 'tap' ? $call->answered_by === $by->id : $listen->user_id === $by->id;
        if (! $mine || ! in_array($kind, ['offer', 'answer', 'candidate'], true)) {
            throw new AuthorizationException('That isn’t your call.');
        }
        $s = GuestCallSignal::create(['call_id' => $call->id, 'leg' => $call->leg, 'listen_id' => $listen->id, 'side' => $side, 'kind' => $kind, 'payload' => $payload, 'created_at' => now()]);
        if ($side === 'monitor' && $kind === 'answer' && $listen->status === 'waiting') {
            $listen->forceFill(['status' => 'live', 'live_at' => now()])->save();
            $this->state($call);   // the person on the call sees who is listening
        }
        $this->realtime->send(Realtime::staff($side === 'tap' ? $listen->user_id : $call->answered_by), 'call.tap_signal',
            ['call' => $call->uuid, 'listen' => $listen->id, 'id' => $s->id, 'kind' => $kind, 'payload' => $payload]);

        return $s;
    }

    /** For the hotel side's browser: listens to serve, with the listeners' signals after $afterId. */
    public function tapsFor(GuestCall $call, User $user, int $afterId = 0): array
    {
        if ($call->answered_by !== $user->id || ! $call->isLive()) {
            return ['taps' => [], 'tap_signals' => []];
        }
        $open = $call->listens()->where('leg', $call->leg)->whereIn('status', ['waiting', 'live'])->pluck('id');

        return [
            'taps' => $open->values(),
            'tap_signals' => $open->isEmpty() ? [] : GuestCallSignal::whereIn('listen_id', $open)->where('side', 'monitor')->where('id', '>', $afterId)->orderBy('id')
                ->get()->map(fn ($s) => ['id' => $s->id, 'listen' => $s->listen_id, 'kind' => $s->kind, 'payload' => $s->payload])->values(),
        ];
    }

    public function endListen(CallListen $listen, string $reason = 'stopped'): CallListen
    {
        if ($listen->isOpen()) {
            $listen->forceFill(['status' => 'ended', 'ended_at' => now(), 'end_reason' => $reason])->save();
            $secs = $listen->live_at ? (int) $listen->live_at->diffInSeconds(now()) : 0;
            Audit::record('calls.listen_end', 'Stopped listening to a guest call' . ($secs ? ' after ' . ($secs >= 60 ? intdiv($secs, 60) . ' min ' : '') . ($secs % 60) . ' s' : ''), $listen->call?->conversation,
                new: ['reason' => $reason, 'seconds' => $secs], by: $listen->user);
            if ($listen->call?->isLive()) {
                $this->state($listen->call);
            }
        }

        return $listen;
    }

    private function closeListens(GuestCall $call, string $reason): void
    {
        foreach ($call->listens()->whereIn('status', ['waiting', 'live'])->get() as $l) {
            $this->endListen($l, $reason);
        }
    }

    /** May this person follow the call's state (the one who put it through keeps seeing it until it's taken)? */
    public function canSee(GuestCall $call, User $user): bool
    {
        try {
            $this->authorizeCall($call, $user);

            return true;
        } catch (AuthorizationException) {
            return $call->transferred_by === $user->id || (bool) $call->conversation?->section?->staffedBy($user);
        }
    }

    /** What the hotel side's call screen polls: the call, the guest's signals, and listeners to serve. */
    public function staffView(GuestCall $call, User $user, int $after = 0, int $afterTap = 0): array
    {
        if (! $this->canSee($call, $user)) {
            throw new AuthorizationException('That call belongs to a section you don’t answer.');
        }
        $this->expire($call);
        $call = $call->fresh();
        $mine = $call->answered_by === $user->id;

        return $this->callJson($call, 'staff') + [
            'mine' => $mine,
            'signals' => $mine || $call->status === 'ringing' ? $this->signals($call, 'staff', $after)->map(fn ($s) => ['id' => $s->id, 'kind' => $s->kind, 'payload' => $s->payload])->values() : [],
        ] + $this->tapsFor($call, $user, $afterTap);
    }

    /** People and sections a call can be put through to. */
    public function transferTargets(GuestCall $call, User $by): array
    {
        $sections = $this->chat->sections()->filter(fn (ChatSection $s) => $s->is_active)->map(fn (ChatSection $s) => [
            'type' => 'section', 'id' => $s->id, 'label' => $s->name, 'icon' => $s->icon,
            'sub' => ($s->isOpen() ? 'Open' : 'Closed now') . ' · ' . $s->staff()->count() . ' staff',
        ])->values();
        $people = User::where('is_disabled', false)->where('id', '!=', $call->answered_by ?? 0)->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $u) => $u->canAny(['Chat with guests', 'Answer any guest call']) && (! method_exists($u, 'canWorkAt') || ! $call->hotel_id || $u->canWorkAt((int) $call->hotel_id)))
            ->map(fn (User $u) => ['type' => 'user', 'id' => $u->id, 'label' => $u->name, 'icon' => 'ri-user-voice-line', 'sub' => $u->getRoleNames()->implode(', ')])
            ->values();

        return ['sections' => $sections, 'people' => $people];
    }

    /** Where a ringing call (or the guest's signals before an answer) goes. */
    private function ringChannels(GuestCall $call): array
    {
        if ($call->answered_by) {
            return [Realtime::staff($call->answered_by)];
        }
        if ($call->leg > 1) {
            return $call->transfer_to_user ? [Realtime::staff($call->transfer_to_user)] : [Realtime::section((int) $call->transfer_to_section)];
        }

        return [Realtime::section($call->conversation->section_id)];
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
            'ringing_since' => $call->ringStartedAt()->toIso8601String(),
            'ring_seconds' => GuestCall::RING_SECONDS,
            'side' => $for, 'leg' => (int) $call->leg,
            // The caller makes the offer: the guest for their calls, staff for a call back — and the guest again whenever it's put through to someone new.
            'i_offer' => $for === 'guest' ? ($call->direction === 'in' || $call->leg > 1) : ($call->direction === 'out' && $call->leg < 2),
            'transfer' => $call->leg > 1 ? [
                'to' => $for === 'guest' ? ($call->transferToSection?->name ?? ($call->transferToUser ? Str::of($call->transferToUser->name)->before(' ')->toString() : null)) : ($call->transferToUser?->name ?? $call->transferToSection?->name),
                'by' => $for === 'staff' ? $call->transferredBy?->name : null,
                'note' => $for === 'staff' ? $call->transfer_note : null,
            ] : null,
            // Told at the start of every call while supervisors can listen in.
            'notice' => $for === 'guest' && $this->monitoringOn() ? (string) config('hotel.call_console.monitoring_notice') : null,
            'listeners' => $for === 'staff' ? $call->listens()->where('status', 'live')->with('user:id,name')->get()->pluck('user.name')->filter()->values() : [],
            'ice_servers' => $call->isLive() ? $this->iceServers($for . '-' . $call->id) : [],
        ];
    }

    // ------------------------------------------------------------------

    private function ring(GuestCall $call, Collection $staff, ?User $transferredBy = null): void
    {
        $conv = $call->conversation->loadMissing('section', 'reservation.guest', 'reservation.rooms.room');
        $json = $this->callJson($call, 'staff');
        $this->realtime->send($this->ringChannels($call), 'call.ring', $json);
        $room = $conv->reservation?->rooms->pluck('room.number')->filter()->implode(', ');
        app(StaffAlerts::class)->toUsers($staff, [
            'title' => $transferredBy ? "Call put through to you — {$conv->section->name}" : "Incoming call — {$conv->section->name}",
            'body' => ($conv->reservation?->guest?->full_name ?? 'A guest') . ($room ? " · room {$room}" : '') . ($transferredBy ? " · from {$transferredBy->name}" : ' is calling from the guest app.'),
            'level' => 'urgent', 'url' => 'call:' . $call->uuid, 'thread' => "call:{$call->id}", 'kind' => 'call', 'item_id' => $call->id,
            'group' => 'calls',
        ]);
    }

    private function state(GuestCall $call): void
    {
        $conv = $call->conversation;
        $channels = [Realtime::section($conv->section_id)];
        if ($call->leg > 1) {
            $channels = array_merge($channels, $this->ringChannels($call), $call->transferred_by ? [Realtime::staff($call->transferred_by)] : []);
        }
        $this->realtime->send($channels, 'call.state', $this->callJson($call, 'staff'));
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
