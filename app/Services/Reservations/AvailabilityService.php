<?php

namespace App\Services\Reservations;

use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Answers "what can we sell for these dates?".
 *
 * A room is unavailable for a stay [in, out) when:
 *  - it is inactive, under maintenance or out of service, or
 *  - it is assigned to another open reservation (pending / confirmed /
 *    checked in) that shares at least one night, or
 *  - the stay starts today or earlier and the room is currently Occupied
 *    (covers guests placed in a room without a reservation).
 *
 * Room-type inventory also counts bookings that have no room assigned yet,
 * so a type can't be oversold even before rooms are allocated.
 */
class AvailabilityService
{
    /** Statuses that take a room off sale regardless of dates. */
    public const BLOCKING_ROOM_STATUSES = [RoomStatus::Maintenance, RoomStatus::OutOfService];

    /** Overlapping reservation_rooms on open reservations. */
    public function overlappingLines($in, $out, ?int $excludeReservationId = null): Collection
    {
        return ReservationRoom::query()
            ->with('reservation:id,check_in_date,check_out_date,status')
            ->whereHas('reservation', function ($q) use ($in, $out, $excludeReservationId) {
                $q->open()->overlapping($in, $out)
                  ->when($excludeReservationId, fn ($q) => $q->where('id', '!=', $excludeReservationId));
            })
            ->get();
    }

    /** IDs of rooms already taken by other open reservations during [in, out). */
    public function bookedRoomIds($in, $out, ?int $excludeReservationId = null): array
    {
        return $this->overlappingLines($in, $out, $excludeReservationId)
            ->pluck('room_id')->filter()->unique()->values()->all();
    }

    /** Rooms of a type (or all types) that are free for the whole stay. */
    public function freeRooms($in, $out, ?int $roomTypeId = null, ?int $excludeReservationId = null): Collection
    {
        $booked = $this->bookedRoomIds($in, $out, $excludeReservationId);
        $startsNowOrEarlier = Carbon::parse($in)->lte(today());

        return Room::query()
            ->with(['roomType', 'floor'])
            ->active()
            ->when($roomTypeId, fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->whereNotIn('status', array_map(fn ($s) => $s->value, self::BLOCKING_ROOM_STATUSES))
            ->when($startsNowOrEarlier, fn ($q) => $q->where('status', '!=', RoomStatus::Occupied->value))
            ->whereNotIn('id', $booked)
            ->get()
            ->sort(fn ($a, $b) => strnatcasecmp($a->number, $b->number))
            ->values();
    }

    /**
     * Per room type: rooms that can be sold for every night of the stay,
     * plus a price quote.
     *
     * @return Collection<int, array{type: RoomType, total: int, available: int, nights: array, amount: float}>
     */
    public function summary($in, $out, ?int $excludeReservationId = null): Collection
    {
        $nights = Reservation::nightsBetween($in, $out);
        $lines = $this->overlappingLines($in, $out, $excludeReservationId);
        $startsNowOrEarlier = Carbon::parse($in)->lte(today());

        return RoomType::where('is_active', true)->orderBy('base_rate')->get()->map(function (RoomType $type) use ($in, $out, $nights, $lines, $startsNowOrEarlier, $excludeReservationId) {
            $sellable = Room::active()->where('room_type_id', $type->id)
                ->whereNotIn('status', array_map(fn ($s) => $s->value, self::BLOCKING_ROOM_STATUSES))
                ->get(['id', 'status']);

            $sellableIds = $sellable->pluck('id')->all();

            // A booking line uses one of this type's rooms on a night when it is
            // assigned to one of them, or is booked as this type with no room yet.
            $relevant = $lines->filter(fn ($l) => $l->room_id
                ? in_array($l->room_id, $sellableIds, true)
                : (int) $l->room_type_id === $type->id);

            // Rooms someone is in right now without a reservation block stays starting today.
            $occupiedNow = 0;
            if ($startsNowOrEarlier) {
                // Includes the excluded reservation's own rooms (e.g. a checked-in guest extending).
                $assignedIds = $excludeReservationId ? $this->bookedRoomIds($in, $out) : $lines->pluck('room_id')->filter()->all();
                $occupiedNow = $sellable->filter(fn ($r) => $r->status === RoomStatus::Occupied && ! in_array($r->id, $assignedIds, true))->count();
            }

            $peak = 0;
            foreach ($nights as $night) {
                $used = $relevant->filter(fn ($l) => $l->reservation->check_in_date->toDateString() <= $night
                                                  && $l->reservation->check_out_date->toDateString() > $night)->count();
                $peak = max($peak, $used);
            }
            $peak += $occupiedNow;

            $available = max(0, $sellable->count() - $peak);
            $quote = $this->quote($type, $in, $out);

            return [
                'type'      => $type,
                'total'     => $sellable->count(),
                'available' => $available,
                'nights'    => $quote,
                'amount'    => array_sum($quote),
            ];
        });
    }

    public function availableForType(int $roomTypeId, $in, $out, ?int $excludeReservationId = null): int
    {
        return (int) ($this->summary($in, $out, $excludeReservationId)->firstWhere('type.id', $roomTypeId)['available'] ?? 0);
    }

    /**
     * Nightly rates for a stay: {"2026-10-23": 40000.0, …}.
     * A room's custom rate (if any) applies to every night.
     */
    public function quote(RoomType $type, $in, $out, ?Room $room = null): array
    {
        $rates = [];
        foreach (Reservation::nightsBetween($in, $out) as $night) {
            $rates[$night] = $room?->rate_override !== null
                ? (float) $room->rate_override
                : $type->rateFor(Carbon::parse($night));
        }

        return $rates;
    }

    /** Is a specific room free for the stay (ignoring one reservation, e.g. the one being edited)? */
    public function roomIsFree(Room $room, $in, $out, ?int $excludeReservationId = null): bool
    {
        return ! in_array($room->id, $this->bookedRoomIds($in, $out, $excludeReservationId), true);
    }
}
