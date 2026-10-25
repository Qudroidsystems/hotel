<?php

namespace App\Services\Mobile;

use App\Exceptions\BookingException;
use App\Models\Booking\GuestRequest;
use App\Models\CashShift;
use App\Models\Finance\Expense;
use App\Models\HousekeepingTask;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\PurchaseRequest;
use App\Models\Inventory\StockCount;
use App\Models\It\ItTicket;
use App\Models\MaintenanceOrder;
use App\Models\User;
use App\Services\Billing\CashShiftService;
use App\Services\Booking\GuestRequestService;
use App\Services\Finance\ExpenseService;
use App\Services\Inventory\ProcurementService;
use App\Services\Operations\HousekeepingService;
use App\Services\Operations\MaintenanceService;
use App\Services\Staff\StaffAppService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The staff mobile app's work queue and approvals as JSON, each item with the
 * buttons this person may press, and the code that runs those buttons.
 *
 * The buttons call the same services as the website, with the same permission
 * checks as the website's controllers, so the rules and the audit trail are
 * identical whichever way someone works.
 */
class StaffActions
{
    public const WORK_KINDS = ['guest', 'housekeeping', 'maintenance', 'ticket'];
    public const APPROVAL_KINDS = ['expense', 'po', 'pr', 'shift', 'count'];

    private const GROUPS = [
        'guest'        => 'Guest requests',
        'housekeeping' => 'Rooms to clean',
        'maintenance'  => 'Repairs',
        'ticket'       => 'IT tickets',
        'expense'      => 'Expenses',
        'po'           => 'Purchase orders',
        'pr'           => 'Purchase requests',
        'shift'        => 'Cash shifts',
        'count'        => 'Stock counts',
    ];

    public function __construct(private StaffAppService $app)
    {
    }

    // ── Lists ─────────────────────────────────────────────────────────

    public function work(User $user): array
    {
        return $this->app->work($user)->map(fn ($it) => $this->workItem($user, $it->kind, $it->model, (bool) $it->urgent))->values()->all();
    }

    public function approvals(User $user): array
    {
        return $this->app->approvals($user)->map(fn ($it) => $this->approvalItem($user, $it->kind, $it->model))->values()->all();
    }

    public function canApprove(User $user): bool
    {
        return $user->canAny(['Approve expenses', 'Approve purchases', 'Review cash shifts', 'Post stock counts']);
    }

    /** One item, fresh from the database, as the list shows it (after a button was pressed). */
    public function item(User $user, string $kind, int $id): ?array
    {
        $model = $this->find($kind, $id);
        if (! $model) {
            return null;
        }
        if (in_array($kind, self::WORK_KINDS, true)) {
            $still = $this->app->work($user)->first(fn ($it) => $it->kind === $kind && $it->model->id === $model->id);

            return $still ? $this->workItem($user, $kind, $still->model, (bool) $still->urgent) : null;
        }
        $still = $this->app->approvals($user)->first(fn ($it) => $it->kind === $kind && $it->model->id === $model->id);

        return $still ? $this->approvalItem($user, $kind, $still->model) : null;
    }

    // ── Running a button ──────────────────────────────────────────────

    /**
     * Press button $action on item $kind/$id. Returns the message to show.
     *
     * @throws AuthorizationException when this person may not do it
     * @throws BookingException       when the rules say no (shown as it is)
     * @throws ValidationException    when required text is missing
     */
    public function perform(User $user, string $kind, int $id, string $action, ?string $input = null): string
    {
        $model = $this->find($kind, $id) ?? throw new BookingException('That item no longer exists.');
        $input = $input !== null ? trim(mb_substr($input, 0, 1000)) : null;
        $allowed = collect($this->actionsFor($user, $kind, $model))->firstWhere('id', $action);
        if (! $allowed) {
            // Either not this person's to do, or it has moved on (someone else finished it).
            if ($this->visibleTo($user, $kind)) {
                throw new BookingException('That can no longer be done here. Pull down to refresh.');
            }
            throw new AuthorizationException('You are not allowed to do that.');
        }
        if (($allowed['input_required'] ?? false) && blank($input)) {
            throw ValidationException::withMessages(['input' => ($allowed['input'] ?? 'A reason') . ' is needed.']);
        }

        return match ($kind) {
            'housekeeping' => $this->housekeeping($user, $model, $action, $input),
            'maintenance'  => $this->maintenance($user, $model, $action, $input),
            'guest'        => $this->guest($user, $model, $action, $input),
            'expense'      => $this->expense($user, $model, $action, $input),
            'po'           => $this->po($user, $model, $action, $input),
            'pr'           => $this->pr($user, $model, $action, $input),
            'shift'        => $this->shift($model, $input),
            default        => throw new BookingException('Open this on the website to deal with it.'),
        };
    }

