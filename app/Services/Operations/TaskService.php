<?php

namespace App\Services\Operations;

use App\Exceptions\BookingException;
use App\Models\Attendance\Department;
use App\Models\StaffTask;
use App\Models\StaffTaskUpdate;
use App\Models\User;
use App\Services\Mobile\StaffAlerts;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Department tasks (spec §12.1). Who may do what:
 * - the person it's assigned to, or anyone in its department while it's unassigned, works on it;
 * - whoever gave it, the department's manager and holders of "Manage tasks" change, check or cancel it;
 * - a task that needs checking is not done until someone other than the person who did it confirms it.
 */
class TaskService
{
    public const FILE_DIR = 'tasks';

    public function __construct(private StaffAlerts $alerts)
    {
    }

    // ── Who may do what ─────────────────────────────────────────────

    public function departmentOf(User $user): ?int
    {
        return $user->staffProfile?->department_id;
    }

    /** Departments this person manages. */
    public function managedDepartments(User $user): array
    {
        return Department::where('manager_id', $user->id)->pluck('id')->all();
    }

    public function canGive(User $user): bool
    {
        return $user->can('Give tasks') || $user->can('Manage tasks') || $this->managedDepartments($user);
    }

    public function canSee(User $user, StaffTask $t): bool
    {
        return $user->can('Manage tasks') || $user->can('View all tasks') || $this->canWork($user, $t) || $this->canManage($user, $t)
            || ($t->department_id && $t->department_id === $this->departmentOf($user));
    }

    public function canWork(User $user, StaffTask $t): bool
    {
        return $t->assigned_to === $user->id
            || (! $t->assigned_to && $t->department_id && $t->department_id === $this->departmentOf($user));
    }

    public function canManage(User $user, StaffTask $t): bool
    {
        return $user->can('Manage tasks') || $t->created_by === $user->id
            || ($t->department_id && in_array($t->department_id, $this->managedDepartments($user), true));
    }

    public function canCheck(User $user, StaffTask $t): bool
    {
        return $t->status === StaffTask::AWAITING_CHECK && $this->canManage($user, $t) && $t->completed_by !== $user->id;
    }

