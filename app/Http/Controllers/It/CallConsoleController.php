<?php

namespace App\Http\Controllers\It;

use App\Exceptions\BookingException;
use App\Http\Controllers\Chat\ChatAudioController;
use App\Http\Controllers\Controller;
use App\Models\Chat\CallListen;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatSection;
use App\Models\Chat\GuestCall;
use App\Models\User;
use App\Services\Chat\CallService;
use App\Services\Facility\FacilityService;
use App\Services\It\CallConsoleService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The call console (IT Control Room → Console): a cockpit-style screen for IT and security operators.
 * Every guest call ringing or on, picking up a call nobody answers (with a headset), putting calls
 * through to the right person, listening in (permission 'Listen to guest calls', always audited,
 * callers are told), voice messages, network strength, the weather, alerts, and the on/off board
 * of every room's equipment and the hotel's other areas.
 */
class CallConsoleController extends Controller
{
    public function __construct(private CallConsoleService $console, private CallService $calls)
    {
        $this->middleware('permission:Use call console');
        $this->middleware('permission:Answer any guest call')->only('answer', 'transfer', 'targets', 'end');
        $this->middleware('permission:Listen to guest calls')->only('listen', 'listenShow', 'listenSignal', 'listenEnd', 'voice');
    }

    public function index(Request $request)
    {
        $pagetitle = 'Call Console';
        $user = $request->user();

        return view('it.console', [
            'pagetitle' => $pagetitle,
            'monitoring' => $this->calls->monitoringOn(),
            'notice' => (string) config('hotel.call_console.monitoring_notice'),
            'escalate' => (int) config('hotel.call_console.escalate_after', 20),
            'canAnswer' => $user->can('Answer any guest call'),
            'canListen' => $user->can('Listen to guest calls') && $this->calls->monitoringOn(),
            'canEquipment' => $user->can('Manage room equipment'),
            'hotelName' => (string) config('hotel.name'),
        ]);
    }

    /** GET console/feed — calls, voice messages, alerts (polled every few seconds). */
    public function feed(Request $request)
    {
        return $this->ok($this->console->feed($request->user()));
    }

    /** GET console/board — the equipment board and network strength (polled less often). */
    public function board(FacilityService $facility)
    {
        return $this->ok(['board' => $facility->board(), 'network' => $this->console->network()]);
    }

    public function weather()
    {
        return $this->ok(Cache::remember('console-weather:' . (\App\Support\Tenancy\CurrentHotel::get()->id() ?? 0), now()->addMinutes(10), fn () => $this->console->weather()));
    }

    // ── Calls: the console is a hotel-side phone for any section ─────

    /** GET console/calls/{uuid}?after=&after_tap= — what HotelCall polls. */
    public function show(Request $request, string $uuid)
    {
        return $this->ok($this->calls->staffView($this->call($uuid), $request->user(), $request->integer('after'), $request->integer('after_tap')));
    }

    public function answer(Request $request, string $uuid)
    {
        try {
            $call = $this->calls->answer($this->call($uuid), 'staff', $request->user());
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 409);
        }

