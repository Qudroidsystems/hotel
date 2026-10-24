<?php

namespace App\Services\Operations;

use App\Enums\RoomStatus;
use App\Exceptions\BookingException;
use App\Models\MaintenanceOrder;
use App\Models\Room;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Work orders (spec §3.4). A job can keep its room out of sale: the room goes
 * to "Under Maintenance" (or stays occupied until the guest leaves, then goes
 * there instead of to cleaning), and when the last such job is completed or
 * cancelled the room goes to "Needs Cleaning" so housekeeping prepares it.
 */
class MaintenanceService
{
    public const PHOTO_DIR = 'maintenance';

    /** @param UploadedFile[] $photos */
    public function report(array $data, array $photos, User $by): MaintenanceOrder
    {
        $priority = $data['priority'] ?? 'normal';

        return DB::transaction(function () use ($data, $photos, $by, $priority) {
            $order = MaintenanceOrder::create([
                'room_id'              => $data['room_id'] ?? null,
                'location'             => $data['location'] ?? null,
                'category'             => $data['category'],
                'title'                => $data['title'],
                'description'          => $data['description'] ?? null,
                'priority'             => $priority,
                'blocks_room'          => ! empty($data['room_id']) && ! empty($data['blocks_room']),
                'assigned_to'          => $data['assigned_to'] ?? null,
                'housekeeping_task_id' => $data['housekeeping_task_id'] ?? null,
                'reported_by'          => $by->id,
                'due_at'               => $data['due_at'] ?? now()->addHours(MaintenanceOrder::PRIORITIES[$priority][2] ?? 72),
            ]);
            $order->forceFill([
                'number' => 'WO-' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                'photos' => $this->store($photos, 'report') ?: null,
            ])->save();

            if ($order->blocks_room && $order->room && ! in_array($order->room->status, [RoomStatus::Occupied, RoomStatus::OutOfService, RoomStatus::Maintenance], true)) {
                $order->room->changeStatus(RoomStatus::Maintenance, "{$order->number}: {$order->title}", $by);
            }

            Audit::record('rooms.fault_reported', "Reported {$order->number} ({$order->priority_label}) at {$order->where}: {$order->title}", $order->room,
                new: ['work_order' => $order->number, 'category' => $order->category, 'priority' => $priority, 'blocks_room' => $order->blocks_room]);

            return $order;
        });
    }

    public function assign(MaintenanceOrder $order, ?User $to): void
    {
        $this->ensureActive($order);
        $order->forceFill(['assigned_to' => $to?->id])->save();
    }

    public function start(MaintenanceOrder $order, User $by): void
    {
        $this->ensureActive($order);
        $order->forceFill(['status' => MaintenanceOrder::IN_PROGRESS, 'started_at' => $order->started_at ?? now(), 'assigned_to' => $order->assigned_to ?? $by->id, 'hold_reason' => null])->save();
    }

    public function hold(MaintenanceOrder $order, string $reason): void
    {
        $this->ensureActive($order);
        $order->forceFill(['status' => MaintenanceOrder::ON_HOLD, 'hold_reason' => $reason])->save();
    }

    /** @param UploadedFile[] $photos */
    public function complete(MaintenanceOrder $order, User $by, string $note, ?float $cost, array $photos = []): void
    {
        $this->ensureActive($order);
        DB::transaction(function () use ($order, $by, $note, $cost, $photos) {
            $order->forceFill([
                'status'          => MaintenanceOrder::COMPLETED,
                'started_at'      => $order->started_at ?? now(),
                'completed_at'    => now(),
                'completed_by'    => $by->id,
                'completion_note' => $note,
                'cost'            => $cost,
                'photos'          => array_merge($order->photos ?? [], $this->store($photos, 'completion')) ?: null,
            ])->save();
            Audit::record('rooms.fault_fixed', "Completed {$order->number} at {$order->where}: {$order->title}" . ($cost ? ' (cost ' . config('hotel.currency_symbol') . number_format($cost, 2) . ')' : ''),
                $order->room, new: ['work_order' => $order->number, 'cost' => $cost], reason: $note, by: $by);
            $this->releaseRoom($order, 'Repair finished');
        });
    }

    public function cancel(MaintenanceOrder $order, string $reason): void
    {
        $this->ensureActive($order);
        DB::transaction(function () use ($order, $reason) {
            $order->forceFill(['status' => MaintenanceOrder::CANCELLED, 'cancel_reason' => $reason])->save();
            Audit::record('rooms.fault_cancelled', "Cancelled {$order->number}: {$order->title}", $order->room, reason: $reason);
            $this->releaseRoom($order, 'Work order cancelled');
        });
    }

    /** Back to cleaning once no other open job keeps the room out of sale. */
    private function releaseRoom(MaintenanceOrder $order, string $why): void
    {
        $room = $order->room;
        if (! $order->blocks_room || ! $room || $room->status !== RoomStatus::Maintenance) {
            return;
        }
        $stillBlocked = MaintenanceOrder::active()->where('room_id', $room->id)->where('blocks_room', true)->exists();
        if (! $stillBlocked) {
            $room->changeStatus(RoomStatus::Dirty, "{$why} ({$order->number}) — needs cleaning before sale");
        }
    }

    private function ensureActive(MaintenanceOrder $order): void
    {
        if (! $order->isActive()) {
            throw new BookingException('This work order is already ' . strtolower($order->status_label) . '.');
        }
    }

    /** @param UploadedFile[] $photos */
    private function store(array $photos, string $stage): array
    {
        $saved = [];
        foreach ($photos as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $saved[] = ['path' => $file->store(self::PHOTO_DIR, 'public'), 'stage' => $stage];
            }
        }

        return $saved;
    }
}
