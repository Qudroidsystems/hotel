<?php

namespace App\Services\Mobile\Modules;

use App\Exceptions\BookingException;
use App\Models\Attendance\Department;
use App\Models\Room;
use App\Models\StaffTask;
use App\Models\User;
use App\Services\Operations\TaskService;
use Illuminate\Database\Eloquent\Model;

/** Department tasks: what I have to do, what my team has, and giving a task. */
class TasksModule extends StaffModule
{
    public function __construct(private TaskService $tasks)
    {
    }

    public function key(): string { return 'tasks'; }
    public function title(): string { return 'Tasks'; }
    public function icon(): string { return 'task_alt'; }
    public function section(): string { return 'My work'; }
    public function blurb(): string { return 'Jobs given to you or your department'; }

    public function allowed(User $user): bool
    {
        return true;   // everyone can have tasks; giving one is checked separately
    }

    public function count(User $user): ?int
    {
        return $this->tasks->visibleTo($user)->active()->where(fn ($q) => $q->where('assigned_to', $user->id)
            ->orWhere(fn ($w) => $w->whereNull('assigned_to')->where('department_id', $this->tasks->departmentOf($user) ?? 0)))->count() ?: null;
    }

    public function tabs(User $user): array
    {
        return array_values(array_filter([
            ['key' => 'mine', 'label' => 'Mine'],
            ['key' => 'team', 'label' => 'Team'],
            $this->tasks->canGive($user) || $user->can('Manage tasks') ? ['key' => 'check', 'label' => 'To check'] : null,
            ['key' => 'done', 'label' => 'Done'],
        ]));
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        $q = $this->tasks->visibleTo($user)->with('assignee', 'department', 'room', 'creator');
        $dept = $this->tasks->departmentOf($user);
        match ($tab) {
            'team'  => $q->active()->where(fn ($w) => $w->whereNull('assigned_to')->orWhere('assigned_to', '!=', $user->id)),
            'check' => $q->where('status', StaffTask::AWAITING_CHECK),
            'done'  => $q->whereIn('status', [StaffTask::DONE, StaffTask::CANCELLED, StaffTask::AWAITING_CHECK])->where('updated_at', '>=', now()->subDays(7)),
            default => $q->active()->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhere(fn ($x) => $x->whereNull('assigned_to')->where('department_id', $dept ?? 0))),
        };
        $rank = "case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end";

