<?php

namespace App\Http\Controllers\Operations;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceOrder;
use App\Models\Room;
use App\Models\User;
use App\Services\Operations\MaintenanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Faults and work orders (spec §3.4). */
class MaintenanceController extends Controller
{
    public function __construct(private MaintenanceService $service)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canAny(['View maintenance', 'Report maintenance issues', 'Manage maintenance']), 403);
        $seeAll = $user->canAny(['View maintenance', 'Manage maintenance']);

        $show = in_array($request->query('show'), ['active', 'completed', 'cancelled', 'all'], true) ? $request->query('show') : 'active';
        $priority = array_key_exists((string) $request->query('priority'), MaintenanceOrder::PRIORITIES) ? $request->query('priority') : null;
        $category = array_key_exists((string) $request->query('category'), MaintenanceOrder::CATEGORIES) ? $request->query('category') : null;
        $mine = $request->boolean('mine') || ! $seeAll;

        $orders = MaintenanceOrder::with(['room:id,number,status', 'assignee:id,name', 'reporter:id,name'])
            ->when($show === 'active', fn ($q) => $q->active())
            ->when(in_array($show, ['completed', 'cancelled'], true), fn ($q) => $q->where('status', $show))
            ->when($priority, fn ($q) => $q->where('priority', $priority))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when($request->integer('room'), fn ($q, $id) => $q->where('room_id', $id))
            ->when($mine, fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhere('reported_by', $user->id)))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('due_at')
            ->paginate(40)->withQueryString();

        $active = MaintenanceOrder::active()->get(['id', 'priority', 'due_at', 'status', 'blocks_room', 'room_id']);
        $month = MaintenanceOrder::where('status', MaintenanceOrder::COMPLETED)->where('completed_at', '>=', today()->startOfMonth())->get(['cost', 'created_at', 'completed_at']);
        $stats = [
            'active'   => $active->count(),
            'urgent'   => $active->whereIn('priority', ['urgent', 'high'])->count(),
            'overdue'  => $active->filter(fn ($o) => $o->due_at && $o->due_at->isPast())->count(),
            'blocked'  => $active->where('blocks_room', true)->pluck('room_id')->unique()->count(),
            'done'     => $month->count(),
            'cost'     => (float) $month->sum('cost'),
            'hours'    => $month->isNotEmpty() ? round($month->avg(fn ($o) => $o->created_at->diffInMinutes($o->completed_at)) / 60, 1) : null,
        ];

        $rooms = Room::active()->orderBy('number')->get(['id', 'number', 'status']);
        $technicians = $this->technicians();
        $pagetitle = 'Maintenance';

        return view('maintenance.index', compact('pagetitle', 'orders', 'stats', 'show', 'priority', 'category', 'mine', 'seeAll', 'rooms', 'technicians'));
    }

    public function show(Request $request, MaintenanceOrder $order)
    {
        $user = $request->user();
        abort_unless($user->canAny(['View maintenance', 'Manage maintenance']) || in_array($user->id, [$order->assigned_to, $order->reported_by], true), 403);
        $order->load(['room.floor', 'assignee', 'reporter', 'completedBy']);
        $history = $order->room_id
            ? MaintenanceOrder::where('room_id', $order->room_id)->whereKeyNot($order->id)->latest()->limit(8)->get()
            : collect();
        $technicians = $this->technicians();
        $canWork = $this->canWork($user, $order);
        $pagetitle = $order->number;

        return view('maintenance.show', compact('pagetitle', 'order', 'history', 'technicians', 'canWork'));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canAny(['Report maintenance issues', 'Manage maintenance']), 403);
        $data = $request->validate([
            'room_id'              => 'nullable|required_without:location|integer|exists:rooms,id',
            'location'             => 'nullable|required_without:room_id|string|max:120',
            'category'             => ['required', Rule::in(array_keys(MaintenanceOrder::CATEGORIES))],
            'priority'             => ['required', Rule::in(array_keys(MaintenanceOrder::PRIORITIES))],
            'title'                => 'required|string|max:150',
            'description'          => 'nullable|string|max:2000',
            'blocks_room'          => 'nullable|boolean',
            'assigned_to'          => 'nullable|integer|exists:users,id',
            'housekeeping_task_id' => 'nullable|integer|exists:housekeeping_tasks,id',
            'photos'               => 'nullable|array|max:4',
            'photos.*'             => 'image|mimes:jpeg,png,jpg,webp|max:5120',
        ], ['room_id.required_without' => 'Choose a room or type where the problem is.']);
        if (! $request->user()->can('Manage maintenance')) {
            unset($data['assigned_to']);
        }

        try {
            $order = $this->service->report($data, $request->file('photos', []), $request->user());
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $where = $request->input('back') === 'housekeeping' ? back() : redirect()->route('maintenance.show', $order);

        return $where->with('success', "{$order->number} reported" . ($order->blocks_room && $order->room?->status->value === 'maintenance' ? " — room {$order->room->number} taken off sale until it's fixed." : '.'));
    }

    public function assign(Request $request, MaintenanceOrder $order)
    {
        abort_unless($request->user()->can('Manage maintenance'), 403);
        $data = $request->validate(['assigned_to' => 'nullable|integer|exists:users,id', 'due_at' => 'nullable|date']);
        if (! empty($data['due_at'])) {
            $order->forceFill(['due_at' => $data['due_at']])->save();
        }

        return $this->attempt(fn () => $this->service->assign($order, isset($data['assigned_to']) ? User::find($data['assigned_to']) : null), 'Work order updated.');
    }

    public function start(Request $request, MaintenanceOrder $order)
    {
        abort_unless($this->canWork($request->user(), $order), 403);

        return $this->attempt(fn () => $this->service->start($order, $request->user()), 'Marked as in progress.');
    }

    public function hold(Request $request, MaintenanceOrder $order)
    {
        abort_unless($this->canWork($request->user(), $order), 403);
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->service->hold($order, $data['reason']), 'Put on hold.');
    }

    public function complete(Request $request, MaintenanceOrder $order)
    {
        abort_unless($this->canWork($request->user(), $order), 403);
        $data = $request->validate([
            'completion_note' => 'required|string|max:1000',
            'cost'            => 'nullable|numeric|min:0|max:100000000',
            'photos'          => 'nullable|array|max:4',
            'photos.*'        => 'image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        return $this->attempt(fn () => $this->service->complete($order, $request->user(), $data['completion_note'], isset($data['cost']) ? (float) $data['cost'] : null, $request->file('photos', [])),
            'Work order completed.' . ($order->blocks_room && $order->room ? " Room {$order->room->number} sent to housekeeping." : ''));
    }

    public function cancel(Request $request, MaintenanceOrder $order)
    {
        abort_unless($request->user()->can('Manage maintenance'), 403);
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->service->cancel($order, $data['reason']), 'Work order cancelled.');
    }

    // ------------------------------------------------------------------

    private function canWork(User $user, MaintenanceOrder $order): bool
    {
        return $user->can('Manage maintenance') || ($order->assigned_to === $user->id && $user->can('View maintenance'));
    }

    private function technicians()
    {
        return User::permission(['View maintenance', 'Manage maintenance'])->where('is_disabled', false)->orderBy('name')->get(['id', 'name']);
    }

    private function attempt(callable $action, string $success)
    {
        try {
            $action();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