    /** Tasks this person should see on their list. */
    public function visibleTo(User $user)
    {
        $q = StaffTask::query();
        if ($user->can('Manage tasks') || $user->can('View all tasks')) {
            return $q;
        }
        $dept = $this->departmentOf($user);
        $managed = $this->managedDepartments($user);

        return $q->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhere('created_by', $user->id)
            ->when($dept, fn ($w) => $w->orWhere('department_id', $dept))
            ->when($managed, fn ($w) => $w->orWhereIn('department_id', $managed)));
    }

    // ── Actions ─────────────────────────────────────────────────────

    /** @param UploadedFile[] $files */
    public function create(array $data, array $files, User $by): StaffTask
    {
        if (! $this->canGive($by)) {
            throw new BookingException('You can’t give tasks.');
        }
        if (empty($data['department_id']) && empty($data['assigned_to'])) {
            throw new BookingException('Choose a department or a person for this task.');
        }

        $task = DB::transaction(function () use ($data, $files, $by) {
            $task = StaffTask::create([
                'title'         => $data['title'],
                'details'       => $data['details'] ?? null,
                'department_id' => $data['department_id'] ?? (isset($data['assigned_to']) ? User::find($data['assigned_to'])?->staffProfile?->department_id : null),
                'assigned_to'   => $data['assigned_to'] ?? null,
                'room_id'       => $data['room_id'] ?? null,
                'location'      => $data['location'] ?? null,
                'priority'      => $data['priority'] ?? 'normal',
                'due_at'        => $data['due_at'] ?? null,
                'needs_check'   => ! empty($data['needs_check']),
                'created_by'    => $by->id,
            ]);
            $task->forceFill([
                'number'      => 'TSK-' . str_pad((string) $task->id, 6, '0', STR_PAD_LEFT),
                'attachments' => $this->store($files, 'brief') ?: null,
            ])->save();
            $this->log($task, $by, 'status', 'Task given to ' . $task->fresh(['assignee', 'department'])->for_label . '.');

            return $task;
        });

        Audit::record('tasks.created', "{$task->number} given: {$task->title}", $task);
        $this->tellWorkers($task, "New task: {$task->title}", $by);

        return $task;
    }

    public function take(StaffTask $t, User $by): void
    {
        if ($t->assigned_to || ! $this->canWork($by, $t) || ! $t->isActive()) {
            throw new BookingException('This task is not open for you to take.');
        }
        $t->forceFill(['assigned_to' => $by->id])->save();
        $this->log($t, $by, 'assigned', "{$by->name} took the task.");
    }

    public function start(StaffTask $t, User $by): void
    {
        if ($t->status !== StaffTask::OPEN || ! $this->canWork($by, $t)) {
            throw new BookingException('Only the person doing this task can start it.');
        }
        $t->forceFill(['status' => StaffTask::IN_PROGRESS, 'started_at' => now(), 'assigned_to' => $t->assigned_to ?? $by->id])->save();
        $this->log($t, $by, 'status', 'Started.');
    }

    /** @param UploadedFile[] $files */
    public function finish(StaffTask $t, User $by, ?string $note, array $files = []): void
    {
        if (! $t->isActive() || ! $this->canWork($by, $t)) {
            throw new BookingException('Only the person doing this task can mark it done.');
        }
        $status = $t->needs_check ? StaffTask::AWAITING_CHECK : StaffTask::DONE;
        $t->forceFill([
            'status'          => $status,
            'assigned_to'     => $t->assigned_to ?? $by->id,
            'started_at'      => $t->started_at ?? now(),
            'completed_at'    => now(),
            'completed_by'    => $by->id,
            'completion_note' => $note,
            'attachments'     => array_merge($t->attachments ?? [], $this->store($files, 'done')) ?: null,
        ])->save();
        $this->log($t, $by, 'status', ($status === StaffTask::DONE ? 'Done.' : 'Done, waiting for a supervisor to check.') . ($note ? " {$note}" : ''));
        Audit::record('tasks.completed', "{$t->number} marked done by {$by->name}", $t);

        if ($status === StaffTask::AWAITING_CHECK) {
            $this->alerts->toUsers($this->supervisors($t), $this->alert($t, "Check {$t->number}: {$t->title}", "{$by->name} says it's done.", 'approvals'));
        }
    }

    public function check(StaffTask $t, User $by, bool $ok, ?string $note): void
    {
        if (! $this->canCheck($by, $t)) {
            throw new BookingException('Someone who manages this task, other than the person who did it, must check it.');
        }
        if (! $ok && blank($note)) {
            throw new BookingException('Say what still needs doing.');
        }
        if ($ok) {
            $t->forceFill(['status' => StaffTask::DONE, 'checked_at' => now(), 'checked_by' => $by->id])->save();
            $this->log($t, $by, 'status', 'Checked and confirmed.' . ($note ? " {$note}" : ''));
            Audit::record('tasks.checked', "{$t->number} checked by {$by->name}", $t);

            return;
        }
        $t->forceFill(['status' => StaffTask::IN_PROGRESS, 'completed_at' => null, 'completed_by' => null])->save();
        $this->log($t, $by, 'returned', "Sent back: {$note}");
        Audit::record('tasks.returned', "{$t->number} sent back by {$by->name}", $t, reason: $note);
        if ($t->assignee) {
            $this->alerts->toUsers(collect([$t->assignee]), $this->alert($t, "{$t->number} sent back", $note));
        }
    }

    public function change(StaffTask $t, User $by, array $data): void
    {
        if (! $this->canManage($by, $t) || ! $t->isActive()) {
            throw new BookingException('You can’t change this task.');
        }
        $before = $t->only(['department_id', 'assigned_to', 'priority', 'due_at']);
        $t->fill(array_intersect_key($data, array_flip(['department_id', 'assigned_to', 'priority', 'due_at'])));
        if ($t->isDirty('due_at') || $t->isDirty('priority')) {
            $t->escalation_level = 0;   // a new deadline starts the overdue clock again
            $t->escalated_at = null;
        }
        if (! $t->isDirty()) {
            return;
        }
        $reassigned = $t->isDirty('assigned_to') || $t->isDirty('department_id');
        $t->save();
        $t->load('assignee', 'department');
        $this->log($t, $by, $reassigned ? 'assigned' : 'note', $reassigned ? "Given to {$t->for_label}." : 'Priority or due time changed.');
        Audit::record('tasks.changed', "{$t->number} changed by {$by->name}", $t, old: $before, new: $t->only(array_keys($before)));
        if ($reassigned) {
            $this->tellWorkers($t, "Task for you: {$t->title}", $by);
        }
    }

    public function cancel(StaffTask $t, User $by, string $reason): void
    {
        if (! $this->canManage($by, $t) || in_array($t->status, [StaffTask::DONE, StaffTask::CANCELLED], true)) {
            throw new BookingException('You can’t cancel this task.');
        }
        $t->forceFill(['status' => StaffTask::CANCELLED])->save();
        $this->log($t, $by, 'status', "Cancelled: {$reason}");
        Audit::record('tasks.cancelled', "{$t->number} cancelled by {$by->name}", $t, reason: $reason);
    }

    public function comment(StaffTask $t, User $by, string $note, ?UploadedFile $file = null): void
    {
        if (! $this->canSee($by, $t)) {
            throw new BookingException('You can’t add to this task.');
        }
        $saved = $file ? $this->store([$file], 'note') : [];
        if ($saved) {
            $t->forceFill(['attachments' => array_merge($t->attachments ?? [], $saved)])->save();
        }
        $this->log($t, $by, 'note', $note, $saved[0]['path'] ?? null);
    }

    /**
     * Run by the scheduler. Overdue: first the person (or department) and their manager are told;
     * when it is still not done after the priority's grace hours, management is told and it is flagged.
     */
    public function escalate(): int
    {
        $count = 0;
        StaffTask::overdue()->where('escalation_level', '<', 2)->with('assignee', 'department')->get()->each(function (StaffTask $t) use (&$count) {
            $late = $t->due_at->diffForHumans(now(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);
            if ($t->escalation_level === 0) {
                $t->forceFill(['escalation_level' => 1, 'escalated_at' => now()])->save();
                $this->log($t, null, 'escalated', "Overdue: the person doing it and the department manager were reminded.");
                $people = $this->workers($t)->merge($this->supervisors($t))->unique('id')->values();
                $this->alerts->toUsers($people, $this->alert($t, "Overdue: {$t->number} {$t->title}", "Was due {$t->due_at->format('D j M H:i')} ({$late} ago).", 'work', 'urgent'));
                $count++;
            }
            $hours = StaffTask::PRIORITIES[$t->priority][2] ?? 4;
            if ($t->escalation_level === 1 && $t->due_at->lt(now()->subHours($hours))) {
                $t->forceFill(['escalation_level' => 2, 'escalated_at' => now()])->save();
                $this->log($t, null, 'escalated', "Still not done {$late} after it was due: management was told.");
                Audit::record('tasks.escalated', "{$t->number} still not done {$late} after it was due", $t, flagged: true);
                $this->alerts->notify('Manage tasks', $this->alert($t, "Escalated: {$t->number} {$t->title}", "{$t->for_label} · due {$t->due_at->format('D j M H:i')}", 'work', 'urgent'));
                $count++;
            }
        });

        return $count;
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /** The person it's for, or everyone in its department when it's unassigned. */
    private function workers(StaffTask $t): Collection
    {
        if ($t->assigned_to) {
            return User::whereKey($t->assigned_to)->get();
        }
        if (! $t->department_id) {
            return collect();
        }

        return User::whereHas('staffProfile', fn ($q) => $q->where('department_id', $t->department_id))->where('is_disabled', false)->get();
    }

    /** Whoever gave it and the department's manager. */
    private function supervisors(StaffTask $t): Collection
    {
        return User::whereIn('id', array_filter([$t->created_by, $t->department?->manager_id]))->get();
    }

    private function tellWorkers(StaffTask $t, string $title, User $by): void
    {
        $this->alerts->toUsers($this->workers($t)->reject(fn ($u) => $u->id === $by->id)->values(),
            $this->alert($t, $title, ($t->due_at ? 'Due ' . $t->due_at->format('D j M H:i') . ' · ' : '') . $t->priority_label . ' priority', 'work', $t->priority === 'urgent' ? 'urgent' : 'normal'));
    }

    private function alert(StaffTask $t, string $title, ?string $body, string $group = 'work', string $level = 'normal'): array
    {
        return ['title' => $title, 'body' => (string) $body, 'group' => $group, 'level' => $level, 'url' => route('tasks.show', $t), 'thread' => "task:{$t->id}", 'kind' => 'task', 'item_id' => $t->id];
    }

    private function log(StaffTask $t, ?User $by, string $kind, string $body, ?string $attachment = null): void
    {
        StaffTaskUpdate::create(['staff_task_id' => $t->id, 'user_id' => $by?->id, 'kind' => $kind, 'body' => $body, 'attachment' => $attachment]);
    }

    /** @param UploadedFile[] $files */
    private function store(array $files, string $stage): array
    {
        $saved = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $saved[] = ['path' => $file->store(self::FILE_DIR, 'public'), 'name' => mb_substr($file->getClientOriginalName(), 0, 120), 'type' => $file->getMimeType(), 'stage' => $stage];
            }
        }

        return $saved;
    }
}