        return $this->ok($this->calls->callJson($call, 'staff'));
    }

    public function signal(Request $request, string $uuid)
    {
        $d = $request->validate(['kind' => 'required|in:offer,answer,candidate', 'payload' => 'nullable|array', 'leg' => 'nullable|integer|min:1']);
        $call = $this->call($uuid);
        if ($call->answered_by && $call->answered_by !== $request->user()->id) {
            return $this->fail('Someone else is on this call.', 409);
        }
        try {
            $s = $this->calls->signal($call, 'staff', $d['kind'], $d['payload'] ?? null, $request->user(), isset($d['leg']) ? (int) $d['leg'] : null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok(['id' => $s->id]);
    }

    /** End a call (operators can also end a ringing call — "busy"). */
    public function end(Request $request, string $uuid)
    {
        $call = $this->call($uuid);
        $reason = $request->input('reason') === 'declined' ? 'declined' : 'hung_up';
        if ($call->answered_by !== $request->user()->id) {
            Audit::record('calls.end', 'Ended a guest call from the call console' . ($call->answeredBy ? " (was with {$call->answeredBy->name})" : ''), $call->conversation);
        }

        return $this->ok($this->calls->callJson($this->calls->end($call, 'staff', $request->user(), $reason), 'staff'));
    }

    public function targets(Request $request, string $uuid)
    {
        return $this->ok($this->console->targets($this->call($uuid), $request->user()));
    }

    /** POST console/calls/{uuid}/transfer {user_id | section_id, note} */
    public function transfer(Request $request, string $uuid)
    {
        $d = $request->validate(['user_id' => 'nullable|integer', 'section_id' => 'nullable|integer', 'note' => 'nullable|string|max:160']);
        try {
            $call = $this->calls->transfer($this->call($uuid), $request->user(), ! empty($d['user_id']) ? User::findOrFail($d['user_id']) : null,
                ! empty($d['section_id']) ? ChatSection::findOrFail($d['section_id']) : null, $d['note'] ?? null);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->calls->callJson($call, 'staff'));
    }

    /** POST console/calls/{uuid}/tap/{listen} — when the operator is on the call and a supervisor listens in. */
    public function tap(Request $request, string $uuid, int $listen)
    {
        $d = $request->validate(['kind' => 'required|in:offer,answer,candidate', 'payload' => 'nullable|array']);
        $l = CallListen::whereKey($listen)->whereHas('call', fn ($q) => $q->where('uuid', $uuid))->firstOrFail();
        try {
            $s = $this->calls->listenSignal($l, 'tap', $d['kind'], $d['payload'] ?? null, $request->user());
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 409);
        }

        return $this->ok(['id' => $s->id]);
    }

    // ── Listening in ─────────────────────────────────────────────────

    /** POST console/calls/{uuid}/listen {reason} */
    public function listen(Request $request, string $uuid)
    {
        $d = $request->validate(['reason' => 'required|string|min:3|max:250']);
        try {
            $l = $this->calls->listen($this->call($uuid), $request->user(), $d['reason']);
        } catch (BookingException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->ok($this->listenJson($l), 201);
    }

    /** GET console/listens/{listen}?after= — the hotel side's offer and candidates for the listener. */
    public function listenShow(Request $request, CallListen $listen)
    {
        abort_unless($listen->user_id === $request->user()->id, 403);
        $this->calls->expire($listen->call);
        $listen->refresh();
        $call = $listen->call;
        if ($listen->isOpen() && (! $call->isLive() || $call->leg !== $listen->leg)) {
            $this->calls->endListen($listen, $call->isLive() ? 'transferred' : 'call_ended');
            $listen->refresh();
        }

        return $this->ok($this->listenJson($listen) + [
            'signals' => $listen->isOpen() ? \App\Models\Chat\GuestCallSignal::where('listen_id', $listen->id)->where('side', 'tap')->where('id', '>', $request->integer('after'))
                ->orderBy('id')->get()->map(fn ($s) => ['id' => $s->id, 'kind' => $s->kind, 'payload' => $s->payload])->values() : [],
        ]);
    }

    public function listenSignal(Request $request, CallListen $listen)
    {
        $d = $request->validate(['kind' => 'required|in:offer,answer,candidate', 'payload' => 'nullable|array']);
        try {
            $s = $this->calls->listenSignal($listen, 'monitor', $d['kind'], $d['payload'] ?? null, $request->user());
        } catch (BookingException $e) {
            return $this->fail($e->getMessage(), 409);
        }

        return $this->ok(['id' => $s->id]);
    }

    public function listenEnd(Request $request, CallListen $listen)
    {
        abort_unless($listen->user_id === $request->user()->id, 403);

        return $this->ok($this->listenJson($this->calls->endListen($listen, 'stopped')));
    }

    /** GET console/voice/{message} — play a guest's voice message (each play is in the audit trail). */
    public function voice(ChatMessage $message)
    {
        abort_unless($message->audio && $message->conversation && Storage::disk('local')->exists($message->audio), 404);
        Audit::record('calls.voice_played', 'Played a voice message from ' . ($message->conversation->reservation?->guest?->full_name ?? 'a guest') . ' (' . $message->conversation->section?->name . ')', $message->conversation);

        return ChatAudioController::stream($message);
    }

    // ------------------------------------------------------------------

    private function listenJson(CallListen $l): array
    {
        $call = $l->call;

        return ['id' => $l->id, 'status' => $l->status, 'end_reason' => $l->end_reason, 'call' => $call->uuid, 'leg' => $l->leg,
            'ice_servers' => $l->isOpen() ? $this->calls->iceServers('monitor-' . $l->id) : [],
            'with' => $call->answeredBy?->name, 'section' => $call->conversation?->section?->name];
    }

    private function call(string $uuid): GuestCall
    {
        return GuestCall::where('uuid', $uuid)->firstOrFail();
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
