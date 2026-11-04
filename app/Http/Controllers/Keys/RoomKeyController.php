<?php

namespace App\Http\Controllers\Keys;

use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Keys\DoorEvent;
use App\Models\Keys\RoomKey;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\Keys\RoomKeyService;
use App\Support\Audit;
use Illuminate\Http\Request;

/** Room keys for the front desk: who has which key, new codes, key cards, opening a door; linking locks; the door log. */
class RoomKeyController extends Controller
{
    public function __construct(private RoomKeyService $keys)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['Manage room keys', 'View door log']), 403);
        $stays = Reservation::where('status', ReservationStatus::CheckedIn)->with(['guest:id,first_name,last_name,is_vip', 'rooms.room:id,number,lock_id'])->get()
            ->sortBy(fn ($r) => $r->rooms->pluck('room.number')->filter()->first(), SORT_NATURAL)->values();
        $keys = RoomKey::whereIn('reservation_id', $stays->pluck('id'))->whereIn('status', ['active', 'failed'])->orderBy('kind')->get()->groupBy(fn ($k) => $k->reservation_id . ':' . $k->room_id);

        return view('keys.index', [
            'pagetitle' => 'Room Keys', 'stays' => $stays, 'keys' => $keys, 'svc' => $this->keys, 'provider' => $this->keys->provider(),
            'canManage' => $this->keys->canManage($request->user()), 'unlinked' => Room::whereNull('lock_id')->count(),
        ]);
    }

    public function act(Request $request, Reservation $reservation, Room $room, string $action)
    {
        $data = $request->validate(['card_number' => 'nullable|string|max:40', 'reason' => 'nullable|string|max:255']);
        try {
            $msg = match ($action) {
                'pin'    => 'New door code for room ' . $room->number . ': ' . $this->keys->newPin($reservation, $room, $request->user())->secret . '. Tell the guest — it is also in their app.',
                'card'   => tap("Key card recorded for room {$room->number}.", fn () => $this->keys->recordCard($reservation, $room, (string) ($data['card_number'] ?? ''), $request->user())),
                'unlock' => ! empty($data['reason']) ? tap("Room {$room->number}'s door is open.", fn () => $this->keys->staffUnlock($room, $request->user(), $data['reason'])) : throw new BookingException('Say why you are opening the door.'),
                default  => abort(404),
            };
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $msg);
    }

    public function revoke(Request $request, RoomKey $key)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255', 'lost' => 'nullable|boolean']);
        try {
            $this->keys->revoke($key, $request->user(), trim((string) ($data['reason'] ?? '')) ?: ($request->boolean('lost') ? 'Reported lost' : 'Cancelled at the desk'), $request->boolean('lost'));
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $request->boolean('lost') ? 'Marked lost.' : 'Key cancelled.');
    }

    public function locks(Request $request)
    {
        abort_unless($this->keys->canManage($request->user()), 403);

        return view('keys.locks', ['pagetitle' => 'Door Locks', 'rooms' => Room::with('floor:id,name')->orderBy('number')->get()->sortBy('number', SORT_NATURAL)->values(), 'provider' => $this->keys->provider(), 'svc' => $this->keys]);
    }

    public function saveLocks(Request $request)
    {
        abort_unless($this->keys->canManage($request->user()), 403);
        $data = $request->validate(['lock' => 'array', 'lock.*' => 'nullable|string|max:60']);
        $n = 0;
        foreach (Room::whereIn('id', array_keys($data['lock'] ?? []))->get() as $room) {
            $v = trim((string) ($data['lock'][$room->id] ?? '')) ?: null;
            if ($room->lock_id !== $v) {
                $room->forceFill(['lock_id' => $v])->save();
                $n++;
            }
        }
        Audit::record('keys.locks', "Linked door locks ({$n} rooms changed)", by: $request->user());

        return back()->with('success', $n ? "{$n} " . str('room')->plural($n) . ' updated.' : 'Nothing changed.');
    }

    public function log(Request $request)
    {
        abort_unless($request->user()->canAny(['View door log', 'Manage room keys']), 403);
        $room = $request->integer('room') ?: null;
        $events = DoorEvent::with(['room:id,number', 'user:id,name'])->when($room, fn ($q, $v) => $q->where('room_id', $v))->latest('at')->paginate(50)->withQueryString();

        return view('keys.log', ['pagetitle' => 'Door Log', 'events' => $events, 'rooms' => Room::whereNotNull('lock_id')->orderBy('number')->get(['id', 'number']), 'room' => $room]);
    }

    public function sync(Request $request)
    {
        abort_unless($request->user()->canAny(['View door log', 'Manage room keys']), 403);

        return back()->with('success', $this->keys->syncRecords() . ' new door record(s) read from the locks.');
    }
}
