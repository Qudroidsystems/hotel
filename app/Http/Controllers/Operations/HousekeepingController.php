<?php

namespace App\Http\Controllers\Operations;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Enums\RoomStatus;
use App\Models\HousekeepingTask;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Operations\HousekeepingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Housekeeping board: rooms to clean, being cleaned, awaiting inspection, ready. */
class HousekeepingController extends Controller
{
    public function __construct(private HousekeepingService $service)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canAny(['View housekeeping', 'Do housekeeping tasks', 'Manage housekeeping']), 403);
        $manager = $user->can('Manage housekeeping');
        $everyone = $manager || $user->can('View housekeeping');
        $mine = ! $everyone || $request->boolean('mine');
        $this->service->syncDirtyRooms();

        $open = HousekeepingTask::open()->with(['room.floor', 'room.building', 'room.roomType', 'assignee:id,name'])
            ->when($mine, fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhereNull('assigned_to')))
            ->get()
            ->sortBy([['urgent', 'desc'], ['created_at', 'asc']]);

        $readyToday = HousekeepingTask::where('status', HousekeepingTask::PASSED)->where('updated_at', '>=', today())
            ->with(['room', 'finishedBy:id,name', 'inspectedBy:id,name'])->latest('updated_at')->get();
        $turnarounds = $readyToday->map(fn ($t) => $t->turnaroundMinutes())->filter();

        $columns = [
            HousekeepingTask::PENDING     => ['To clean', 'ri-brush-line', $open->where('status', HousekeepingTask::PENDING)],
            HousekeepingTask::IN_PROGRESS => ['Being cleaned', 'ri-hand-sanitizer-line', $open->where('status', HousekeepingTask::IN_PROGRESS)],
            HousekeepingTask::DONE        => ['Awaiting inspection', 'ri-search-eye-line', $open->where('status', HousekeepingTask::DONE)],
        ];
        $stats = [
            'pending'    => $columns[HousekeepingTask::PENDING][2]->count(),
            'cleaning'   => $columns[HousekeepingTask::IN_PROGRESS][2]->count(),
            'inspection' => $columns[HousekeepingTask::DONE][2]->count(),
            'ready'      => $readyToday->count(),
            'turnaround' => $turnarounds->isNotEmpty() ? (int) round($turnarounds->avg()) : null,
        ];

        $staff = $manager ? User::permission(['Do housekeeping tasks'])->where('is_disabled', false)->orderBy('name')->get(['id', 'name']) : collect();
        $rooms = $manager || $user->can('Report maintenance issues') ? Room::active()->orderBy('number')->get(['id', 'number', 'status']) : collect();
        $pagetitle = $everyone ? 'Housekeeping' : 'My Rooms to Clean';

        return view('housekeeping.index', compact('pagetitle', 'columns', 'readyToday', 'stats', 'manager', 'everyone', 'mine', 'staff', 'rooms'));
    }

    /**
     * The tablet board: every room as a big tile, coloured by what it needs,
     * for the housekeeping office or a cleaner's tablet. Refreshes itself;
     * tapping a room starts, finishes or inspects its clean.
     */
    public function board(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canAny(['View housekeeping', 'Do housekeeping tasks', 'Manage housekeeping']), 403);
        $manager = $user->can('Manage housekeeping');
        $mine = $request->boolean('mine') || ! ($manager || $user->can('View housekeeping'));
        $this->service->syncDirtyRooms();

        $tasks = HousekeepingTask::open()->with('assignee:id,name')->get()
            ->sortBy([['urgent', 'desc'], ['created_at', 'asc']])->groupBy('room_id');
        $arriving = Reservation::arrivingOn(today())->with('rooms:id,reservation_id,room_id', 'guest:id,first_name,last_name,is_vip')->get()
            ->flatMap(fn (Reservation $r) => $r->rooms->pluck('room_id')->filter()->mapWithKeys(fn ($id) => [$id => $r]));
        $leaving = Reservation::departingOn(today())->with('rooms:id,reservation_id,room_id')->get()
            ->flatMap(fn (Reservation $r) => $r->rooms->pluck('room_id')->filter())->flip();

        $tiles = Room::active()->with(['floor:id,name,level', 'building:id,name', 'roomType:id,name'])->get()
            ->map(function (Room $room) use ($tasks, $arriving, $leaving, $user) {
                $task = $tasks->get($room->id)?->first();
                $state = match (true) {
                    $task?->status === HousekeepingTask::PENDING     => 'clean',
                    $task?->status === HousekeepingTask::IN_PROGRESS => 'cleaning',
                    $task?->status === HousekeepingTask::DONE        => 'inspect',
                    in_array($room->status, [RoomStatus::Maintenance, RoomStatus::OutOfService], true) => 'blocked',
                    $room->status === RoomStatus::Occupied           => 'occupied',
                    default                                          => 'ready',
                };

                $guest = $arriving->get($room->id);

                return (object) [
                    'room' => $room, 'task' => $task, 'state' => $state,
                    'arriving' => $guest, 'leaving' => $leaving->has($room->id),
                    'coming' => $guest ? app(\App\Services\Booking\ArrivalService::class)->forStaff($guest) : null,
                    'mine' => $task && (int) $task->assigned_to === (int) $user->id,
                ];
            })
            ->when($mine, fn ($c) => $c->filter(fn ($t) => $t->task && in_array($t->task->assigned_to, [null, $user->id])))
            ->sortBy(fn ($t) => sprintf('%04d|%s|%08s', $t->room->floor?->level ?? 999, $t->room->building?->name, $t->room->number));

        $floors = $tiles->groupBy(fn ($t) => trim(($tiles->pluck('room.building_id')->unique()->count() > 1 ? ($t->room->building?->name . ' · ') : '') . ($t->room->floor?->name ?? 'Other rooms')));
        $counts = $tiles->countBy('state');
        $staff = $manager ? User::permission(['Do housekeeping tasks'])->where('is_disabled', false)->orderBy('name')->get(['id', 'name']) : collect();
        $inspection = (bool) config('hotel.housekeeping.require_inspection', true);

        $data = compact('floors', 'counts', 'manager', 'mine', 'staff', 'inspection');
        if ($request->boolean('partial')) {
            return response()->view('housekeeping.partials.board-grid', $data);
        }

        return view('housekeeping.board', $data + ['pagetitle' => 'Housekeeping board']);
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'room_id'     => 'required|integer|exists:rooms,id',
            'type'        => ['required', Rule::in(array_keys(HousekeepingTask::TYPES))],
            'notes'       => 'nullable|string|max:500',
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        return $this->attempt(fn () => $this->service->raise(Room::findOrFail($data['room_id']), $data['type'], null, $data['notes'] ?? null, $data['assigned_to'] ?? null), 'Task added.');
    }

    public function stayovers(Request $request)
    {
        $this->authorizeManage($request);
        $count = $this->service->raiseStayovers();

        return back()->with('success', $count ? "{$count} stay-over task(s) added for occupied rooms." : 'Every occupied room already has a stay-over task.');
    }

    public function assign(Request $request, HousekeepingTask $task)
    {
        $this->authorizeManage($request);
        $data = $request->validate(['assigned_to' => 'nullable|integer|exists:users,id']);

        return $this->attempt(fn () => $this->service->assign($task, isset($data['assigned_to']) ? User::find($data['assigned_to']) : null), 'Task assigned.');
    }

    public function start(Request $request, HousekeepingTask $task)
    {
        $this->authorizeWork($request, $task);

        return $this->attempt(fn () => $this->service->start($task, $request->user()), "Room {$task->room->number}: cleaning started.");
    }

    public function finish(Request $request, HousekeepingTask $task)
    {
        $this->authorizeWork($request, $task);
        $data = $request->validate(['notes' => 'nullable|string|max:255']);

        return $this->attempt(fn () => $this->service->finish($task, $request->user(), $data['notes'] ?? null),
            $task->type === 'checkout' && config('hotel.housekeeping.require_inspection', true) ? "Room {$task->room->number} is ready for inspection." : "Room {$task->room->number} done.");
    }

    public function inspect(Request $request, HousekeepingTask $task)
    {
        $this->authorizeManage($request);
        $data = $request->validate(['result' => 'required|in:pass,fail', 'note' => 'nullable|string|max:500']);
        $passed = $data['result'] === 'pass';

        return $this->attempt(fn () => $this->service->inspect($task, $request->user(), $passed, $data['note'] ?? null),
            $passed ? "Room {$task->room->number} passed and is back on sale." : "Room {$task->room->number} sent back for cleaning.");
    }

    public function cancel(Request $request, HousekeepingTask $task)
    {
        $this->authorizeManage($request);
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->service->cancel($task, $data['reason']), 'Task cancelled.');
    }

    // ------------------------------------------------------------------

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('Manage housekeeping'), 403);
    }

    /** Cleaners work on their own tasks and unassigned ones; supervisors on any. */
    private function authorizeWork(Request $request, HousekeepingTask $task): void
    {
        $user = $request->user();
        abort_unless($user->can('Manage housekeeping')
            || ($user->can('Do housekeeping tasks') && in_array($task->assigned_to, [null, $user->id], true)), 403);
    }

    private function attempt(callable $action, string $success)
    {
        $json = request()->expectsJson();   // the tablet board
        try {
            $action();
        } catch (BookingException $e) {
            return $json ? response()->json(['success' => false, 'message' => $e->getMessage()], 422) : back()->withInput()->with('error', $e->getMessage());
        }

        return $json ? response()->json(['success' => true, 'message' => $success]) : back()->with('success', $success);
    }
}