    private function housekeeping(User $user, HousekeepingTask $t, string $action, ?string $input): string
    {
        $svc = app(HousekeepingService::class);
        $room = $t->room?->number;

        return match ($action) {
            'start'  => tap("Room {$room}: cleaning started.", fn () => $svc->start($t, $user)),
            'finish' => tap($t->type === 'checkout' && config('hotel.housekeeping.require_inspection', true) ? "Room {$room} is ready for inspection." : "Room {$room} done.",
                fn () => $svc->finish($t, $user, $input ? mb_substr($input, 0, 255) : null)),
            'pass'   => tap("Room {$room} passed and is back on sale.", fn () => $svc->inspect($t, $user, true, $input)),
            'fail'   => tap("Room {$room} sent back for cleaning.", fn () => $svc->inspect($t, $user, false, $input ?: 'Sent back from inspection')),
        };
    }

    private function maintenance(User $user, MaintenanceOrder $o, string $action, ?string $input): string
    {
        $svc = app(MaintenanceService::class);

        return match ($action) {
            'start'    => tap('Marked as in progress.', fn () => $svc->start($o, $user)),
            'hold'     => tap('Put on hold.', fn () => $svc->hold($o, mb_substr((string) $input, 0, 255))),
            'complete' => tap('Work order completed.' . ($o->blocks_room && $o->room ? " Room {$o->room->number} sent to housekeeping." : ''),
                fn () => $svc->complete($o, $user, (string) $input, null)),
        };
    }

    private function guest(User $user, GuestRequest $g, string $action, ?string $input): string
    {
        $svc = app(GuestRequestService::class);
        $note = $input ? mb_substr($input, 0, 500) : null;

        return match ($action) {
            'progress'    => tap('Marked as on it — the guest can see that.', fn () => $svc->update($g, $user, 'in_progress', $note)),
            'done'        => tap('Done — the guest can see that.', fn () => $svc->update($g, $user, 'done', $note)),
            'maintenance' => $svc->toMaintenance($g, $user)->number . ' raised for maintenance.',
        };
    }

    private function expense(User $user, Expense $e, string $action, ?string $input): string
    {
        $svc = app(ExpenseService::class);

        return match ($action) {
            'approve' => tap("{$e->number} approved.", fn () => $svc->approve($e, $user)),
            'reject'  => tap("{$e->number} rejected.", fn () => $svc->reject($e, $user, mb_substr((string) $input, 0, 255))),
        };
    }

    private function po(User $user, PurchaseOrder $po, string $action, ?string $input): string
    {
        $svc = app(ProcurementService::class);

        return match ($action) {
            'approve' => tap("{$po->number} approved.", fn () => $svc->approveOrder($po, $user)),
            'reject'  => tap("{$po->number} sent back to draft.", fn () => $svc->rejectOrder($po, mb_substr((string) $input, 0, 255), $user)),
        };
    }

    private function pr(User $user, PurchaseRequest $pr, string $action, ?string $input): string
    {
        $approve = $action === 'approve';
        app(ProcurementService::class)->decideRequest($pr, $approve, $input ? mb_substr($input, 0, 255) : null, $user);

        return "{$pr->number} " . ($approve ? 'approved.' : 'rejected.');
    }

