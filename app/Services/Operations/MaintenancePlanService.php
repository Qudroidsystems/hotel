<?php

namespace App\Services\Operations;

use App\Exceptions\BookingException;
use App\Models\MaintenanceOrder;
use App\Models\MaintenancePlan;
use App\Models\Room;
use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;

/**
 * Preventive maintenance (spec §3.4: servicing schedules). Each plan repeats
 * every N days, weeks or months. A few days before it is due, the plan opens
 * a work order (one per room when the plan covers every room) that goes
 * through Maintenance Jobs like any other. When the last of those jobs is
 * completed or cancelled, the next due date is set one interval after the day
 * the work was finished, so a late service doesn't bunch up the next ones.
 */
class MaintenancePlanService
{
    public function __construct(private MaintenanceService $maintenance)
    {
    }

    public function save(?MaintenancePlan $plan, array $data, User $by): MaintenancePlan
    {
        if (($data['scope'] ?? 'one') === 'one' && empty($data['room_id']) && blank($data['location'] ?? null)) {
            throw new BookingException('Choose the room, or write where the equipment is.');
        }
        $fields = [
            'title'       => $data['title'],
            'category'    => $data['category'],
            'scope'       => $data['scope'] ?? 'one',
            'room_id'     => ($data['scope'] ?? 'one') === 'one' ? ($data['room_id'] ?? null) : null,
            'location'    => ($data['scope'] ?? 'one') === 'one' ? ($data['location'] ?? null) : null,
            'description' => $data['description'] ?? null,
            'every'       => (int) $data['every'],
            'unit'        => $data['unit'],
            'next_due_on' => $data['next_due_on'],
            'lead_days'   => (int) ($data['lead_days'] ?? 3),
            'priority'    => $data['priority'] ?? 'normal',
            'assigned_to' => $data['assigned_to'] ?? null,
        ];
        if ($plan) {
            $plan->update($fields);
            Audit::record('maintenance.plan_changed', "Changed servicing plan “{$plan->title}” ({$plan->interval_label}, next {$plan->next_due_on->format('j M Y')})", $plan, by: $by);
        } else {
            $plan = MaintenancePlan::create($fields + ['created_by' => $by->id, 'active' => true]);
            Audit::record('maintenance.plan_added', "Added servicing plan “{$plan->title}” ({$plan->interval_label}, first {$plan->next_due_on->format('j M Y')})", $plan, by: $by);
        }

        return $plan;
    }

    public function toggle(MaintenancePlan $plan, User $by): void
    {
        $plan->forceFill(['active' => ! $plan->active])->save();
        Audit::record('maintenance.plan_' . ($plan->active ? 'resumed' : 'paused'), ($plan->active ? 'Resumed' : 'Paused') . " servicing plan “{$plan->title}”", $plan, by: $by);
    }

    /** The plan's jobs still open (the current service). */
    public function openOrders(MaintenancePlan $plan)
    {
        return MaintenanceOrder::where('maintenance_plan_id', $plan->id)->active();
    }

    /** Open the work orders of every plan whose time has come. Answers how many jobs were opened. */
    public function openDue(?Carbon $today = null): int
    {
        $today ??= today();
        $opened = 0;
        MaintenancePlan::where('active', true)->orderBy('next_due_on')->get()
            ->filter(fn (MaintenancePlan $p) => $p->opensOn()->lte($today))
            ->each(function (MaintenancePlan $p) use (&$opened) {
                if (! $this->openOrders($p)->exists()) {
                    $opened += $this->open($p);
                }
            });

        return $opened;
    }

    /** Open this plan's jobs now (also the "Open the job now" button). */
    public function open(MaintenancePlan $plan, ?User $by = null): int
    {
        if ($this->openOrders($plan)->exists()) {
            throw new BookingException('The job for this service is already open.');
        }
        $by ??= User::find($plan->created_by) ?? User::role('Super Admin')->first() ?? User::first();
        $rooms = $plan->scope === 'rooms' ? Room::active()->orderBy('number')->get(['id', 'number']) : collect([null]);
        $due = $plan->next_due_on->copy()->setTime(17, 0);
        $n = 0;
        foreach ($rooms as $room) {
            $order = $this->maintenance->report([
                'room_id'     => $room?->id ?? $plan->room_id,
                'location'    => $room ? null : $plan->location,
                'category'    => $plan->category,
                'title'       => $plan->title,
                'description' => trim("Planned servicing ({$plan->interval_label}, due " . $plan->next_due_on->format('D j M') . ").\n\n" . ($plan->description ?? '')),
                'priority'    => $plan->priority,
                'assigned_to' => $plan->assigned_to,
                'due_at'      => $due->isPast() ? now()->addHours(MaintenanceOrder::PRIORITIES[$plan->priority][2] ?? 72) : $due,
            ], [], $by);
            $order->forceFill(['maintenance_plan_id' => $plan->id])->save();
            $n++;
        }

        return $n;
    }

    /** A job of a plan was completed or cancelled: when it was the last open one, set the next due date. */
    public function jobClosed(MaintenanceOrder $order): void
    {
        $plan = $order->maintenance_plan_id ? MaintenancePlan::find($order->maintenance_plan_id) : null;
        if (! $plan || $this->openOrders($plan)->exists()) {
            return;
        }
        $done = MaintenanceOrder::where('maintenance_plan_id', $plan->id)->where('status', MaintenanceOrder::COMPLETED)
            ->where('created_at', '>=', $order->created_at->copy()->subMinute())->max('completed_at');
        $from = $done ? Carbon::parse($done)->startOfDay() : $plan->next_due_on;   // all cancelled: skip this one, keep the rhythm
        $next = $plan->after($from);
        while ($next->lte(today())) {
            $next = $plan->after($next);   // never a due date in the past
        }
        $plan->forceFill(['next_due_on' => $next, 'last_done_on' => $done ? Carbon::parse($done)->toDateString() : $plan->last_done_on])->save();
    }
}
