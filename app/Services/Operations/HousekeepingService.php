<?php

namespace App\Services\Operations;

use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Exceptions\BookingException;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\User;
use App\Services\Reservations\ReservationService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Room cleaning lifecycle (spec §3.3):
 *
 *   room set to Needs Cleaning (check-out, room move, repair done…)
 *     → task "To clean" → cleaner starts (room Being Cleaned) → finishes
 *     → supervisor inspects: pass = room Available (Reserved if a guest arrives
 *       today), fail = back to "To clean" with the supervisor's note.
 *
 * Stay-over and other tasks on occupied rooms never change the room's status.
 */
class HousekeepingService
{
    /** Called for every room status change (AppServiceProvider). */
    public function roomStatusChanged(RoomStatusLog $log): void
    {
        $room = $log->room;
        if (! $room) {
            return;
        }

        if ($log->to_status === RoomStatus::Dirty) {
            // A repair is still open on this room: keep it out of sale instead.
            $blocking = MaintenanceOrder::active()->where('room_id', $room->id)->where('blocks_room', true)->first();
            if ($blocking) {
                $room->changeStatus(RoomStatus::Maintenance, "Kept out of sale for {$blocking->number}: {$blocking->title}");

                return;
            }
            $this->raise($room, 'checkout', $this->departedReservation($room), null);
        } elseif ($log->to_status === RoomStatus::Available) {
            // Released by hand from the room board: nothing left to clean.
            HousekeepingTask::open()->where('room_id', $room->id)->where('type', 'checkout')->get()
                ->each(fn ($t) => $t->forceFill(['status' => HousekeepingTask::CANCELLED, 'inspection_note' => 'Room set to Available on the room board.'])->save());
        }
    }

    /** Open a cleaning task for a room, unless one is already open. */
    public function raise(Room $room, string $type = 'checkout', ?Reservation $reservation = null, ?string $notes = null, ?int $assignTo = null): HousekeepingTask
    {
        if (! isset(HousekeepingTask::TYPES[$type])) {
            throw new BookingException('Unknown kind of housekeeping task.');
        }
        $existing = HousekeepingTask::open()->where('room_id', $room->id)->where('type', $type)->first();
        if ($existing) {
            return $existing;
        }

        return HousekeepingTask::create([
            'room_id'        => $room->id,
            'type'           => $type,
            'urgent'         => $this->arrivalToday($room) !== null,
            'reservation_id' => $reservation?->id,
            'notes'          => $notes,
            'assigned_to'    => $assignTo,
            'created_by'     => auth()->id(),
        ]);
    }

    /** Rooms marked Needs Cleaning / Being Cleaned without a task (e.g. set before this module existed) get one. */
    public function syncDirtyRooms(): int
    {
        $rooms = Room::active()->whereIn('status', [RoomStatus::Dirty->value, RoomStatus::Cleaning->value])
            ->whereDoesntHave('housekeepingTasks', fn ($q) => $q->whereIn('status', HousekeepingTask::OPEN))->get();
        foreach ($rooms as $room) {
            $this->raise($room, 'checkout');
        }

        return $rooms->count();
    }

    /** A stay-over service task for every occupied room that hasn't got one open. */
    public function raiseStayovers(): int
    {
        $count = 0;
        foreach (Room::active()->where('status', RoomStatus::Occupied->value)->get() as $room) {
            if (! HousekeepingTask::open()->where('room_id', $room->id)->where('type', 'stayover')->exists()) {
                $this->raise($room, 'stayover');
                $count++;
            }
        }

        return $count;
    }

    public function assign(HousekeepingTask $task, ?User $to): void
    {
        $this->ensureOpen($task);
        $task->forceFill(['assigned_to' => $to?->id])->save();
    }

    public function start(HousekeepingTask $task, User $by): void
    {
        if ($task->status !== HousekeepingTask::PENDING) {
            throw new BookingException('This room is not waiting to be cleaned.');
        }
        DB::transaction(function () use ($task, $by) {
            $task->forceFill(['status' => HousekeepingTask::IN_PROGRESS, 'started_at' => now(), 'assigned_to' => $task->assigned_to ?? $by->id])->save();
            if ($task->room->status === RoomStatus::Dirty) {
                $task->room->changeStatus(RoomStatus::Cleaning, 'Cleaning started by ' . $by->name, $by);
            }
        });
    }