        return $q->orderByRaw($tab === 'done' ? 'updated_at desc' : $rank)->orderByRaw('due_at is null')->orderBy('due_at')->limit(100)->get()
            ->map(fn (StaffTask $t) => $this->item($user, $t))->all();
    }

    public function find(int $id): ?Model
    {
        return StaffTask::with('assignee', 'department', 'room', 'creator', 'updates.user')->find($id);
    }

    public function item(User $user, Model $t): array
    {
        /** @var StaffTask $t */
        return [
            'id' => $t->id,
            'title' => $t->title,
            'subtitle' => trim(($t->number ? $t->number . ' · ' : '') . $t->for_label . ($t->where ? ' · ' . $t->where : '')),
            'badge' => ['label' => $t->status_label, 'tone' => self::tone($t->status_pill)],
            'chips' => array_values(array_filter([
                $t->priority !== 'normal' ? ['label' => $t->priority_label, 'tone' => self::tone($t->priority_pill)] : null,
                $t->due_at ? ['label' => ($t->isOverdue() ? 'Overdue · ' : 'Due ') . self::when($t->due_at), 'tone' => $t->isOverdue() ? 'danger' : 'muted'] : null,
            ])),
            'urgent' => $t->isOverdue() || $t->priority === 'urgent',
            'body' => $t->details,
            'lines' => self::lines([
                'Given by' => $t->creator?->name,
                'For' => $t->for_label,
                'Where' => $t->where,
                'Due' => self::when($t->due_at),
                'Needs checking' => $t->needs_check ? 'Yes, by a supervisor' : null,
                'Done note' => $t->completion_note,
            ]),
            'history' => $t->relationLoaded('updates') ? $t->updates->take(10)->map(fn ($u) => ['who' => $u->user?->name, 'what' => $u->note, 'at' => self::when($u->created_at)])->values()->all() : [],
            'actions' => $this->actions($user, $t),
        ];
    }

    public function actions(User $user, Model $t): array
    {
        /** @var StaffTask $t */
        $a = [];
        if ($t->isActive() && ! $t->assigned_to && $this->tasks->canWork($user, $t)) {
            $a[] = ['id' => 'take', 'label' => 'Take it', 'tone' => 'neutral'];
        }
        if ($t->status === StaffTask::OPEN && $this->tasks->canWork($user, $t)) {
            $a[] = ['id' => 'start', 'label' => 'Start', 'tone' => 'primary'];
        }
        if ($t->isActive() && $this->tasks->canWork($user, $t)) {
            $a[] = ['id' => 'finish', 'label' => 'Done', 'tone' => 'primary', 'fields' => [self::field('note', 'Anything to say? (optional)', 'textarea', ['max' => 1000]), self::field('photos', 'Photos (optional)', 'photos')]];
        }
        if ($this->tasks->canCheck($user, $t)) {
            $a[] = ['id' => 'pass', 'label' => 'Checked, all good', 'tone' => 'primary'];
            $a[] = ['id' => 'redo', 'label' => 'Send back', 'tone' => 'danger', 'fields' => [self::field('note', 'What still needs doing?', 'textarea', ['required' => true, 'max' => 1000])]];
        }
        if ($this->tasks->canSee($user, $t) && ! in_array($t->status, [StaffTask::DONE, StaffTask::CANCELLED], true)) {
            $a[] = ['id' => 'comment', 'label' => 'Add a note', 'tone' => 'neutral', 'fields' => [self::field('note', 'Note', 'textarea', ['required' => true, 'max' => 1000])]];
        }
        if ($this->tasks->canManage($user, $t) && ! in_array($t->status, [StaffTask::DONE, StaffTask::CANCELLED], true)) {
            $a[] = ['id' => 'cancel', 'label' => 'Cancel task', 'tone' => 'danger', 'fields' => [self::field('reason', 'Why?', 'text', ['required' => true])]];
        }

        return $a;
    }

    public function perform(User $user, Model $t, string $action, array $input, array $files): string
    {
        /** @var StaffTask $t */
        switch ($action) {
            case 'take': $this->tasks->take($t, $user); return 'It’s yours.';
            case 'start': $this->tasks->start($t, $user); return 'Started.';
            case 'finish':
                $this->tasks->finish($t, $user, $input['note'] ?? null, array_values(array_filter((array) ($files['photos'] ?? []))));
                return $t->fresh()->status === StaffTask::AWAITING_CHECK ? 'Done — a supervisor will check it.' : 'Done. Thank you.';
            case 'pass': $this->tasks->check($t, $user, true, null); return 'Checked and closed.';
            case 'redo': $this->tasks->check($t, $user, false, $input['note'] ?? null); return 'Sent back.';
            case 'comment': $this->tasks->comment($t, $user, (string) $input['note']); return 'Note added.';
            case 'cancel': $this->tasks->cancel($t, $user, (string) $input['reason']); return 'Task cancelled.';
        }
        throw new BookingException('Unknown action.');
    }

    public function form(User $user): ?array
    {
        if (! $this->tasks->canGive($user)) {
            return null;
        }
        $people = User::query()->where('is_disabled', false)->orderBy('name')->limit(500)->get(['id', 'name'])
            ->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name])->all();

        return ['title' => 'Give a task', 'submit' => 'Give task', 'fields' => [
            self::field('title', 'What needs doing?', 'text', ['required' => true, 'max' => 160]),
            self::field('details', 'Details', 'textarea'),
            self::field('department_id', 'Department', 'select', ['options' => Department::orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['value' => (string) $d->id, 'label' => $d->name])->all()]),
            self::field('assigned_to', 'Person (or leave for anyone in the department)', 'select', ['options' => $people]),
            self::field('room_id', 'Room', 'select', ['options' => Room::orderBy('number')->get(['id', 'number'])->map(fn ($r) => ['value' => (string) $r->id, 'label' => 'Room ' . $r->number])->all()]),
            self::field('location', 'Or where', 'text', ['max' => 120]),
            self::field('priority', 'Priority', 'select', ['required' => true, 'value' => 'normal', 'options' => self::options(StaffTask::PRIORITIES)]),
            self::field('due_at', 'Due by', 'datetime'),
            self::field('needs_check', 'A supervisor checks it when done', 'switch'),
            self::field('photos', 'Photos', 'photos'),
        ]];
    }

    public function create(User $user, array $input, array $files): array
    {
        $task = $this->tasks->create($input, array_values(array_filter((array) ($files['photos'] ?? []))), $user);

        return ["{$task->number} given to {$task->fresh(['assignee', 'department'])->for_label}.", $task];
    }

    public function emptyText(?string $tab): string
    {
        return match ($tab) {
            'check' => 'Nothing waiting for your check.',
            'done' => 'Nothing finished in the last week.',
            default => 'No tasks. Nice.',
        };
    }
}
