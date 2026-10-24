<?php

namespace App\Services\Booking;

use App\Enums\ReservationStatus;
use App\Exceptions\BookingException;
use App\Models\Booking\GuestRequest;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceOrder;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Operations\HousekeepingService;
use App\Services\Operations\MaintenanceService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Requests guests send from their booking page during a stay (spec §12.2).
 * Towels, cleaning and amenities become a housekeeping task straight away;
 * repairs and front-desk requests wait in the guest-requests queue, where
 * staff can pass a repair to maintenance with one tap.
 */
class GuestRequestService
{
    public const MAX_OPEN = 8;

    public function __construct(private HousekeepingService $housekeeping, private MaintenanceService $maintenance)
    {
    }

    public function create(Reservation $r, string $type, ?string $details): GuestRequest
    {
        if (! config('hotel.booking.guest_requests', true)) {
            throw new BookingException('Requests can’t be sent online at the moment — please call reception.');
        }
        if ($r->status !== ReservationStatus::CheckedIn) {
            throw new BookingException('Requests can be sent once you have checked in.');
        }
        if (! isset(GuestRequest::TYPES[$type])) {
            throw new BookingException('Choose what you need.');
        }
        if ($r->guestRequests()->active()->count() >= self::MAX_OPEN) {
            throw new BookingException('You already have several requests open — reception will be with you shortly.');
        }
        $room = $r->rooms()->with('room')->get()->pluck('room')->filter()->first();

        return DB::transaction(function () use ($r, $type, $details, $room) {
            $req = GuestRequest::create(['reservation_id' => $r->id, 'room_id' => $room?->id, 'type' => $type, 'details' => $details, 'status' => 'open']);

            if ($req->route === 'housekeeping' && $room) {
                $task = $this->housekeeping->raise($room, 'other', $r, 'Guest request: ' . $req->type_label . ($details ? ' — ' . $details : ''));
                $req->forceFill(['housekeeping_task_id' => $task->id])->save();
            }
            $r->log('guest_request', "Guest asked for: {$req->type_label}" . ($details ? " — {$details}" : '') . '.');
            Audit::record('guest.request', "Room " . ($room?->number ?? '?') . " ({$r->reference}) asked for {$req->type_label}" . ($details ? ": {$details}" : ''), $req);

            return $req;
        });
    }

    /** Bring requests up to date with the housekeeping task / work order they started. */
    public function refresh($requests): void
    {
        foreach ($requests as $req) {
            if (! in_array($req->status, ['open', 'in_progress'], true)) {
                continue;
            }
            $task = $req->housekeeping_task_id ? $req->housekeepingTask : null;
            $order = $req->maintenance_order_id ? $req->maintenanceOrder : null;
            $status = match (true) {
                $task && in_array($task->status, [HousekeepingTask::DONE, HousekeepingTask::PASSED], true) => 'done',
                $task && $task->status === HousekeepingTask::IN_PROGRESS                               => 'in_progress',
                $task && $task->status === HousekeepingTask::CANCELLED                                 => 'cancelled',
                $order && $order->status === MaintenanceOrder::COMPLETED                                => 'done',
                $order && $order->status === MaintenanceOrder::CANCELLED                                => 'cancelled',
                (bool) $order                                                                          => 'in_progress',
                default                                                                                => $req->status,
            };
            if ($status !== $req->status) {
                $req->forceFill(['status' => $status, 'handled_at' => $req->handled_at ?? now()])->save();
            }
        }
    }

    public function update(GuestRequest $req, User $by, string $status, ?string $note): void
    {
        if (! isset(GuestRequest::STATUSES[$status])) {
            throw new BookingException('Unknown status.');
        }
        $req->forceFill(['status' => $status, 'handled_by' => $by->id, 'handled_at' => now(), 'staff_note' => $note ?: $req->staff_note])->save();
    }

    /** "Send to maintenance": a work order for the guest's room, reported by the member of staff. */
    public function toMaintenance(GuestRequest $req, User $by): MaintenanceOrder
    {
        if ($req->maintenance_order_id) {
            return $req->maintenanceOrder;
        }
        $order = $this->maintenance->report([
            'room_id'     => $req->room_id,
            'location'    => $req->room_id ? null : 'Guest request',
            'category'    => 'other',
            'priority'    => 'high',
            'title'       => 'Guest: ' . str($req->details ?: $req->type_label)->limit(130),
            'description' => "Reported by the guest from their booking page ({$req->reservation?->reference}).",
        ], [], $by);
        $req->forceFill(['maintenance_order_id' => $order->id, 'status' => 'in_progress', 'handled_by' => $by->id, 'handled_at' => now(),
            'staff_note' => $req->staff_note ?: 'A technician is on the way.'])->save();

        return $order;
    }
}