    public function finish(HousekeepingTask $task, User $by, ?string $notes = null): void
    {
        if (! in_array($task->status, [HousekeepingTask::PENDING, HousekeepingTask::IN_PROGRESS], true)) {
            throw new BookingException('This task is not in progress.');
        }
        DB::transaction(function () use ($task, $by, $notes) {
            $task->forceFill([
                'status'      => HousekeepingTask::DONE,
                'started_at'  => $task->started_at ?? now(),
                'finished_at' => now(),
                'finished_by' => $by->id,
                'notes'       => $notes ? trim(($task->notes ? $task->notes . ' · ' : '') . $notes) : $task->notes,
            ])->save();

            if (! config('hotel.housekeeping.require_inspection', true) || $task->type !== 'checkout') {
                $this->pass($task, $by, null, false);
            }
        });
    }

    public function inspect(HousekeepingTask $task, User $by, bool $passed, ?string $note): void
    {
        if ($task->status !== HousekeepingTask::DONE) {
            throw new BookingException('Only rooms that have been cleaned can be inspected.');
        }
        if (! $passed && blank($note)) {
            throw new BookingException('Say what needs doing again.');
        }

        DB::transaction(function () use ($task, $by, $passed, $note) {
            if ($passed) {
                $this->pass($task, $by, $note, true);

                return;
            }
            $task->forceFill([
                'status'             => HousekeepingTask::PENDING,
                'inspection_note'    => $note,
                'failed_inspections' => $task->failed_inspections + 1,
                'started_at'         => null,
                'finished_at'        => null,
            ])->save();
            if ($task->room->status === RoomStatus::Cleaning) {
                $task->room->changeStatus(RoomStatus::Dirty, "Failed inspection: {$note}", $by);
            }
            Audit::record('rooms.inspection_failed', "Room {$task->room->number} failed inspection", $task->room, reason: $note, by: $by);
        });
    }

    public function cancel(HousekeepingTask $task, string $reason): void
    {
        $this->ensureOpen($task);
        $task->forceFill(['status' => HousekeepingTask::CANCELLED, 'inspection_note' => $reason])->save();
    }

    /** Room released: Available, or Reserved straight away if its guest arrives today. */
    private function pass(HousekeepingTask $task, User $by, ?string $note, bool $inspected): void
    {
        $task->forceFill([
            'status'          => HousekeepingTask::PASSED,
            'inspected_at'    => $inspected ? now() : null,
            'inspected_by'    => $inspected ? $by->id : null,
            'inspection_note' => $note,
        ])->save();

        $room = $task->room;
        if (in_array($room->status, [RoomStatus::Dirty, RoomStatus::Cleaning], true)) {
            $room->changeStatus(RoomStatus::Available, $inspected ? "Inspected and ready ({$by->name})" : "Cleaned ({$by->name})", $by);
            if ($arrival = $this->arrivalToday($room)) {
                app(ReservationService::class)->markArrivalRoomsReserved($arrival);
            }
        }
    }

    private function ensureOpen(HousekeepingTask $task): void
    {
        if (! $task->isOpen()) {
            throw new BookingException('This task is already closed.');
        }
    }

    private function arrivalToday(Room $room): ?Reservation
    {
        return Reservation::whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Confirmed->value])
            ->whereDate('check_in_date', today()->toDateString())
            ->whereHas('rooms', fn ($q) => $q->where('room_id', $room->id))
            ->with('rooms.room')->first();
    }

    private function departedReservation(Room $room): ?Reservation
    {
        // The room goes to cleaning while the check-out (or room move) is still being saved.
        return Reservation::whereIn('status', [ReservationStatus::CheckedIn->value, ReservationStatus::CheckedOut->value])
            ->where(fn ($q) => $q->whereNull('checked_out_at')->orWhere('checked_out_at', '>=', now()->subHour()))
            ->whereHas('rooms', fn ($q) => $q->where('room_id', $room->id))
            ->latest('checked_in_at')->first();
    }
}
