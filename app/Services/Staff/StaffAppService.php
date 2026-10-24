<?php

namespace App\Services\Staff;

use App\Models\Attendance\AttendanceDay;
use App\Models\CashShift;
use App\Models\Finance\Expense;
use App\Models\FolioCharge;
use App\Models\HousekeepingTask;
use App\Models\Inventory\PurchaseOrder;
use App\Models\Inventory\PurchaseRequest;
use App\Models\Inventory\StockCount;
use App\Models\It\ItAlert;
use App\Models\It\ItTicket;
use App\Models\MaintenanceOrder;
use App\Models\Payment;
use App\Models\Pos\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the Staff App shows each person (spec §16): their own work queue,
 * the approvals waiting for them, and — for managers — today's key figures.
 * Everything reads the same records as the full system; actions on the app
 * go through the existing controllers, so the rules are identical.
 */
class StaffAppService
{
    /** Work assigned to (or open to) this person, most urgent first. */
    public function work(User $user): Collection
    {
        $items = collect();

        if ($user->canAny(['Do housekeeping tasks', 'Manage housekeeping'])) {
            $manager = $user->can('Manage housekeeping');
            HousekeepingTask::open()->with(['room:id,number,floor_id', 'room.floor:id,name', 'assignee:id,name'])
                // Cleaners: rooms still to do (a finished room waits for a supervisor, not them).
                ->when(! $manager, fn ($q) => $q->whereIn('status', [HousekeepingTask::PENDING, HousekeepingTask::IN_PROGRESS])
                    ->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhereNull('assigned_to')))
                ->when($manager, fn ($q) => $q->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhereNull('assigned_to')->orWhere('status', HousekeepingTask::DONE)))
                ->get()
                ->each(fn ($t) => $items->push((object) [
                    'kind' => 'housekeeping', 'model' => $t, 'urgent' => (bool) $t->urgent, 'at' => $t->created_at,
                    'rank' => $t->urgent ? 0 : 2,
                ]));
        }

        if ($user->canAny(['View maintenance', 'Manage maintenance'])) {
            MaintenanceOrder::active()->with(['room:id,number'])
                ->where(fn ($q) => $q->where('assigned_to', $user->id)
                    ->when($user->can('Manage maintenance'), fn ($w) => $w->orWhereNull('assigned_to')))
                ->get()
                ->each(fn ($o) => $items->push((object) [
                    'kind' => 'maintenance', 'model' => $o, 'urgent' => $o->priority === 'urgent' || $o->isOverdue(), 'at' => $o->created_at,
                    'rank' => $o->priority === 'urgent' ? 0 : ($o->isOverdue() ? 1 : 3),
                ]));
        }

        if ($user->can('Handle guest requests')) {
            // Requests that need a person (housekeeping ones arrive as cleaning tasks instead).
            \App\Models\Booking\GuestRequest::active()->whereNull('housekeeping_task_id')->whereNull('maintenance_order_id')
                ->with(['room:id,number', 'reservation.guest:id,first_name,last_name,is_vip'])->get()
                ->each(fn ($g) => $items->push((object) [
                    'kind' => 'guest', 'model' => $g, 'urgent' => $g->created_at->lt(now()->subMinutes(20)) || (bool) $g->reservation?->guest?->is_vip,
                    'at' => $g->created_at, 'rank' => $g->status === 'open' ? 0 : 2,
                ]));
        }

        if ($user->can('Manage IT tickets')) {
            ItTicket::active()->with('requester:id,name')
                ->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhereNull('assigned_to'))
                ->get()
                ->each(fn ($t) => $items->push((object) [
                    'kind' => 'ticket', 'model' => $t, 'urgent' => in_array($t->priority, ['critical', 'high'], true) || $t->responseLate() || $t->resolveLate(),
                    'at' => $t->created_at, 'rank' => $t->priority === 'critical' ? 0 : ($t->responseLate() || $t->resolveLate() ? 1 : 3),
                ]));
        }

        return $items->sortBy([['rank', 'asc'], ['at', 'asc']])->values();
    }

    /** Things waiting for this person's decision. Never their own requests. */
    public function approvals(User $user): Collection
    {
        $items = collect();

        if ($user->can('Approve expenses')) {
            Expense::where('status', 'pending')->where('recorded_by', '!=', $user->id)->with(['category:id,name', 'recorder:id,name'])->oldest()->get()
                ->each(fn ($e) => $items->push((object) ['kind' => 'expense', 'model' => $e, 'amount' => (float) $e->amount, 'at' => $e->created_at]));
        }
        if ($user->can('Approve purchases')) {
            PurchaseOrder::where('status', 'pending')->where(fn ($q) => $q->whereNull('submitted_by')->orWhere('submitted_by', '!=', $user->id))
                ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $user->id))
                ->with(['supplier:id,name', 'submittedBy:id,name'])->withCount('lines')->oldest('submitted_at')->get()
                ->each(fn ($po) => $items->push((object) ['kind' => 'po', 'model' => $po, 'amount' => (float) $po->total, 'at' => $po->submitted_at ?? $po->created_at]));
            PurchaseRequest::where('status', 'submitted')->where('requested_by', '!=', $user->id)->with('requester:id,name')->withCount('lines')->oldest()->get()
                ->each(fn ($pr) => $items->push((object) ['kind' => 'pr', 'model' => $pr, 'amount' => (float) $pr->estimated_total, 'at' => $pr->created_at]));
        }
        if ($user->can('Review cash shifts')) {
            CashShift::where('status', CashShift::CLOSED)->where('user_id', '!=', $user->id)->with('user:id,name')->oldest('closed_at')->get()
                ->each(fn ($s) => $items->push((object) ['kind' => 'shift', 'model' => $s, 'amount' => (float) $s->variance, 'at' => $s->closed_at]));
        }
        if ($user->can('Post stock counts')) {
            StockCount::where('status', 'counting')->with(['location:id,name'])->withCount(['lines', 'lines as counted_count' => fn ($q) => $q->whereNotNull('counted_quantity')])->oldest()->get()
                ->filter(fn ($c) => $c->lines_count > 0 && $c->counted_count === $c->lines_count)   // fully counted, ready to post
                ->each(fn ($c) => $items->push((object) ['kind' => 'count', 'model' => $c, 'amount' => null, 'at' => $c->updated_at]));
        }

        return $items->sortBy('at')->values();
    }

    /** Small badge numbers for the bottom bar. */
    public function counts(User $user): array
    {
        return [
            'work'      => $this->work($user)->count(),
            'approvals' => $this->approvals($user)->count(),
        ];
    }

    /** Today in numbers, for managers. */
    public function today(): array
    {
        $rooms = Room::active()->count();
        $inHouse = Reservation::inHouse()->withCount('rooms')->get(['id']);
        $occupied = Room::active()->where('status', 'occupied')->count();
        $start = today()->startOfDay();

        $roomStatus = Room::active()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')
            ->mapWithKeys(fn ($n, $s) => [$s instanceof \BackedEnum ? $s->value : (string) $s => (int) $n]);

        return [
            'rooms'      => $rooms,
            'occupied'   => $occupied,
            'occupancy'  => $rooms ? round($occupied / $rooms * 100) : 0,
            'in_house'   => $inHouse->count(),
            'arrivals'   => Reservation::arrivingOn(today())->count(),
            'arrived'    => Reservation::whereDate('checked_in_at', today())->count(),
            'departures' => Reservation::departingOn(today())->count(),
            'room_status' => $roomStatus,
            'pos_sales'  => (float) PosOrder::whereIn('status', [PosOrder::PAID, PosOrder::CHARGED])->where('ordered_at', '>=', $start)->sum('total'),
            'collected'  => (float) Payment::valid()->where('received_at', '>=', $start)->get(['kind', 'amount'])->sum(fn ($p) => $p->signed_amount)
                + (float) PosOrder::where('status', PosOrder::PAID)->where('ordered_at', '>=', $start)->sum('total'),
            'room_revenue' => (float) FolioCharge::whereNull('voided_at')->where('type', 'room')->whereDate('service_date', today()->subDay())->sum('total'),
            'it_alerts'  => ItAlert::active()->count(),
            'it_down'    => ItAlert::where('status', 'open')->whereIn('severity', ['critical', 'high'])->count(),
            'maintenance' => MaintenanceOrder::active()->count(),
            'dirty'      => ($roomStatus['dirty'] ?? 0) + ($roomStatus['cleaning'] ?? 0),
        ];
    }

    /** Today's arrivals and departures for the front desk. */
    public function frontDesk(): array
    {
        return [
            'arrivals'   => Reservation::arrivingOn(today())->with(['guest:id,title,first_name,last_name,phone,is_vip', 'rooms.room:id,number'])->orderBy('id')->get(),
            'departures' => Reservation::departingOn(today())->with(['guest:id,title,first_name,last_name,is_vip', 'rooms.room:id,number'])->orderBy('id')->get(),
        ];
    }

    public function attendance(User $user): ?AttendanceDay
    {
        return AttendanceDay::where('user_id', $user->id)->whereDate('work_date', today())->first();
    }
}
