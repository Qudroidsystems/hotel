<?php

namespace App\Http\Controllers\Chat;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\GuestCall;
use App\Models\User;
use App\Services\Chat\CallService;
use App\Services\Chat\ChatService;
use App\Services\Realtime\Realtime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/**
 * Staff side of guest chat and calls: the website's Guest Chat page (JSON under /chat/…) and the staff app
 * (/api/v1/chats/…). Each person sees the sections they answer; managers see all.
 */
class StaffChatController extends Controller
{
    public function __construct(private ChatService $chat, private CallService $calls)
    {
    }

    /** GET chats?section=&status=open|closed|all&q= — sections, the inbox and what's ringing. */
    public function index(Request $request)
    {
        $user = $this->staff($request);
        $sections = $this->chat->sectionsFor($user);
        $list = $this->chat->inbox($user, $request->integer('section') ?: null, in_array($request->query('status'), ['open', 'closed', 'all'], true) ? $request->query('status') : 'open', $request->query('q'));
        $unread = ChatConversation::whereIn('section_id', $sections->pluck('id'))->where('status', 'open')->selectRaw('section_id, sum(staff_unread) as n')->groupBy('section_id')->pluck('n', 'section_id');

        return $this->ok([
            'sections' => $sections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'icon' => $s->icon, 'open' => $s->isOpen(), 'hours' => $s->hours_label, 'unread' => (int) ($unread[$s->id] ?? 0)])->values(),
            'conversations' => $list->map(fn ($c) => $this->chat->conversationJson($c))->values(),
            'ringing' => $this->calls->ringingFor($user)->map(fn ($c) => $this->calls->callJson($c, 'staff'))->values(),
            'unread' => (int) $unread->sum(),
            'live' => app(Realtime::class)->clientConfig() + ['channels' => array_merge(
                $sections->map(fn ($s) => Realtime::section($s->id))->all(), [Realtime::staff($user->id)])],
        ]);
    }

    /** GET chats/{conversation}?after=id — the thread (marks the guest's messages read). */
    public function show(Request $request, ChatConversation $conversation)
    {
        $user = $this->staff($request);
        $this->chat->authorize($conversation, $user);
        $after = $request->integer('after') ?: null;
        $this->chat->markReadByStaff($conversation);
        $call = GuestCall::where('conversation_id', $conversation->id)->whereIn('status', ['ringing', 'active'])->latest('id')->first();

        return $this->ok([
            'conversation' => $this->chat->conversationJson($conversation->fresh(['section', 'reservation.guest', 'reservation.rooms.room', 'assignee'])),
            'messages' => $this->chat->messages($conversation, $after, $request->integer('before') ?: null)->map(fn ($m) => $this->chat->messageJson($m, 'staff'))->values(),
            'guest_read_up_to' => $conversation->messages()->where('sender', 'staff')->whereNotNull('read_at')->max('id'),
            'typing' => $conversation->guestTyping(),
            'call' => $call ? $this->calls->callJson($call, 'staff') : null,
            'can_call' => $this->calls->enabled(),
            'quick_replies' => ['On our way to your room now.', 'Sure — it will be ready in about 20 minutes.', 'We’ve added it to your bill.', 'Could you tell us your room number, please?', 'You’re welcome! Anything else?'],
        ]);
    }

    /** POST chats/{conversation} {body, client_id, photo} */
    public function send(Request $request, ChatConversation $conversation)
    {
        $user = $this->staff($request);
        $d = $request->validate(['body' => 'nullable|string|max:' . ChatService::MAX_LENGTH, 'client_id' => 'nullable|uuid', 'photo' => 'nullable|image|max:8192']);
        try {
            $m = $this->chat->staffSend($conversation, $user, $d['body'] ?? null, $request->file('photo'), $d['client_id'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok(['message' => $this->chat->messageJson($m, 'staff')], 201);
    }

    public function typing(Request $request, ChatConversation $conversation)
    {
        $user = $this->staff($request);
        $this->chat->authorize($conversation, $user);
        $this->chat->typing($conversation, 'staff');

        return $this->ok(null);
    }

    /** POST chats/{conversation}/close  |  /assign {user_id|null}  |  /reopen */
    public function close(Request $request, ChatConversation $conversation)
    {
        $this->chat->close($conversation, $this->staff($request));

        return $this->ok(null);
    }

    public function assign(Request $request, ChatConversation $conversation)
    {
        $user = $this->staff($request);
        $to = $request->filled('user_id') ? User::find($request->integer('user_id')) : null;
        $this->chat->assign($conversation, $user, $to ?? ($request->boolean('me') ? $user : null));

        return $this->ok(null);
    }

    // ── Calls ─────────────────────────────────────────────────────────

    /** GET calls/ringing — the ringer asks (polling fallback). */
    public function ringing(Request $request)
    {
        $user = $this->staff($request);

        return $this->ok($this->calls->ringingFor($user)->map(fn ($c) => $this->calls->callJson($c, 'staff'))->values());
    }

    /** POST chats/{conversation}/call — call the guest back. */
    public function callBack(Request $request, ChatConversation $conversation)
    {
        try {
            $call = $this->calls->callBack($conversation, $this->staff($request));
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->calls->callJson($call, 'staff'), 201);
    }

    /** GET calls/{uuid}?after=id */
    public function call(Request $request, string $uuid)
    {
        $user = $this->staff($request);
        $call = GuestCall::where('uuid', $uuid)->firstOrFail();
        $this->chat->authorize($call->conversation, $user);
        $this->calls->expire($call);

        return $this->ok($this->calls->callJson($call->fresh(), 'staff') + [
            'signals' => $this->calls->signals($call, 'staff', $request->integer('after'))->map(fn ($s) => ['id' => $s->id, 'kind' => $s->kind, 'payload' => $s->payload])->values(),
        ]);
    }

    public function answer(Request $request, string $uuid)
    {
        $user = $this->staff($request);
        try {
            $call = $this->calls->answer(GuestCall::where('uuid', $uuid)->firstOrFail(), 'staff', $user);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 409);
        }

        return $this->ok($this->calls->callJson($call, 'staff'));
    }

    public function signal(Request $request, string $uuid)
    {
        $user = $this->staff($request);
        $d = $request->validate(['kind' => 'required|in:offer,answer,candidate', 'payload' => 'nullable|array']);
        $call = GuestCall::where('uuid', $uuid)->firstOrFail();
        if ($call->status === 'active' && $call->answered_by && $call->answered_by !== $user->id) {
            return $this->fail('Someone else is on this call.', 409);
        }
        try {
            $s = $this->calls->signal($call, 'staff', $d['kind'], $d['payload'] ?? null, $user);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok(['id' => $s->id]);
    }

    /** POST calls/{uuid}/end {reason: hung_up|declined} */
    public function end(Request $request, string $uuid)
    {
        $user = $this->staff($request);
        $call = $this->calls->end(GuestCall::where('uuid', $uuid)->firstOrFail(), 'staff', $user, $request->input('reason') === 'declined' ? 'declined' : 'hung_up');

        return $this->ok($this->calls->callJson($call, 'staff'));
    }

    // ------------------------------------------------------------------

    private function staff(Request $request): User
    {
        $user = $request->user();
        if (! $user || ! $user->canAny(['Chat with guests', 'Manage guest chat'])) {
            throw new AuthorizationException('Guest chat isn’t part of your work.');
        }

        return $user;
    }

    private function ok(mixed $data, int $status = 200)
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    private function fail(string $message, int $status = 422)
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