    private function shift(CashShift $s, ?string $input): string
    {
        app(CashShiftService::class)->review($s, $input ? mb_substr($input, 0, 500) : null);

        return 'Shift signed off.';
    }

    // ── Buttons each person sees (mirrors the website's controller checks) ──

    private function actionsFor(User $user, string $kind, $m): array
    {
        $b = fn (string $id, string $label, string $style = '', ?string $input = null, bool $required = false, ?string $confirm = null)
            => array_filter(compact('id', 'label', 'style', 'input', 'confirm'), fn ($v) => $v !== null) + ['input_required' => $required];

        switch ($kind) {
            case 'housekeeping':
                $manager = $user->can('Manage housekeeping');
                $mine = $manager || ($user->can('Do housekeeping tasks') && in_array($m->assigned_to, [null, $user->id], true));
                $inspect = $m->type === 'checkout' && config('hotel.housekeeping.require_inspection', true);

                return array_values(array_filter([
                    $mine && $m->status === HousekeepingTask::PENDING ? $b('start', 'Start cleaning', 'primary') : null,
                    $mine && $m->status === HousekeepingTask::IN_PROGRESS ? $b('finish', $inspect ? 'Done — ready for inspection' : 'Done', 'primary', 'Note (optional)') : null,
                    $manager && $m->status === HousekeepingTask::DONE ? $b('pass', 'Passed', 'primary') : null,
                    $manager && $m->status === HousekeepingTask::DONE ? $b('fail', 'Clean again', 'destructive', 'What needs doing again?') : null,
                ]));

            case 'maintenance':
                $can = $user->can('Manage maintenance') || ($m->assigned_to === $user->id && $user->can('View maintenance'));
                if (! $can) {
                    return [];
                }

                return array_values(array_filter([
                    in_array($m->status, [MaintenanceOrder::OPEN, MaintenanceOrder::ON_HOLD], true) ? $b('start', 'Start', 'primary') : null,
                    $m->status === MaintenanceOrder::IN_PROGRESS ? $b('complete', 'Mark fixed', 'primary', 'What did you do?', true) : null,
                    $m->status === MaintenanceOrder::IN_PROGRESS ? $b('hold', 'On hold', '', 'Why is it on hold?', true) : null,
                ]));

            case 'guest':
                if (! $user->can('Handle guest requests') || ! in_array($m->status, ['open', 'in_progress'], true)) {
                    return [];
                }

                return array_values(array_filter([
                    $m->status === 'open' ? $b('progress', 'On it', '', 'Message to the guest (optional)') : null,
                    $b('done', 'Done', 'primary', 'Message to the guest (optional)'),
                    $m->route === 'maintenance' && ! $m->maintenance_order_id && $user->canAny(['Report maintenance issues', 'Manage maintenance'])
                        ? $b('maintenance', 'Send to maintenance', '', null, false, 'Raise a work order for this room?') : null,
                ]));

            case 'expense':
                return $user->can('Approve expenses') && $m->status === 'pending' && $m->recorded_by !== $user->id
                    ? [$b('approve', 'Approve', 'primary', null, false, "Approve {$m->number}?"), $b('reject', 'Reject', 'destructive', 'Reason', true)] : [];

            case 'po':
                return $user->can('Approve purchases') && $m->status === 'pending' && ! in_array($user->id, array_filter([$m->created_by, $m->submitted_by]), true)
                    ? [$b('approve', 'Approve', 'primary', null, false, "Approve {$m->number}?"), $b('reject', 'Send back', 'destructive', 'Reason', true)] : [];

            case 'pr':
                return $user->can('Approve purchases') && $m->status === 'submitted' && $m->requested_by !== $user->id
                    ? [$b('approve', 'Approve', 'primary', 'Note (optional)'), $b('reject', 'Reject', 'destructive', 'Reason', true)] : [];

            case 'shift':
                return $user->can('Review cash shifts') && $m->status === CashShift::CLOSED && ($m->user_id !== $user->id || $user->hasRole('Super Admin'))
                    ? [$b('signoff', 'Sign off', 'primary', 'Note (optional)')] : [];
        }

        return [];   // tickets and stock counts are dealt with on the website
    }

