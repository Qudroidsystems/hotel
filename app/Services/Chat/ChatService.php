<?php

namespace App\Services\Chat;

use App\Enums\ReservationStatus as S;
use App\Exceptions\BookingException;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatSection;
use App\Models\Chat\GuestCall;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\OnlineBookingService;
use App\Services\Mobile\StaffAlerts;
use App\Services\Realtime\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guest chat. A guest writes to a section of the hotel (Reception, Restaurant,
 * Bar, Laundry …) from the guest app or their booking page; the staff of that
 * section answer on the website or in the staff app. Every message is stored
 * first, then pushed live; whoever isn't connected catches up on their next
 * look, so nothing is lost when a phone drops its connection. A message sent
 * twice (a retry after a timeout) is stored once.
 */
class ChatService
{
    public const MAX_LENGTH = 2000;

    /** What a browser or phone records a voice message as (Chrome: webm, Safari/iOS: mp4/m4a, Android: aac/3gp). */
    public const AUDIO_TYPES = 'audio/webm,video/webm,audio/ogg,audio/mpeg,audio/mp4,video/mp4,audio/x-m4a,audio/m4a,audio/aac,audio/x-aac,audio/3gpp,video/3gpp,audio/wav,audio/x-wav';
    public const PAGE = 60;

    public function __construct(private Realtime $realtime)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('hotel.booking.guest_chat', true);
    }

    // ── Sections ──────────────────────────────────────────────────────

    /** The current hotel's sections; a hotel's first look sets up the usual ones. */
    public function sections(bool $activeOnly = true): Collection
    {
        if (! ChatSection::exists()) {
            foreach (ChatSection::DEFAULTS as $i => [$key, $name, $icon, $desc, $roles, $opens, $closes]) {
                ChatSection::create(['key' => $key, 'name' => $name, 'icon' => $icon, 'description' => $desc, 'roles' => $roles,
                    'opens' => $opens, 'closes' => $closes, 'sort_order' => $i, 'calls' => true,
                    'phone' => $key === 'reception' ? config('hotel.booking.contact_phone') : null]);
            }
        }

        return $activeOnly ? ChatSection::active()->get() : ChatSection::orderBy('sort_order')->orderBy('id')->get();
    }

    /** Sections this person answers. */
    public function sectionsFor(User $user): Collection
    {
        return $this->sections()->filter(fn (ChatSection $s) => $s->staffedBy($user))->values();
    }

    /** Why this stay can't chat, or null. */
    public function blocker(Reservation $r): ?string
    {
        if (! $this->enabled()) {
            return 'Chat isn’t available at this hotel.';
        }
        if (in_array($r->status, [S::Cancelled, S::NoShow], true) || ($r->status === S::CheckedOut && $r->checked_out_at?->lt(now()->subDays(7)))) {
            return 'Chat is open from booking until a week after your stay.';
        }

        return null;
    }

    public function conversation(Reservation $r, ChatSection $section): ChatConversation
    {
        try {
            return ChatConversation::firstOrCreate(['section_id' => $section->id, 'reservation_id' => $r->id], ['guest_id' => $r->guest_id, 'status' => 'open']);
        } catch (UniqueConstraintViolationException) {
            return ChatConversation::where(['section_id' => $section->id, 'reservation_id' => $r->id])->firstOrFail();
        }
    }

    // ── Sending ───────────────────────────────────────────────────────

    public function guestSend(Reservation $r, ChatSection $section, ?string $body, ?UploadedFile $photo = null, ?string $clientId = null, ?UploadedFile $audio = null, ?int $audioSeconds = null): ChatMessage
    {
        if ($why = $this->blocker($r)) {
            throw new BookingException($why);
        }
        if (! $section->is_active) {
            throw new BookingException('That part of the hotel isn’t taking messages. Try Reception.');
        }
        $conv = $this->conversation($r, $section);
        $msg = $this->store($conv, 'guest', null, $body, $photo, $clientId, $audio, $audioSeconds);
        if (! $msg->wasRecentlyCreated) {
            return $msg;   // a resend
        }
        $conv->forceFill(['status' => 'open', 'closed_at' => null, 'staff_unread' => $conv->staff_unread + 1, 'guest_typing_at' => null])->save();

        // Closed now: say when it opens (once every 3 hours), and let the staff know anyway.
        if (! $section->isOpen() && ! $conv->messages()->where('sender', 'system')->where('created_at', '>', now()->subHours(3))->exists()) {
            $this->store($conv, 'system', null, $section->away_message
                ?: "{$section->name} is closed now" . ($section->hours_label ? " (open {$section->hours_label})" : '') . '. We’ll answer as soon as we open — for anything urgent, message Reception.', null, null);
        }
        $this->announce($conv, $msg);
        $this->alertStaff($conv, $msg);

        return $msg;
    }

    public function staffSend(ChatConversation $conv, User $user, ?string $body, ?UploadedFile $photo = null, ?string $clientId = null, ?UploadedFile $audio = null, ?int $audioSeconds = null): ChatMessage
    {
        $this->authorize($conv, $user);
        $msg = $this->store($conv, 'staff', $user, $body, $photo, $clientId, $audio, $audioSeconds);
        if (! $msg->wasRecentlyCreated) {
            return $msg;
        }
        $conv->forceFill(['guest_unread' => $conv->guest_unread + 1, 'staff_typing_at' => null, 'assigned_to' => $conv->assigned_to ?? $user->id, 'status' => 'open'])->save();
        // Answering is reading.
        $this->markReadByStaff($conv);
        $this->announce($conv, $msg);

        return $msg;
    }

    /** A line from the system in the chat (a missed call …). */
    public function note(ChatConversation $conv, string $text): ChatMessage
    {
        $msg = $this->store($conv, 'system', null, $text, null, null);
        $this->announce($conv, $msg);

        return $msg;
    }

    private function store(ChatConversation $conv, string $sender, ?User $user, ?string $body, ?UploadedFile $photo, ?string $clientId, ?UploadedFile $audio = null, ?int $audioSeconds = null): ChatMessage
    {
        $body = $body !== null ? trim(Str::limit(str_replace("\r\n", "\n", $body), self::MAX_LENGTH, '')) : null;
        if (($body === null || $body === '') && ! $photo && ! $audio) {
            throw new BookingException('Write a message first.');
        }
        if ($clientId && ! Str::isUuid($clientId)) {
            $clientId = null;
        }
        if ($clientId && ($same = ChatMessage::where('conversation_id', $conv->id)->where('client_id', $clientId)->first())) {
            return $same;
        }
        $path = $photo ? $photo->store('chat/' . $conv->id, 'public') : null;
        // Voice messages stay private: they are played through a signed link (see audioUrl).
        $voice = $audio ? $audio->store('chat-audio/' . $conv->id, 'local') : null;
        $seconds = $audio ? max(1, min(300, (int) ($audioSeconds ?: 1))) : null;
        try {
            $msg = ChatMessage::create([
                'conversation_id' => $conv->id, 'sender' => $sender, 'user_id' => $user?->id,
                'body' => $body ?: null, 'photo' => $path, 'audio' => $voice, 'audio_seconds' => $seconds, 'client_id' => $clientId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return ChatMessage::where('conversation_id', $conv->id)->where('client_id', $clientId)->firstOrFail();
        }
        $conv->forceFill(['last_message_at' => $msg->created_at, 'last_preview' => Str::limit($body ?: ($voice ? '🎤 Voice message (' . self::clock($seconds) . ')' : '📷 Photo'), 150)])->save();

        return $msg;
    }

    // ── Reading, typing, closing ──────────────────────────────────────

    public function markReadByGuest(ChatConversation $conv): void
    {
        $n = $conv->messages()->whereIn('sender', ['staff', 'system'])->whereNull('read_at')->update(['read_at' => now()]);
        if ($n || $conv->guest_unread) {
            $conv->forceFill(['guest_unread' => 0])->save();
            $this->realtime->send(Realtime::section($conv->section_id), 'chat.read', ['conversation_id' => $conv->id, 'by' => 'guest', 'at' => now()->toIso8601String()]);
        }
    }

    public function markReadByStaff(ChatConversation $conv): void
    {
        $n = $conv->messages()->where('sender', 'guest')->whereNull('read_at')->update(['read_at' => now()]);
        if ($n || $conv->staff_unread) {
            $conv->forceFill(['staff_unread' => 0])->save();
            if ($token = $conv->reservation?->guest_token) {
                $this->realtime->send(Realtime::stay($token), 'chat.read', ['conversation_id' => $conv->id, 'section_id' => $conv->section_id, 'by' => 'staff', 'at' => now()->toIso8601String()]);
            }
        }
    }

    public function typing(ChatConversation $conv, string $side): void
    {
        $conv->forceFill([$side === 'guest' ? 'guest_typing_at' : 'staff_typing_at' => now()])->saveQuietly();
        $data = ['conversation_id' => $conv->id, 'section_id' => $conv->section_id, 'side' => $side];
        $side === 'guest'
            ? $this->realtime->send(Realtime::section($conv->section_id), 'chat.typing', $data)
            : ($conv->reservation?->guest_token ? $this->realtime->send(Realtime::stay($conv->reservation->guest_token), 'chat.typing', $data) : null);
    }

    public function close(ChatConversation $conv, User $user): void
    {
        $this->authorize($conv, $user);
        $conv->forceFill(['status' => 'closed', 'closed_at' => now(), 'staff_unread' => 0])->save();
    }

    public function assign(ChatConversation $conv, User $user, ?User $to): void
    {
        $this->authorize($conv, $user);
        $conv->forceFill(['assigned_to' => $to?->id])->save();
    }

    /** Messages after $afterId (oldest first), or the latest page. */
    public function messages(ChatConversation $conv, ?int $afterId = null, ?int $beforeId = null): Collection
    {
        $q = $conv->messages()->with('user:id,name');
        if ($afterId) {
            return $q->where('id', '>', $afterId)->orderBy('id')->limit(200)->get();
        }
        if ($beforeId) {
            $q->where('id', '<', $beforeId);
        }

        return $q->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();
    }

    public function authorize(ChatConversation $conv, User $user): void
    {
        $section = $conv->section;
        if (! $section || ! $section->staffedBy($user)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('That chat belongs to a section you don’t answer.');
        }
    }

    // ── JSON ──────────────────────────────────────────────────────────

    /** A link that plays a voice message for a few hours (works in the browser, the guest app and the staff app). */
    public function audioUrl(ChatMessage $m): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('chat.audio', now()->addHours(6), ['message' => $m->id]);
    }

    public static function clock(?int $seconds): string
    {
        $seconds = (int) $seconds;

        return intdiv($seconds, 60) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }

    /** A message for the guest (staff by first name only) or for staff (full name). */
    public function messageJson(ChatMessage $m, string $for): array
    {
        $name = match ($m->sender) {
            'staff'  => $for === 'guest' ? (Str::of($m->user?->name ?? 'Staff')->before(' ')->toString()) : ($m->user?->name ?? 'Staff'),
            'guest'  => $for === 'guest' ? 'You' : 'Guest',
            default  => null,
        };

        return [
            'id' => $m->id, 'client_id' => $m->client_id, 'sender' => $m->sender, 'name' => $name,
            'mine' => ($for === 'guest' && $m->sender === 'guest') || ($for === 'staff' && $m->sender === 'staff'),
            'body' => $m->body, 'photo' => $m->photo_url,
            'audio' => $m->audio ? $this->audioUrl($m) : null, 'audio_seconds' => $m->audio_seconds,
            'audio_label' => $m->audio ? '🎤 Voice message (' . self::clock((int) $m->audio_seconds) . ')' : null,
            'at' => $m->created_at->toIso8601String(), 'time' => $m->created_at->format('g:i A'), 'day' => $m->created_at->format('D j M'),
            'read' => $m->read_at !== null,
        ];
    }

    /** The chat home for a guest: every section, open or not, with their conversation's state. */
    public function forGuest(Reservation $r): array
    {
        $convs = ChatConversation::where('reservation_id', $r->id)->get()->keyBy('section_id');
        $calls = app(CallService::class);

        return [
            'available' => $this->blocker($r) === null,
            'note'      => $this->blocker($r),
            'calls'     => $calls->enabled(),
            'sections'  => $this->sections()->map(function (ChatSection $s) use ($convs, $calls) {
                $c = $convs->get($s->id);

                return [
                    'id' => $s->id, 'key' => $s->key, 'name' => $s->name, 'icon' => $s->icon, 'description' => $s->description,
                    'open' => $s->isOpen(), 'hours' => $s->hours_label, 'phone' => $s->phone,
                    'can_call' => $calls->enabled() && $s->calls && $s->isOpen(),
                    'unread' => (int) ($c?->guest_unread ?? 0),
                    'last' => $c?->last_preview, 'last_at' => $c?->last_message_at?->toIso8601String(),
                    'last_label' => $c?->last_message_at?->diffForHumans(short: true),
                    'typing' => (bool) $c?->staffTyping(),
                    'suggestions' => $s->suggestions(),
                ];
            })->values(),
            'unread' => (int) $convs->sum('guest_unread'),
            'live' => $this->realtime->clientConfig() + ['channel' => Realtime::stay((string) $r->guest_token)],
        ];
    }

    /** One section's thread for the guest (marks it read). */
    public function threadForGuest(Reservation $r, ChatSection $section, ?int $afterId = null): array
    {
        $conv = $this->conversation($r, $section);
        $this->markReadByGuest($conv);
        $call = GuestCall::where('conversation_id', $conv->id)->whereIn('status', ['ringing', 'active'])->latest('id')->first();

        return [
            'conversation_id' => $conv->id,
            'section' => ['id' => $section->id, 'name' => $section->name, 'icon' => $section->icon, 'open' => $section->isOpen(), 'hours' => $section->hours_label, 'phone' => $section->phone,
                'can_call' => app(CallService::class)->enabled() && $section->calls && $section->isOpen(), 'key' => $section->key, 'suggestions' => $section->suggestions()],
            'messages' => $this->messages($conv, $afterId)->map(fn ($m) => $this->messageJson($m, 'guest'))->values(),
            'typing' => $conv->staffTyping(),
            'read_up_to' => $conv->messages()->where('sender', 'guest')->whereNotNull('read_at')->max('id'),
            'call' => $call ? app(CallService::class)->callJson($call, 'guest') : null,
        ];
    }

    /** The staff inbox: conversations in the sections this person answers, newest first. */
    public function inbox(User $user, ?int $sectionId = null, string $status = 'open', ?string $q = null): Collection
    {
        $ids = $this->sectionsFor($user)->pluck('id');
        if ($sectionId) {
            $ids = $ids->intersect([$sectionId]);
        }

        return ChatConversation::whereIn('section_id', $ids)
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->whereNotNull('last_message_at')
            ->when($q, fn ($w) => $w->whereHas('reservation', fn ($r) => $r->where('reference', 'like', "%{$q}%")
                ->orWhereHas('guest', fn ($g) => $g->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%"))))
            ->with(['section:id,name,icon', 'reservation:id,reference,status,guest_id,check_in_date,check_out_date', 'reservation.guest:id,title,first_name,last_name,is_vip,phone', 'reservation.rooms.room:id,number', 'assignee:id,name'])
            ->orderByDesc('staff_unread')->orderByDesc('last_message_at')->limit(100)->get();
    }

    public function conversationJson(ChatConversation $c): array
    {
        $r = $c->reservation;

        return [
            'id' => $c->id, 'section_id' => $c->section_id, 'section' => $c->section?->name, 'section_icon' => $c->section?->icon,
            'guest' => $r?->guest?->full_name ?? 'Guest', 'vip' => (bool) $r?->guest?->is_vip, 'phone' => $r?->guest?->phone,
            'room' => $r?->rooms->pluck('room.number')->filter()->implode(', ') ?: null,
            'reference' => $r?->reference, 'stay_status' => $r?->status?->label(), 'reservation_id' => $r?->id,
            'stay' => $r ? $r->check_in_date->format('j M') . ' – ' . $r->check_out_date->format('j M') : null,
            'status' => $c->status, 'unread' => (int) $c->staff_unread,
            'last' => $c->last_preview, 'last_at' => $c->last_message_at?->toIso8601String(), 'last_label' => $c->last_message_at?->diffForHumans(short: true),
            'assigned' => $c->assignee?->name, 'typing' => $c->guestTyping(),
        ];
    }

    /** Total unread for a person (badges). */
    public function unreadFor(User $user): int
    {
        $ids = $this->sectionsFor($user)->pluck('id');

        return $ids->isEmpty() ? 0 : (int) ChatConversation::whereIn('section_id', $ids)->where('status', 'open')->sum('staff_unread');
    }

    // ------------------------------------------------------------------

    private function announce(ChatConversation $conv, ChatMessage $msg): void
    {
        $conv->loadMissing('reservation');
        $this->realtime->send(Realtime::section($conv->section_id), 'chat.message', ['conversation' => $this->conversationJson($conv->fresh(['section', 'reservation.guest', 'reservation.rooms.room', 'assignee'])), 'message' => $this->messageJson($msg, 'staff')]);
        if ($token = $conv->reservation?->guest_token) {
            $this->realtime->send(Realtime::stay($token), 'chat.message', ['section_id' => $conv->section_id, 'conversation_id' => $conv->id, 'message' => $this->messageJson($msg, 'guest')]);
        }
    }

    private function alertStaff(ChatConversation $conv, ChatMessage $msg): void
    {
        // One push per conversation per few minutes: a guest typing five short lines rings once.
        $key = "chat-alert:{$conv->id}";
        if (\Illuminate\Support\Facades\Cache::has($key)) {
            return;
        }
        \Illuminate\Support\Facades\Cache::put($key, 1, now()->addMinutes(3));
        $conv->loadMissing('section', 'reservation.guest', 'reservation.rooms.room');
        $room = $conv->reservation?->rooms->pluck('room.number')->filter()->implode(', ');
        $who = ($conv->reservation?->guest?->full_name ?? 'A guest') . ($room ? " (room {$room})" : '');
        $staff = $conv->assigned_to ? $conv->section->staff()->where('id', $conv->assigned_to)->values() : collect();
        app(StaffAlerts::class)->toUsers($staff->isNotEmpty() ? $staff : $conv->section->staff(), [
            'title' => "{$conv->section->name}: {$who}",
            'body' => $msg->body ? Str::limit($msg->body, 160) : ($msg->audio ? 'Sent a voice message' : 'Sent a photo'),
            'group' => 'chat', 'level' => $conv->reservation?->guest?->is_vip ? 'urgent' : 'normal',
            'url' => 'chat:' . $conv->id, 'thread' => "chat:{$conv->id}", 'kind' => 'chat', 'item_id' => $conv->id,
        ]);
    }
}
