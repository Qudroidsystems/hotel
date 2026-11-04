<?php

namespace App\Services\Mobile;

use App\Models\Laundry\LaundryOrder;
use App\Models\Wellness\WellnessBooking;
use App\Models\Pos\GuestOrder;
use App\Models\Transport\Ride;
use App\Services\Transport\RideService;
use App\Services\Laundry\LaundryService;
use App\Services\Wellness\WellnessBookings;
use App\Services\Pos\GuestOrderService;
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
    public const WORK_KINDS = ['ride', 'order', 'laundry', 'wellness', 'guest', 'housekeeping', 'maintenance', 'ticket'];
    public const APPROVAL_KINDS = ['expense', 'po', 'pr', 'payroll', 'shift', 'count'];

    private const GROUPS = [
        'ride'         => 'Cars',
        'order'        => 'Room orders',
        'laundry'      => 'Laundry',
        'wellness'     => 'Spa, gym & pool',
        'guest'        => 'Guest requests',
        'housekeeping' => 'Rooms to clean',
        'maintenance'  => 'Repairs',
        'ticket'       => 'IT tickets',
        'expense'      => 'Expenses',
        'po'           => 'Purchase orders',
        'pr'           => 'Purchase requests',
        'payroll'      => 'Payroll',
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
            'order'        => $this->order($user, $model, $action, $input),
            'laundry'      => $this->laundry($user, $model, $action, $input),
            'wellness'     => $this->wellness($user, $model, $action, $input),
            'ride'         => $this->ride($user, $model, $action, $input),
            'expense'      => $this->expense($user, $model, $action, $input),
            'po'           => $this->po($user, $model, $action, $input),
            'pr'           => $this->pr($user, $model, $action, $input),
            'payroll'      => $action === 'approve'
                ? tap("Payroll {$model->month_label} approved.", fn () => app(\App\Services\Payroll\PayrollService::class)->approve($model, $user))
                : tap("Payroll {$model->month_label} sent back.", fn () => app(\App\Services\Payroll\PayrollService::class)->reject($model, $user, (string) $input)),
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

    private function ride(User $user, Ride $r, string $action, ?string $input): string
    {
        $svc = app(RideService::class);

        return match ($action) {
            'auto'   => ($d = $svc->autoAssign($r, $user)) ? "{$r->number} given to {$d->name}." : throw new BookingException('No driver is free with a car. Assign one on the website.'),
            'cancel' => tap("{$r->number} cancelled.", fn () => $svc->cancel($r, (string) $input, $user)),
            default  => $svc->driverAction($r, $user, $action, $input),
        };
    }

    private function order(User $user, GuestOrder $o, string $action, ?string $input): string
    {
        $svc = app(GuestOrderService::class);

        return match ($action) {
            'accept'  => tap("{$o->number} accepted — on room {$o->room_number}'s bill.", fn () => $svc->accept($o, $user, $input)),
            'send'    => tap("{$o->number} on its way — the guest can see that.", fn () => $svc->send($o, $user)),
            'deliver' => tap("{$o->number} delivered.", fn () => $svc->deliver($o, $user)),
            'decline' => tap("{$o->number} declined — the guest sees your reason.", fn () => $svc->decline($o, $user, (string) $input)),
        };
    }

    private function laundry(User $user, LaundryOrder $o, string $action, ?string $input): string
    {
        $svc = app(LaundryService::class);

        return match ($action) {
            'collect'  => (function () use ($svc, $o, $user, $input) {
                $o = $svc->collect($o, $user, null, null, $input);

                return "{$o->number} collected — " . config('hotel.currency_symbol') . number_format((float) $o->total, 2) . " on room {$o->room_number}'s bill, due back " . $o->due_at->format('D g:i A') . '.';
            })(),
            'ready'    => tap("{$o->number} ready to take up.", fn () => $svc->ready($o, $user)),
            'returned' => tap("{$o->number} returned to room {$o->room_number}.", fn () => $svc->returned($o, $user)),
            'decline'  => tap("{$o->number} declined — the guest sees your reason.", fn () => $svc->decline($o, $user, (string) $input)),
            'issue'    => tap('Problem recorded on the booking.', fn () => $svc->reportIssue($o, $user, (string) $input)),
        };
    }

    private function wellness(User $user, WellnessBooking $b, string $action, ?string $input): string
    {
        $svc = app(WellnessBookings::class);

        return match ($action) {
            'arrive'   => tap("{$b->number}: {$b->guest_name} is here" . ($b->payment === 'room' && (float) $b->total > 0 ? ' — on the room bill.' : ($b->payment === 'desk' ? ' — take payment.' : '.')), fn () => $svc->arrive($b, $user)),
            'complete' => tap("{$b->number} done.", fn () => $svc->complete($b, $user)),
            'no_show'  => tap("{$b->number} marked as didn't come.", fn () => $svc->noShow($b, $user)),
            'cancel'   => tap("{$b->number} cancelled.", fn () => $svc->cancel($b, $user, (string) $input)),
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

            case 'ride':
                $mine = $m->driver_id && ($d = app(RideService::class)->driverFor($user)) && $d->id === $m->driver_id;
                $desk = $user->can('Manage transport');

                return array_values(array_filter([
                    $desk && $m->status === Ride::REQUESTED ? $b('auto', 'Give to nearest free driver', 'primary') : null,
                    $mine && $m->status === Ride::ASSIGNED ? $b('start', 'On my way', 'primary') : null,
                    $mine && in_array($m->status, [Ride::ASSIGNED, Ride::ON_THE_WAY], true) ? $b('arrived', 'I\'m at the pick-up', $m->status === Ride::ON_THE_WAY ? 'primary' : '') : null,
                    $mine && in_array($m->status, [Ride::ARRIVED, Ride::ON_THE_WAY], true) ? $b('picked_up', 'Guest picked up', $m->status === Ride::ARRIVED ? 'primary' : '') : null,
                    $mine && $m->status === Ride::ON_TRIP ? $b('complete', 'Dropped off', 'primary') : null,
                    $mine && $m->status === Ride::ASSIGNED ? $b('decline', 'Can\'t do it', 'destructive', 'Why? (the front desk sees it)', true) : null,
                    $desk && in_array($m->status, [Ride::REQUESTED, Ride::ASSIGNED], true) ? $b('cancel', 'Cancel ride', 'destructive', 'Reason (the guest sees it)', true) : null,
                ]));

            case 'order':
                if (! app(GuestOrderService::class)->canHandle($user)) {
                    return [];
                }

                return array_values(array_filter([
                    $m->status === GuestOrder::PLACED ? $b('accept', 'Accept — add to bill', 'primary', 'Message to the guest (optional)') : null,
                    $m->status === GuestOrder::PLACED ? $b('decline', 'Decline', 'destructive', 'Reason (the guest sees it)', true) : null,
                    $m->status === GuestOrder::ACCEPTED ? $b('send', 'On the way', 'primary') : null,
                    in_array($m->status, [GuestOrder::ACCEPTED, GuestOrder::ON_THE_WAY], true) ? $b('deliver', 'Delivered', $m->status === GuestOrder::ON_THE_WAY ? 'primary' : '') : null,
                ]));

            case 'laundry':
                if (! app(LaundryService::class)->canHandle($user)) {
                    return [];
                }

                return array_values(array_filter([
                    // A bag with the guest's own list can be taken as listed; an unlisted bag is counted on the website.
                    $m->status === LaundryOrder::REQUESTED && $m->lines ? $b('collect', 'Collected — as listed', 'primary', 'Note to the guest (optional)', false, 'Charge ' . config('hotel.currency_symbol') . number_format((float) $m->total, 2) . ' to the room? If the count differs, count it on the website.') : null,
                    $m->status === LaundryOrder::REQUESTED ? $b('decline', 'Decline', 'destructive', 'Reason (the guest sees it)', true) : null,
                    $m->status === LaundryOrder::COLLECTED ? $b('ready', 'Ready', 'primary') : null,
                    in_array($m->status, [LaundryOrder::COLLECTED, LaundryOrder::READY], true) ? $b('returned', 'Returned to room', $m->status === LaundryOrder::READY ? 'primary' : '') : null,
                    in_array($m->status, [LaundryOrder::COLLECTED, LaundryOrder::READY], true) ? $b('issue', 'Damaged / missing', 'destructive', 'What happened?', true) : null,
                ]));

            case 'wellness':
                if (! app(WellnessBookings::class)->canManage($user)) {
                    return [];
                }

                return array_values(array_filter([
                    $m->status === WellnessBooking::BOOKED ? $b('arrive', 'Arrived', 'primary', null, false, $m->payment === 'room' && (float) $m->total > 0 ? 'Charge ' . config('hotel.currency_symbol') . number_format((float) $m->total, 2) . ' to the room?' : null) : null,
                    $m->status === WellnessBooking::ARRIVED ? $b('complete', 'Done', 'primary') : null,
                    $m->status === WellnessBooking::BOOKED && $m->starts_at->isPast() ? $b('no_show', 'Didn\'t come', '') : null,
                    $m->status === WellnessBooking::BOOKED ? $b('cancel', 'Cancel', 'destructive', 'Reason (the guest sees it)', true) : null,
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

            case 'payroll':
                return $user->can('Approve payroll') && $m->status === 'submitted' && ($m->submitted_by !== $user->id || $user->hasRole('Super Admin'))
                    ? [$b('approve', 'Approve', 'primary', null, false, 'Approve ' . config('hotel.currency_symbol') . number_format((float) $m->net, 2) . " net pay for {$m->staff_count} staff?"), $b('reject', 'Send back', 'destructive', 'Reason', true)] : [];

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
            'order'        => app(GuestOrderService::class)->canHandle($user),
            'laundry'      => app(LaundryService::class)->canHandle($user),
            'wellness'     => app(WellnessBookings::class)->canManage($user),
            'ride'         => $user->canAny(['Manage transport', 'Drive guests']),
            'ticket'       => $user->can('Manage IT tickets'),
            'expense'      => $user->can('Approve expenses'),
            'po', 'pr'     => $user->can('Approve purchases'),
            'payroll'      => $user->can('Approve payroll'),
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
            'ride' => [
                'badge'        => $m->pickup_at ? $m->pickup_at->format('H:i') : 'NOW',
                'title'        => $m->toHotel() ? 'Pick up: ' . $m->place_label : 'To: ' . $m->place_label,
                'subtitle'     => trim(($m->guest_name ?? 'Guest') . ' · ' . $m->passengers . ' pax' . ($m->bags ? ', ' . $m->bags . ' bags' : '') . ($m->flight ? ' · flight ' . $m->flight : '')),
                'detail'       => trim($m->when_label . ($m->notes ? ' — “' . $m->notes . '”' : '')),
                'meta'         => $m->driver ? $m->driver->name . ' · ' . $m->vehicle?->label : 'No driver yet' . ($m->fare !== null ? ' · ' . config('hotel.currency_symbol') . number_format((float) $m->fare, 0) : ''),
                'status'       => $m->status, 'status_label' => $m->staff_label, 'tone' => $this->tone($m->status_pill),
                'url'          => route('transport.index'),
                'nav'          => app(RideService::class)->navUrl($m),
                'phone'        => $m->guest_phone,
            ],
            'order' => [
                'badge'        => $m->room_number,
                'title'        => $m->outlet?->name . ' · ' . $m->number,
                'subtitle'     => ($m->guest_name ?? 'Guest') . ' · ' . config('hotel.currency_symbol') . number_format((float) $m->total, 2),
                'detail'       => $m->summary . ($m->note ? ' — “' . $m->note . '”' : ''),
                'meta'         => collect($m->lines)->filter(fn ($l) => ! empty($l['note']))->map(fn ($l) => $l['name'] . ': ' . $l['note'])->implode(' · ') ?: null,
                'status'       => $m->status, 'status_label' => $m->staff_label, 'tone' => $this->tone($m->status_pill),
                'url'          => route('pos.room-orders'),
            ],
            'wellness' => [
                'badge'        => $m->starts_at->format('H:i'),
                'title'        => $m->what,
                'subtitle'     => $m->guest_name . ($m->reservation_id ? ' · room guest' : ' · visitor') . ($m->therapist ? ' · ' . $m->therapist->name : ''),
                'detail'       => trim($m->venue?->name . ' · ' . $m->starts_at->format('g:i') . '–' . $m->ends_at->format('g:i A') . ($m->note ? ' — “' . $m->note . '”' : '')),
                'meta'         => (float) $m->total > 0 ? config('hotel.currency_symbol') . number_format((float) $m->total, 2) . ' · ' . $m->payment_label : 'Free',
                'status'       => $m->status, 'status_label' => $m->status_label, 'tone' => $this->tone($m->status_pill),
                'url'          => route('wellness.index'),
                'phone'        => $m->reservation_id ? null : $m->phone,
            ],
            'laundry' => [
                'badge'        => $m->room_number,
                'title'        => 'Laundry ' . $m->number . ($m->express ? ' · express' : ''),
                'subtitle'     => ($m->guest_name ?? 'Guest') . ($m->lines ? ' · ' . $m->pieces . ' pcs · ' . config('hotel.currency_symbol') . number_format((float) $m->total, 2) : ' · no list'),
                'detail'       => ($m->lines ? $m->summary : 'Count the bag on the website when you collect it.') . ($m->note ? ' — “' . $m->note . '”' : ''),
                'meta'         => $m->due_at && $m->status !== LaundryOrder::REQUESTED ? ($m->isLate() ? 'Overdue — was due ' : 'Due back ') . $m->due_at->format('D g:i A') . ($m->issue ? ' · ' . $m->issue : '') : null,
                'status'       => $m->status, 'status_label' => $m->staff_label, 'tone' => $this->tone($m->status_pill),
                'url'          => $m->status === LaundryOrder::REQUESTED ? route('laundry.collect.form', $m) : route('laundry.index'),
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
            'payroll' => $m->submitted_at,
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
            'payroll' => [
                'title'    => 'Payroll ' . $m->month_label, 'subtitle' => $m->number . ' · ' . $m->staff_count . ' staff · PAYE ' . $money($m->paye),
                'meta'     => 'Sent by ' . ($m->submitter?->name ?? '—') . ' · gross ' . $money($m->gross),
                'amount'   => (float) $m->net, 'amount_label' => $money($m->net),
                'url'      => route('payroll.show', $m),
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
            'order'        => GuestOrder::class,
            'laundry'      => LaundryOrder::class,
            'wellness'     => WellnessBooking::class,
            'ride'         => Ride::class,
            'ticket'       => ItTicket::class,
            'expense'      => Expense::class,
            'po'           => PurchaseOrder::class,
            'pr'           => PurchaseRequest::class,
            'payroll'      => \App\Models\Payroll\PayrollRun::class,
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
