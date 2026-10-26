<?php

namespace App\Http\Controllers\Channels;

use App\Http\Controllers\Controller;
use App\Models\Channels\ChannelBooking;
use App\Models\Channels\ChannelSyncLog;
use App\Models\Channels\IcalFeed;
use App\Models\Room;
use App\Services\Channels\ChannelException;
use App\Services\Channels\ChannelManager;
use App\Services\Channels\IcalService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Front Desk → Room Calendars: iCal calendars per room for Airbnb, Vrbo and similar sites, the bookings
 * they brought in, and the sync log.
 */
class ChannelController extends Controller
{
    public function __construct(private ChannelManager $manager)
    {
        $this->middleware('permission:View room calendars|Manage room calendars', ['only' => ['index']]);
        $this->middleware('permission:Manage room calendars', ['except' => ['index', 'export']]);
    }

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), ['calendars', 'bookings', 'log'], true) ? $request->query('tab') : 'calendars';
        $status = $this->manager->status();
        $data = compact('tab', 'status') + ['pagetitle' => 'Room Calendars'];

        if ($tab === 'bookings') {
            $show = $request->query('show', 'all');
            $data['show'] = $show;
            $data['bookings'] = ChannelBooking::with(['reservation.guest', 'reservation.rooms.roomType', 'feed.room'])
                ->when($show === 'attention', fn ($q) => $q->where(fn ($w) => $w->where('result', 'failed')->orWhere('overbooked', true)))
                ->latest('received_at')->paginate(25)->withQueryString();
        }
        if ($tab === 'calendars') {
            $data['feeds'] = IcalFeed::with('room.roomType')->orderBy('room_id')->get();
            $data['rooms'] = Room::active()->with('roomType')->get()->sort(fn ($a, $b) => strnatcasecmp($a->number, $b->number))->values();
        }
        if ($tab === 'log') {
            $data['logs'] = ChannelSyncLog::latest('created_at')->paginate(50)->withQueryString();
        }

        return view('channels.index', $data);
    }

    /** Read every calendar now. */
    public function pull()
    {
        $done = $this->manager->run(true);
        $msg = "Calendars read: {$done['calendars']} change(s).";

        return back()->with($done['errors'] ? 'error' : 'success', $done['errors'] ? $msg . ' Problems: ' . implode(' ', $done['errors']) : $msg);
    }

    // ── Calendars ─────────────────────────────────────────────────────

    public function storeFeed(Request $request)
    {
        $data = $this->feedData($request);
        $feed = IcalFeed::create($data + ['created_by' => $request->user()->id]);
        Audit::record('channels.ical', "Calendar added: {$feed->name} for room {$feed->room->number}", $feed);

        return redirect()->route('channels.index', ['tab' => 'calendars'])->with('success', "Calendar added. Give {$feed->name} the address under “Our calendar”" . ($feed->import_url ? '; their bookings are read every ' . config('hotel.channels.ical.every_minutes', 15) . ' minutes.' : '.'));
    }

    public function updateFeed(Request $request, IcalFeed $feed)
    {
        $feed->update($this->feedData($request) + ['is_active' => $request->boolean('is_active')]);

        return redirect()->route('channels.index', ['tab' => 'calendars'])->with('success', 'Calendar saved.');
    }

    public function destroyFeed(IcalFeed $feed)
    {
        Audit::record('channels.ical', "Calendar removed: {$feed->name} for room {$feed->room?->number}", $feed);
        $feed->delete();

        return redirect()->route('channels.index', ['tab' => 'calendars'])->with('success', 'Calendar removed. Bookings it brought in are kept.');
    }

    public function syncFeed(IcalFeed $feed, IcalService $ical)
    {
        try {
            $n = $ical->import($feed);
        } catch (ChannelException $e) {
            return back()->with('error', "{$feed->name}: " . $e->getMessage());
        }

        return back()->with('success', "{$feed->name} read: {$n} change(s).");
    }

    /** Public: our calendar for one room (the address given to Airbnb, Vrbo ...). */
    public function export(string $token, IcalService $ical)
    {
        $feed = IcalFeed::with('room')->where('export_token', $token)->where('is_active', true)->firstOrFail();

        return response($ical->export($feed), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="room-' . $feed->room->number . '.ics"',
            'Cache-Control' => 'no-cache, max-age=0',
        ]);
    }

    private function feedData(Request $request): array
    {
        return $request->validate([
            'room_id'    => 'required|integer|exists:rooms,id',
            'name'       => 'required|string|max:60',
            'import_url' => ['nullable', 'url:http,https', 'max:1000'],
        ], ['import_url.url' => 'Paste the full calendar address, starting with https://.']);
    }
}
