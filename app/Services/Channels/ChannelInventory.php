<?php

namespace App\Services\Channels;

use App\Enums\RoomStatus;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Reservations\AvailabilityService;
use Illuminate\Support\Carbon;

/**
 * Rooms left of each type, night by night, counted the same way as the front desk's availability. The
 * room calendars (iCal) use it to show nights a room type is full.
 */
class ChannelInventory
{
    /**
     * Rooms free each night, per room type: [room_type_id => ['2026-11-02' => 3, ...]]. Nights run from
     * $from to the night before $to.
     */
    public function availability(Carbon $from, Carbon $to, ?array $typeIds = null): array
    {
        $nights = Reservation::nightsBetween($from, $to);
        if (! $nights) {
            return [];
        }
        $blocking = array_map(fn ($s) => $s->value, AvailabilityService::BLOCKING_ROOM_STATUSES);
        $rooms = Room::active()->whereNotIn('status', $blocking)->when($typeIds, fn ($q) => $q->whereIn('room_type_id', $typeIds))->get(['id', 'room_type_id', 'status']);
        $typeOfRoom = Room::pluck('room_type_id', 'id');

        $lines = ReservationRoom::with('reservation:id,check_in_date,check_out_date,status')
            ->whereHas('reservation', fn ($q) => $q->open()->overlapping($from, $to))->get();

        // Rooms someone is in tonight without a booking line on them (e.g. a stay extended at the desk).
        $assigned = $lines->pluck('room_id')->filter()->all();
        $occupiedNow = $rooms->filter(fn ($r) => $r->status === RoomStatus::Occupied && ! in_array($r->id, $assigned, true))->countBy('room_type_id');

        $types = $typeIds ?? RoomType::where('is_active', true)->pluck('id')->all();
        $out = [];
        foreach ($types as $typeId) {
            $sellable = $rooms->where('room_type_id', $typeId)->count();
            $mine = $lines->filter(fn ($l) => (int) ($l->room_id ? ($typeOfRoom[$l->room_id] ?? $l->room_type_id) : $l->room_type_id) === (int) $typeId);
            foreach ($nights as $night) {
                $used = $mine->filter(fn ($l) => $l->reservation->check_in_date->toDateString() <= $night && $l->reservation->check_out_date->toDateString() > $night)->count();
                if ($night === today()->toDateString()) {
                    $used += (int) ($occupiedNow[$typeId] ?? 0);
                }
                $out[$typeId][$night] = max(0, $sellable - $used);
            }
        }

        return $out;
    }

    /**
     * Runs of consecutive nights with the same value: [[from, to, value], ...] (to is the last night).
     *
     * @param array<string, mixed> $nights date => value, in date order
     */
    public function runs(array $nights): array
    {
        $runs = [];
        foreach ($nights as $date => $value) {
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last][2] === $value && Carbon::parse($runs[$last][1])->addDay()->toDateString() === $date) {
                $runs[$last][1] = $date;
            } else {
                $runs[] = [$date, $date, $value];
            }
        }

        return $runs;
    }
}