    private function visibleTo(User $user, string $kind): bool
    {
        return match ($kind) {
            'housekeeping' => $user->canAny(['Do housekeeping tasks', 'Manage housekeeping']),
            'maintenance'  => $user->canAny(['View maintenance', 'Manage maintenance']),
            'guest'        => $user->can('Handle guest requests'),
            'ticket'       => $user->can('Manage IT tickets'),
            'expense'      => $user->can('Approve expenses'),
            'po', 'pr'     => $user->can('Approve purchases'),
            'shift'        => $user->can('Review cash shifts'),
            'count'        => $user->can('Post stock counts'),
            default        => false,
        };
    }

    // ── JSON for one item ─────────────────────────────────────────────

    private function workItem(User $user, string $kind, $m, bool $urgent): array
    {
        $base = ['key' => "{$kind}:{$m->id}", 'kind' => $kind, 'id' => $m->id, 'group' => self::GROUPS[$kind], 'urgent' => $urgent,
            'created_at' => $m->created_at?->toIso8601String(), 'time' => $m->created_at?->diffForHumans(short: true)];

        $item = match ($kind) {
            'guest' => [
                'badge'        => $m->room?->number,
                'title'        => $m->type_label,
                'subtitle'     => trim(($m->reservation?->guest?->full_name ?? 'Guest') . ($m->reservation?->guest?->is_vip ? ' · VIP' : '')),
                'detail'       => $m->details ? '“' . $m->details . '”' : null,
                'status'       => $m->status, 'status_label' => $m->status_label, 'tone' => $this->tone($m->status_pill),
                'url'          => route('guest-requests.index'),
            ],
            'housekeeping' => [
                'badge'        => $m->room?->number,
                'title'        => 'Room ' . $m->room?->number,
                'subtitle'     => $m->type_label . ($m->room?->floor ? ' · ' . $m->room->floor->name : ''),
                'detail'       => $m->notes,
                'meta'         => $m->assignee ? ($m->assignee->id === $user->id ? 'Assigned to you' : 'Assigned to ' . $m->assignee->name) : 'Not assigned — anyone on the team can take it',
                'status'       => $m->status, 'status_label' => $m->status_label, 'tone' => $this->tone($m->status_pill),
                'url'          => route('housekeeping.index'),
                'room_id'      => $m->room_id,
            ],
            'maintenance' => [
                'badge'        => $m->room?->number,
                'title'        => $m->title,
                'subtitle'     => $m->where . ' · ' . $m->number . ($m->due_at ? ' · due ' . $m->due_at->diffForHumans() : ''),
                'detail'       => $m->description ? \Illuminate\Support\Str::limit($m->description, 240) : null,
                'meta'         => $m->status_label . ($m->assigned_to ? '' : ' · not assigned') . ($m->blocks_room ? ' · room off sale' : ''),
                'status'       => $m->priority, 'status_label' => $m->priority_label, 'tone' => $this->tone($m->priority_pill),
                'url'          => route('maintenance.show', $m),
            ],
            'ticket' => [
                'title'        => $m->title,
                'subtitle'     => $m->number . ' · ' . ($m->requester?->name ?? '—') . ($m->location ? ' · ' . $m->location : ''),
                'meta'         => $m->status_label . ($m->assigned_to ? '' : ' · not assigned') . ($m->responseLate() || $m->resolveLate() ? ' · overdue' : ''),
                'status'       => $m->priority, 'status_label' => $m->priority_label, 'tone' => $this->tone($m->priority_pill),
                'url'          => route('it.tickets.show', $m),
            ],
        };

        return $base + $item + ['actions' => $this->actionsFor($user, $kind, $m)];
    }

