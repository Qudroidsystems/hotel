<?php

namespace App\Http\Controllers\Operations;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Attendance\Department;
use App\Models\Room;
use App\Models\StaffTask;
use App\Models\User;
use App\Services\Operations\TaskService;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Department tasks (spec §12.1): every member of staff sees their own and their department's. */
class TaskController extends Controller
{
    public const VIEWS = [
        'mine'    => 'My tasks',
        'team'    => 'My department',
        'given'   => 'Given by me',
        'check'   => 'To check',
        'overdue' => 'Overdue',
        'all'     => 'Everything',
    ];

    public function __construct(private TaskService $tasks)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $seeAll = $user->canAny(['Manage tasks', 'View all tasks']);
        $view = array_key_exists((string) $request->query('view'), self::VIEWS) ? $request->query('view') : 'mine';
        $dept = $this->tasks->departmentOf($user);
        $managed = $this->tasks->managedDepartments($user);
        $done = $request->boolean('done');

        $base = $this->tasks->visibleTo($user);
        $list = (clone $base)->with(['assignee:id,name', 'department:id,name,color', 'room:id,number', 'creator:id,name'])
            ->when(! $done && ! in_array($view, ['check'], true), fn ($q) => $q->active())
            ->when($done, fn ($q) => $q->whereIn('status', [StaffTask::DONE, StaffTask::CANCELLED]))
            ->when($view === 'mine', fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', $user->id)
                ->when($dept, fn ($w) => $w->orWhere(fn ($u) => $u->whereNull('assigned_to')->where('department_id', $dept)))))
            ->when($view === 'team', fn ($q) => $q->whereIn('department_id', array_filter([$dept, ...$managed]) ?: [0]))
            ->when($view === 'given', fn ($q) => $q->where('created_by', $user->id))
            ->when($view === 'check', fn ($q) => $q->where('status', StaffTask::AWAITING_CHECK)->where(fn ($w) => $w->whereNull('completed_by')->orWhere('completed_by', '!=', $user->id)))
            ->when($view === 'overdue', fn ($q) => $q->whereNotNull('due_at')->where('due_at', '<', now()))
            ->when($request->integer('department'), fn ($q, $d) => $q->where('department_id', $d))
            ->when(array_key_exists((string) $request->query('priority'), StaffTask::PRIORITIES), fn ($q) => $q->where('priority', $request->query('priority')))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderByRaw('due_at is null')->orderBy('due_at')->latest('id')
            ->paginate(40)->withQueryString();

        $active = (clone $base)->active()->get(['id', 'assigned_to', 'department_id', 'due_at', 'priority']);
        $stats = [
            'mine'    => $active->filter(fn ($t) => $t->assigned_to === $user->id || (! $t->assigned_to && $dept && $t->department_id === $dept))->count(),
            'overdue' => $active->filter(fn ($t) => $t->due_at && $t->due_at->isPast())->count(),
            'urgent'  => $active->whereIn('priority', ['urgent', 'high'])->count(),
            'check'   => (clone $base)->where('status', StaffTask::AWAITING_CHECK)->where(fn ($w) => $w->whereNull('completed_by')->orWhere('completed_by', '!=', $user->id))->count(),
            'week'    => (clone $base)->where('status', StaffTask::DONE)->where('completed_at', '>=', now()->subDays(7))->count(),
        ];

        $views = collect(self::VIEWS)->except($seeAll ? [] : ['all'])
            ->except($this->tasks->canGive($user) ? [] : ['given'])
            ->except($seeAll || $managed || $this->tasks->canGive($user) ? [] : ['check'])->all();
        [$departments, $people, $rooms] = $this->formLists();
        $canGive = $this->tasks->canGive($user);
        $pagetitle = 'Tasks';

        return view('tasks.index', compact('pagetitle', 'list', 'stats', 'view', 'views', 'done', 'seeAll', 'canGive', 'departments', 'people', 'rooms'));
    }

    public function show(Request $request, StaffTask $task)
    {
        $user = $request->user();
        abort_unless($this->tasks->canSee($user, $task), 403);
        $task->load(['assignee', 'department.manager', 'creator', 'completedBy', 'checkedBy', 'room', 'updates.user']);
        $can = [
            'work'   => $this->tasks->canWork($user, $task) && $task->isActive(),
            'manage' => $this->tasks->canManage($user, $task),
            'check'  => $this->tasks->canCheck($user, $task),
        ];
        [$departments, $people] = $this->formLists();
        $pagetitle = $task->number;

        return view('tasks.show', compact('pagetitle', 'task', 'can', 'departments', 'people'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'         => 'required|string|max:160',
            'details'       => 'nullable|string|max:3000',
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'assigned_to'   => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_disabled', 0)],
            'room_id'       => ['nullable', 'integer', Rule::exists('rooms', 'id')],
            'location'      => 'nullable|string|max:120',
            'priority'      => ['required', Rule::in(array_keys(StaffTask::PRIORITIES))],
            'due_at'        => 'nullable|date|after:now',
            'needs_check'   => 'nullable|boolean',
            'files'         => 'nullable|array|max:5',
            'files.*'       => 'file|max:8192|mimes:jpg,jpeg,png,webp,heic,pdf,doc,docx,xls,xlsx,txt',
        ]);

        return $this->attempt(function () use ($request, $data) {
            $task = $this->tasks->create($data, $request->file('files', []), $request->user());

            return redirect()->route('tasks.show', $task)->with('success', "{$task->number} given to {$task->fresh(['assignee', 'department'])->for_label}.");
        });
    }

    public function take(Request $request, StaffTask $task)
    {
        return $this->attempt(fn () => tap(back()->with('success', 'It’s yours.'), fn () => $this->tasks->take($task, $request->user())));
    }

    public function start(Request $request, StaffTask $task)
    {
        return $this->attempt(fn () => tap(back()->with('success', 'Started.'), fn () => $this->tasks->start($task, $request->user())));
    }

    public function finish(Request $request, StaffTask $task)
    {
        $data = $request->validate([
            'note'    => 'nullable|string|max:1000',
            'files'   => 'nullable|array|max:5',
            'files.*' => 'file|max:8192|mimes:jpg,jpeg,png,webp,heic,pdf',
        ]);

        return $this->attempt(function () use ($request, $task, $data) {
            $this->tasks->finish($task, $request->user(), $data['note'] ?? null, $request->file('files', []));

            return back()->with('success', $task->status === StaffTask::AWAITING_CHECK ? 'Marked done. A supervisor will check it.' : 'Marked done.');
        });
    }

    public function check(Request $request, StaffTask $task)
    {
        $data = $request->validate(['decision' => 'required|in:ok,redo', 'note' => 'nullable|string|max:1000']);

        return $this->attempt(function () use ($request, $task, $data) {
            $this->tasks->check($task, $request->user(), $data['decision'] === 'ok', $data['note'] ?? null);

            return back()->with('success', $data['decision'] === 'ok' ? 'Confirmed as done.' : 'Sent back to be finished.');
        });
    }

    public function change(Request $request, StaffTask $task)
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'assigned_to'   => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_disabled', 0)],
            'priority'      => ['required', Rule::in(array_keys(StaffTask::PRIORITIES))],
            'due_at'        => 'nullable|date',
        ]);
        if (empty($data['department_id']) && empty($data['assigned_to'])) {
            return back()->with('error', 'Choose a department or a person.');
        }
        $data += ['department_id' => null, 'assigned_to' => null, 'due_at' => null];

        return $this->attempt(fn () => tap(back()->with('success', 'Saved.'), fn () => $this->tasks->change($task, $request->user(), $data)));
    }

    public function cancel(Request $request, StaffTask $task)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => tap(back()->with('success', 'Task cancelled.'), fn () => $this->tasks->cancel($task, $request->user(), $data['reason'])));
    }

    public function comment(Request $request, StaffTask $task)
    {
        $data = $request->validate(['note' => 'required|string|max:1000', 'file' => 'nullable|file|max:8192|mimes:jpg,jpeg,png,webp,heic,pdf,doc,docx,xls,xlsx,txt']);

        return $this->attempt(fn () => tap(back()->with('success', 'Added.'), fn () => $this->tasks->comment($task, $request->user(), $data['note'], $request->file('file'))));
    }

    private function attempt(callable $fn)
    {
        try {
            return $fn();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    private function formLists(): array
    {
        return [
            Department::orderBy('name')->get(['id', 'name']),
            User::where('is_disabled', false)->with('staffProfile:id,user_id,department_id')->orderBy('name')->get(['id', 'name'])
                ->when(CurrentHotel::multi() && CurrentHotel::get()->id(), function ($users) {   // multi-hotel: this hotel's staff only
                    $here = DB::table('hotel_user')->where('hotel_id', CurrentHotel::get()->id())->pluck('user_id')->all();

                    return $users->filter(fn (User $u) => in_array($u->id, $here, true))->values();
                }),
            Room::active()->orderBy('number')->get(['id', 'number']),
        ];
    }
}
