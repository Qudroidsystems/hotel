<?php

namespace App\Http\Controllers\Chat;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Chat\ChatSection;
use App\Models\Chat\GuestCall;
use App\Models\Reservation;
use App\Services\Chat\CallService;
use App\Services\Chat\ChatService;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Http\Request;

/**
 * The guest's side of chat and calls, for the guest app (/api/v1/guest/stays/{token}/…) and the booking page
 * (/stay/{token}/…). The booking's private token is the guest's key, as for the rest of their booking.
 * Answers {success, message, data} like the rest of the API.
 */
class GuestChatController extends Controller
{
    public function __construct(private ChatService $chat, private CallService $calls)
    {
    }

    /** GET …/chat — every section, open or closed, with unread counts. */
    public function index(string $token)
    {
        return $this->ok($this->chat->forGuest($this->reservation($token)));
    }

    /** GET …/chat/{section}?after=id — the thread (new messages only with after). */
    public function thread(Request $request, string $token, int $section)
    {
        $r = $this->reservation($token);

        return $this->ok($this->chat->threadForGuest($r, $this->section($section), $request->integer('after') ?: null));
    }

    /** POST …/chat/{section} {body, client_id, photo} */
    public function send(Request $request, string $token, int $section)
    {
        $r = $this->reservation($token);
        $d = $request->validate(['body' => 'nullable|string|max:' . ChatService::MAX_LENGTH, 'client_id' => 'nullable|uuid', 'photo' => 'nullable|image|max:8192',
            'audio' => 'nullable|file|max:6144|mimetypes:' . ChatService::AUDIO_TYPES, 'audio_seconds' => 'nullable|integer|min:1|max:300']);
        try {
            $m = $this->chat->guestSend($r, $this->section($section), $d['body'] ?? null, $request->file('photo'), $d['client_id'] ?? null, $request->file('audio'), isset($d['audio_seconds']) ? (int) $d['audio_seconds'] : null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok(['message' => $this->chat->messageJson($m, 'guest')], status: 201);
    }

    /** POST …/chat/{section}/typing */
    public function typing(string $token, int $section)
    {
        $r = $this->reservation($token);
        $this->chat->typing($this->chat->conversation($r, $this->section($section)), 'guest');

        return $this->ok(null);
    }

    // ── Calls ─────────────────────────────────────────────────────────

    /** POST …/calls {section_id} — call a section. */
    public function call(Request $request, string $token)
    {
        $r = $this->reservation($token);
        $d = $request->validate(['section_id' => 'required|integer']);
        try {
            $call = $this->calls->guestCalls($r, $this->section($d['section_id']));
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->calls->callJson($call, 'guest'), status: 201);
    }

    /** GET …/calls/ringing — a call back from the hotel ringing for this stay (polling fallback). */
    public function ringing(string $token)
    {
        $r = $this->reservation($token);
        $this->calls->expire();
        $call = GuestCall::where('status', 'ringing')->where('direction', 'out')
            ->whereHas('conversation', fn ($q) => $q->where('reservation_id', $r->id))->latest('id')->first();

        return $this->ok($call ? $this->calls->callJson($call, 'guest') : null);
    }

    /** GET …/calls/{uuid}?after=id — the call and the hotel's signals since. */
    public function show(Request $request, string $token, string $uuid)
    {
        $call = $this->callFor($token, $uuid);
        $this->calls->expire($call);

        return $this->ok($this->calls->callJson($call->fresh(), 'guest') + [
            'signals' => $this->calls->signals($call, 'guest', $request->integer('after'))->map(fn ($s) => ['id' => $s->id, 'kind' => $s->kind, 'payload' => $s->payload])->values(),
        ]);
    }

    /** POST …/calls/{uuid}/signal {kind, payload} */
    public function signal(Request $request, string $token, string $uuid)
    {
        $call = $this->callFor($token, $uuid);
        $d = $request->validate(['kind' => 'required|in:offer,answer,candidate', 'payload' => 'nullable|array', 'leg' => 'nullable|integer|min:1']);
        try {
            $s = $this->calls->signal($call, 'guest', $d['kind'], $d['payload'] ?? null, null, isset($d['leg']) ? (int) $d['leg'] : null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok(['id' => $s->id]);
    }

    /** POST …/calls/{uuid}/answer — the guest picks up a call back. */
    public function answer(string $token, string $uuid)
    {
        try {
            $call = $this->calls->answer($this->callFor($token, $uuid), 'guest');
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->calls->callJson($call, 'guest'));
    }

    /** POST …/calls/{uuid}/end {reason} */
    public function end(Request $request, string $token, string $uuid)
    {
        $call = $this->calls->end($this->callFor($token, $uuid), 'guest', null, $request->input('reason') === 'declined' ? 'declined' : 'hung_up');

        return $this->ok($this->calls->callJson($call, 'guest'));
    }

    // ------------------------------------------------------------------

    private function reservation(string $token): Reservation
    {
        abort_unless(strlen($token) === 48, 404);
        $r = Reservation::withoutGlobalScope('hotel')->where('guest_token', $token)->firstOrFail();
        CurrentHotel::get()->adopt($r);

        return $r;
    }

    private function section(int $id): ChatSection
    {
        $this->chat->sections();   // a hotel's first chat sets up its sections

        return ChatSection::findOrFail($id);
    }

    private function callFor(string $token, string $uuid): GuestCall
    {
        $r = $this->reservation($token);

        return GuestCall::where('uuid', $uuid)->whereHas('conversation', fn ($q) => $q->where('reservation_id', $r->id))->firstOrFail();
    }

    private function ok(mixed $data, ?string $message = null, int $status = 200)
    {
        return response()->json(array_filter(['success' => true, 'message' => $message, 'data' => $data], fn ($v) => $v !== null) + ['data' => $data], $status);
    }

    private function fail(string $message, int $status = 422)
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