    private function approvalItem(User $user, string $kind, $m): array
    {
        $money = fn ($v) => $v === null ? null : config('hotel.currency_symbol') . number_format((float) $v, 2);
        $at = match ($kind) {
            'po'    => $m->submitted_at ?? $m->created_at,
            'shift' => $m->closed_at,
            'count' => $m->updated_at,
            default => $m->created_at,
        };
        $base = ['key' => "{$kind}:{$m->id}", 'kind' => $kind, 'id' => $m->id, 'group' => self::GROUPS[$kind], 'urgent' => false,
            'created_at' => $at?->toIso8601String(), 'time' => $at?->diffForHumans(short: true)];

        $item = match ($kind) {
            'expense' => [
                'title'    => $m->description, 'subtitle' => $m->number . ' · ' . ($m->category?->name ?? 'Expense') . ($m->payee_name ? ' · ' . $m->payee_name : ''),
                'meta'     => 'Recorded by ' . ($m->recorder?->name ?? '—') . ($m->expense_date ? ' · ' . $m->expense_date->format('j M') : ''),
                'amount'   => (float) $m->amount, 'amount_label' => $money($m->amount),
                'url'      => route('expenses.show', $m),
            ],
            'po' => [
                'title'    => $m->supplier?->name ?? 'Purchase order', 'subtitle' => $m->number . ' · ' . $m->lines_count . ' ' . str('item')->plural($m->lines_count),
                'meta'     => 'Sent by ' . ($m->submittedBy?->name ?? '—'),
                'amount'   => (float) $m->total, 'amount_label' => $money($m->total),
                'url'      => route('inventory.po.show', $m),
            ],
            'pr' => [
                'title'    => $m->reason ?: 'Purchase request', 'subtitle' => $m->number . ' · ' . $m->department . ' · ' . $m->lines_count . ' ' . str('item')->plural($m->lines_count),
                'meta'     => 'From ' . ($m->requester?->name ?? '—') . ($m->needed_by ? ' · needed ' . $m->needed_by->format('j M') : ''),
                'amount'   => (float) $m->estimated_total, 'amount_label' => $money($m->estimated_total) ? 'about ' . $money($m->estimated_total) : null,
                'url'      => route('inventory.requests.show', $m),
            ],
            'shift' => [
                'title'    => ($m->user?->name ?? 'Cash shift') . ' · ' . $m->station, 'subtitle' => $m->number . ' · closed ' . $m->closed_at?->format('j M g:i A'),
                'meta'     => abs((float) $m->variance) < 0.005 ? 'Cash matched' : ((float) $m->variance < 0 ? 'Short' : 'Over'),
                'amount'   => (float) $m->variance, 'amount_label' => abs((float) $m->variance) < 0.005 ? null : ((float) $m->variance < 0 ? '−' : '+') . $money(abs((float) $m->variance)),
                'url'      => route('cash-shifts.show', $m),
            ],
            'count' => [
                'title'    => 'Stock count · ' . ($m->location?->name ?? 'Store'), 'subtitle' => $m->number . ' · ' . $m->lines_count . ' items counted',
                'meta'     => 'Ready to post — open it on the website to check the differences',
                'amount'   => null, 'amount_label' => null,
                'url'      => route('inventory.counts.show', $m),
            ],
        };

        return $base + $item + ['status' => 'pending', 'status_label' => 'Waiting', 'tone' => 'warning', 'actions' => $this->actionsFor($user, $kind, $m)];
    }

    private function find(string $kind, int $id)
    {
        $class = match ($kind) {
            'housekeeping' => HousekeepingTask::class,
            'maintenance'  => MaintenanceOrder::class,
            'guest'        => GuestRequest::class,
            'ticket'       => ItTicket::class,
            'expense'      => Expense::class,
            'po'           => PurchaseOrder::class,
            'pr'           => PurchaseRequest::class,
            'shift'        => CashShift::class,
            'count'        => StockCount::class,
            default        => null,
        };

        return $class ? $class::find($id) : null;
    }

    /** "st-warning" → "warning", for the app's coloured labels. */
    public function tone(?string $pill): string
    {
        $t = str_replace(['st-', 'pill-'], '', (string) $pill);

        return in_array($t, ['success', 'warning', 'danger', 'info', 'primary', 'muted'], true) ? $t : 'muted';
    }

    public static function groups(): Collection
    {
        return collect(self::GROUPS);
    }
}
