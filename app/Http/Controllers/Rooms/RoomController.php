<?php

namespace App\Http\Controllers\Rooms;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Individual rooms, the live room board, and room status changes. */
class RoomController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View rooms', ['only' => ['index', 'show']]);
        $this->middleware('permission:Create rooms', ['only' => ['create', 'store', 'bulkCreate', 'bulkStore']]);
        $this->middleware('permission:Update rooms', ['only' => ['edit', 'update']]);
        $this->middleware('permission:Delete rooms', ['only' => ['destroy']]);
        $this->middleware('permission:Change room status', ['only' => ['changeStatus', 'bulkStatus']]);
    }

    /** Room board (default) or table list (?view=list), with filters. */
    public function index(Request $request)
    {
        $view = $request->query('view') === 'list' ? 'list' : 'board';
        $pagetitle = $view === 'list' ? 'Room List' : 'Room Board';

        $filters = $request->only(['building', 'floor', 'type', 'status', 'q', 'inactive']);

        $rooms = Room::query()
            ->with(['building', 'floor', 'roomType', 'statusChangedBy'])
            ->when($filters['building'] ?? null, fn ($q, $v) => $q->where('building_id', $v))
            ->when($filters['floor'] ?? null, fn ($q, $v) => $q->where('floor_id', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('room_type_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('number', 'like', '%' . $v . '%'))
            ->when(empty($filters['inactive']), fn ($q) => $q->where('is_active', true))
            ->get()
            // Natural sort by building, floor level, then room number ("2" before "10").
            ->sort(function (Room $a, Room $b) {
            return [$a->building->sort_order, $a->building->name, $a->floor->level]
                <=> [$b->building->sort_order, $b->building->name, $b->floor->level]
                ?: strnatcasecmp($a->number, $b->number);
        })->values();

        // Status counts across ALL active rooms (not just the filtered set).
        $statusCounts = Room::active()->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status');
        $totalActive = $statusCounts->sum();

        $buildings = Building::ordered()->with('floors')->get();
        $roomTypes = RoomType::orderBy('name')->get();

        return view('rooms.index', compact(
            'pagetitle', 'view', 'filters', 'rooms', 'statusCounts', 'totalActive', 'buildings', 'roomTypes'
        ));
    }

    public function create(Request $request)
    {
        $this->ensureSetup();

        $pagetitle = 'Add Room';
        $room = new Room([
            'building_id'  => $request->query('building'),
            'floor_id'     => $request->query('floor'),
            'status'       => RoomStatus::Available,
            'is_active'    => true,
        ]);

        return view('rooms.form', $this->formData($pagetitle, $room));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $room = Room::create($data + ['status' => RoomStatus::Available]);
        $room->statusLogs()->create([
            'from_status' => null,
            'to_status'   => RoomStatus::Available->value,
            'reason'      => 'Room created',
            'user_id'     => auth()->id(),
        ]);

        return redirect()->route('rooms.show', $room)->with('success', "Room {$room->number} added.");
    }

    public function bulkCreate()
    {
        $this->ensureSetup();

        $pagetitle = 'Add Rooms in Bulk';

        return view('rooms.bulk', $this->formData($pagetitle, new Room()));
    }

    /** Create a run of rooms, e.g. 101–120 on Floor 1, all Deluxe. Existing numbers are skipped. */
    public function bulkStore(Request $request)
    {
        $data = $request->validate([
            'building_id'  => 'required|exists:buildings,id',
            'floor_id'     => ['required', Rule::exists('floors', 'id')->where('building_id', $request->input('building_id'))],
            'room_type_id' => 'required|exists:room_types,id',
            'prefix'       => 'nullable|string|max:5|alpha_dash',
            'start'        => 'required|integer|min:0|max:99999',
            'end'          => 'required|integer|gte:start|max:99999',
        ], ['floor_id.exists' => 'Pick a floor that belongs to the selected building.']);

        if ($data['end'] - $data['start'] >= 200) {
            return back()->withInput()->withErrors(['end' => 'You can create at most 200 rooms at a time.']);
        }

        $prefix = strtoupper($data['prefix'] ?? '');
        $numbers = collect(range($data['start'], $data['end']))->map(fn ($n) => $prefix . $n);
        $existing = Room::whereIn('number', $numbers)->pluck('number');

        DB::transaction(function () use ($numbers, $existing, $data) {
            foreach ($numbers->diff($existing) as $number) {
                $room = Room::create([
                    'number'       => $number,
                    'building_id'  => $data['building_id'],
                    'floor_id'     => $data['floor_id'],
                    'room_type_id' => $data['room_type_id'],
                    'status'       => RoomStatus::Available,
                    'is_active'    => true,
                ]);
                $room->statusLogs()->create([
                    'to_status' => RoomStatus::Available->value,
                    'reason'    => 'Room created (bulk)',
                    'user_id'   => auth()->id(),
                ]);
            }
        });

        $created = $numbers->count() - $existing->count();
        $message = "{$created} room(s) created.";
        if ($existing->isNotEmpty()) {
            $message .= ' Skipped existing: ' . $existing->implode(', ') . '.';
        }

        return redirect()->route('rooms.index', ['building' => $data['building_id']])->with('success', $message);
    }

    public function show(Room $room)
    {
        $room->load(['building', 'floor', 'roomType.photos', 'statusChangedBy']);
        $pagetitle = 'Room ' . $room->number;
        $logs = $room->statusLogs()->with('user')->paginate(15);

        // Current and upcoming stays in this room.
        $bookings = auth()->user()->can('View reservations')
            ? \App\Models\Reservation::open()
                ->where('check_out_date', '>', today()->toDateString())
                ->whereHas('rooms', fn ($q) => $q->where('room_id', $room->id))
                ->with('guest')->orderBy('check_in_date')->limit(10)->get()
            : collect();

        // Cleaning and repairs (spec §3.3, §3.4): what's open now and the repair history.
        $cleaning = \App\Models\HousekeepingTask::open()->where('room_id', $room->id)->with('assignee:id,name')->get();
        $jobs = auth()->user()->canAny(['View maintenance', 'Manage maintenance', 'Report maintenance issues'])
            ? \App\Models\MaintenanceOrder::where('room_id', $room->id)->latest()->limit(10)->get()
            : collect();

        return view('rooms.show', compact('pagetitle', 'room', 'logs', 'bookings', 'cleaning', 'jobs'));
    }

    public function edit(Room $room)
    {
        return view('rooms.form', $this->formData('Edit Room ' . $room->number, $room));
    }

    public function update(Request $request, Room $room)
    {
        $room->update($this->validated($request, $room));

        return redirect()->route('rooms.show', $room)->with('success', "Room {$room->number} updated.");
    }

    public function destroy(Room $room)
    {
        if (in_array($room->status, [RoomStatus::Occupied, RoomStatus::Reserved], true)) {
            return back()->with('error', "Room {$room->number} is {$room->status->label()} and can't be deleted. Mark it inactive instead.");
        }

        $number = $room->number;
        $room->delete();

        return redirect()->route('rooms.index')->with('success', "Room {$number} deleted.");
    }

    public function changeStatus(Request $request, Room $room)
    {
        $data = $this->validateStatus($request);

        $changed = $room->changeStatus(RoomStatus::from($data['status']), $data['reason'] ?? null);

        $message = $changed
            ? "Room {$room->number} is now {$room->status->label()}."
            : "Room {$room->number} was already {$room->status->label()}.";

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message, 'status' => $room->status->value])
            : back()->with('success', $message);
    }

    /** Set the same status on several rooms at once (e.g. a floor closed for repainting). */
    public function bulkStatus(Request $request)
    {
        $data = $this->validateStatus($request) + $request->validate([
            'rooms'   => 'required|array|min:1',
            'rooms.*' => 'integer|exists:rooms,id',
        ]);

        $status = RoomStatus::from($data['status']);
        $count = 0;
        DB::transaction(function () use ($data, $status, &$count) {
            foreach (Room::whereIn('id', $data['rooms'])->get() as $room) {
                $count += (int) $room->changeStatus($status, $data['reason'] ?? null);
            }
        });

        return back()->with('success', "{$count} room(s) set to {$status->label()}.");
    }

    // ------------------------------------------------------------------

    private function validateStatus(Request $request): array
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(RoomStatus::class)],
            'reason' => 'nullable|string|max:500',
        ]);

        if (RoomStatus::from($data['status'])->requiresReason() && blank($data['reason'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'reason' => 'Please give a reason when taking a room out of service or into maintenance.',
            ]);
        }

        return $data;
    }

    private function validated(Request $request, ?Room $room = null): array
    {
        $data = $request->validate([
            'number'        => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\/ ]+$/', Rule::unique('rooms', 'number')->ignore($room?->id)],
            'building_id'   => 'required|exists:buildings,id',
            'floor_id'      => ['required', Rule::exists('floors', 'id')->where('building_id', $request->input('building_id'))],
            'room_type_id'  => 'required|exists:room_types,id',
            'rate_override' => 'nullable|numeric|min:0|max:99999999',
            'features'      => 'nullable|string|max:500',
            'notes'         => 'nullable|string|max:2000',
        ], ['floor_id.exists' => 'Pick a floor that belongs to the selected building.']);

        $data['number'] = strtoupper(trim($data['number']));
        $data['features'] = collect(explode(',', (string) ($data['features'] ?? '')))->map(fn ($f) => trim($f))->filter()->values()->all();
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function formData(string $pagetitle, Room $room): array
    {
        return [
            'pagetitle' => $pagetitle,
            'room'      => $room,
            'buildings' => Building::ordered()->where('is_active', true)->with('floors')->get(),
            'roomTypes' => RoomType::where('is_active', true)->orderBy('name')->get(),
        ];
    }

    /** Rooms need a building, a floor and a room type first. */
    private function ensureSetup(): void
    {
        $missing = match (true) {
            ! Building::exists()  => 'a building',
            ! Floor::exists()     => 'a floor',
            ! RoomType::exists()  => 'a room type',
            default               => null,
        };

        if ($missing) {
            abort(redirect()->route($missing === 'a room type' ? 'room-types.index' : 'buildings.index')
                ->with('error', "Add {$missing} before adding rooms."));
        }
    }
}
