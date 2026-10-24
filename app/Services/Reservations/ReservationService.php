<?php

namespace App\Services\Reservations;

use App\Enums\BookingSource;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Exceptions\BookingException;
use App\Exceptions\OutstandingBalanceException;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Billing\FolioService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every change to a reservation goes through here so availability is
 * re-checked inside a transaction, room statuses stay in step with the
 * booking, and each step is written to the reservation's event log.
 */
class ReservationService
{
    public function __construct(private AvailabilityService $availability, private FolioService $folios)
    {
    }

    /**
     * @param  array  $data  check_in_date, check_out_date, adults, children, source, status,
     *                       expected_arrival_time, special_requests, notes
     * @param  array  $lines [['room_type_id' => 1, 'room_id' => null|5, 'adults' => 2, 'children' => 0], …]
     */
    public function create(Guest $guest, array $data, array $lines): Reservation
    {
        $in = Carbon::parse($data['check_in_date'])->startOfDay();
        $out = Carbon::parse($data['check_out_date'])->startOfDay();

        if ($out->lte($in)) {
            throw new BookingException('Check-out must be after check-in.');
        }
        if ($in->lt(today())) {
            throw new BookingException('Check-in date cannot be in the past.');
        }
        if (empty($lines)) {
            throw new BookingException('Add at least one room.');
        }

        return DB::transaction(function () use ($guest, $data, $lines, $in, $out) {
            // Serialise bookings for the same room types so two clerks can't sell the last room twice.
            $typeIds = collect($lines)->pluck('room_type_id')->unique()->all();
            $types = RoomType::whereIn('id', $typeIds)->lockForUpdate()->get()->keyBy('id');

            // Each line uses a room of its chosen room's type (or the booked type when unassigned).
            $roomTypesById = Room::whereIn('id', collect($lines)->pluck('room_id')->filter())->pluck('room_type_id', 'id');
            $byType = collect($lines)->groupBy(fn ($l) => ! empty($l['room_id']) ? ($roomTypesById[$l['room_id']] ?? $l['room_type_id']) : $l['room_type_id']);
            $types = $types->union(RoomType::whereIn('id', $byType->keys())->whereNotIn('id', $types->keys())->lockForUpdate()->get()->keyBy('id'));

            foreach ($byType as $typeId => $group) {
                $type = $types[$typeId] ?? throw new BookingException('Unknown room type.');
                $available = $this->availability->availableForType($type->id, $in, $out);
                if ($group->count() > $available) {
                    throw new BookingException("Only {$available} {$type->name} room(s) left for these dates.");
                }
            }

            $this->assertRoomsFree($lines, $in, $out);

            $status = ReservationStatus::from($data['status'] ?? ReservationStatus::Confirmed->value);

            $reservation = Reservation::create([
                'reference'             => Reservation::newReference(),
                'qr_token'              => Reservation::newQrToken(),
                'guest_id'              => $guest->id,
                'status'                => $status,
                'source'                => BookingSource::from($data['source'] ?? BookingSource::WalkIn->value),
                'check_in_date'         => $in,
                'check_out_date'        => $out,
                'adults'                => (int) collect($lines)->sum('adults') ?: (int) ($data['adults'] ?? 1),
                'children'              => (int) collect($lines)->sum('children'),
                'expected_arrival_time' => $data['expected_arrival_time'] ?? null,
                'special_requests'      => $data['special_requests'] ?? null,
                'notes'                 => $data['notes'] ?? null,
                'created_by'            => auth()->id(),
            ]);
            if ($status === ReservationStatus::Confirmed) {
                $reservation->forceFill(['confirmed_at' => now()])->save();
            }

            foreach ($lines as $line) {
                $type = $types[$line['room_type_id']];
                $room = ! empty($line['room_id']) ? Room::find($line['room_id']) : null;
                $rates = $this->availability->quote($type, $in, $out, $room);

                $reservation->rooms()->create([
                    'room_type_id' => $type->id,
                    'room_id'      => $room?->id,
                    'adults'       => max(1, (int) ($line['adults'] ?? 1)),
                    'children'     => (int) ($line['children'] ?? 0),
                    'nightly_rates' => $rates,
                    'amount'       => array_sum($rates),
                ]);
            }

            $reservation->recalculateTotals();
            $reservation->log('created', sprintf(
                '%s booking for %d night(s), %d room(s). Source: %s.',
                $status->label(), $reservation->nights, count($lines), $reservation->source->label()
            ));

            $this->folios->forReservation($reservation);
            $this->markArrivalRoomsReserved($reservation->fresh('rooms.room'));

            return $reservation;
        });
    }

    public function confirm(Reservation $reservation): void
    {
        if ($reservation->status !== ReservationStatus::Pending) {
            throw new BookingException('Only pending reservations can be confirmed.');
        }

        $reservation->forceFill(['status' => ReservationStatus::Confirmed, 'confirmed_at' => now()])->save();
        $reservation->log('confirmed', 'Reservation confirmed.');
        $this->markArrivalRoomsReserved($reservation->load('rooms.room'));
    }

    /** Assign (or change) the room for one line of a reservation. */
    public function assignRoom(ReservationRoom $line, Room $room, ?string $reason = null): void
    {
        $reservation = $line->reservation;

        if (! $reservation->status->isOpen()) {
            throw new BookingException("This reservation is {$reservation->status->label()}; rooms can't be changed.");
        }
        if (! $room->is_active || in_array($room->status, AvailabilityService::BLOCKING_ROOM_STATUSES, true)) {
            throw new BookingException("Room {$room->number} is {$room->status->label()} and can't be assigned.");
        }

        if ($reservation->status === ReservationStatus::CheckedIn && $room->status !== RoomStatus::Available) {
            throw new BookingException("Room {$room->number} is {$room->status->label()}. Move the guest to a room that is ready.");
        }
        if ($reservation->check_in_date->lte(today()) && $room->status === RoomStatus::Occupied && $line->room_id !== $room->id) {
            throw new BookingException("Room {$room->number} is occupied right now.");
        }

        DB::transaction(function () use ($line, $room, $reason, $reservation) {
            Room::whereKey($room->id)->lockForUpdate()->first();

            // The room must be free for the remaining stay (from today if the guest is already in).
            $from = $reservation->status === ReservationStatus::CheckedIn ? today()->max($reservation->check_in_date) : $reservation->check_in_date;
            $sameReservationOther = $reservation->rooms()->where('id', '!=', $line->id)->where('room_id', $room->id)->exists();
            if ($sameReservationOther || ! $this->availability->roomIsFree($room, $from, $reservation->check_out_date, $reservation->id)) {
                throw new BookingException("Room {$room->number} is already booked for some of these nights.");
            }

            $old = $line->room;
            $line->update(['room_id' => $room->id]);

            $isChange = $old && $old->id !== $room->id;
            $typeNote = $room->room_type_id !== $line->room_type_id
                ? " ({$room->roomType->name} room on a {$line->roomType->name} booking — rate unchanged)"
                : '';
            $reservation->log('room_assigned', ($isChange
                ? "Moved from room {$old->number} to room {$room->number}"
                : "Room {$room->number} assigned") . $typeNote . ($reason ? ". Reason: {$reason}" : '.'));

            if ($reservation->status === ReservationStatus::CheckedIn) {
                // Mid-stay room move: the new room becomes occupied, the old one needs cleaning.
                $room->changeStatus(RoomStatus::Occupied, "Room move — {$reservation->reference}");
                if ($isChange) {
                    $old->changeStatus(RoomStatus::Dirty, "Guest moved to {$room->number} — {$reservation->reference}");
                }
            } else {
                if ($isChange) {
                    $this->releaseReservedRoom($old, $reservation);
                }
                $this->markArrivalRoomsReserved($reservation->load('rooms.room'));
            }
        });
    }

    public function unassignRoom(ReservationRoom $line): void
    {
        $reservation = $line->reservation;
        if ($reservation->status === ReservationStatus::CheckedIn) {
            throw new BookingException('The guest is checked in; move them to another room instead.');
        }
        if (! $line->room) {
            return;
        }

        $room = $line->room;
        $line->update(['room_id' => null]);
        $reservation->log('room_released', "Room {$room->number} released.");
        $this->releaseReservedRoom($room, $reservation);
    }

    public function checkIn(Reservation $reservation): void
    {
        $reservation->load('rooms.room');

        if (! in_array($reservation->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true)) {
            throw new BookingException("Can't check in a reservation that is {$reservation->status->label()}.");
        }
        if ($reservation->check_in_date->gt(today())) {
            throw new BookingException('This booking starts on ' . $reservation->check_in_date->format('D j M') . '. Change the arrival date to check in early.');
        }
        if ($reservation->check_out_date->lte(today())) {
            throw new BookingException('The stay has already ended. Mark it as a no-show or change the dates.');
        }
        if (! $reservation->allRoomsAssigned()) {
            throw new BookingException('Assign a room to every line before check-in.');
        }
        foreach ($reservation->rooms as $line) {
            if (! in_array($line->room->status, [RoomStatus::Available, RoomStatus::Reserved], true)) {
                throw new BookingException("Room {$line->room->number} is {$line->room->status->label()}. It must be ready before the guest goes in.");
            }
        }

        DB::transaction(function () use ($reservation) {
            foreach ($reservation->rooms as $line) {
                $line->room->changeStatus(RoomStatus::Occupied, "Checked in — {$reservation->reference} ({$reservation->guest->full_name})");
            }
            $reservation->forceFill([
                'status'        => ReservationStatus::CheckedIn,
                'checked_in_at' => now(),
                'checked_in_by' => auth()->id(),
                'confirmed_at'  => $reservation->confirmed_at ?? now(),
            ])->save();
            $reservation->log('checked_in', 'Guest checked in to room(s) ' . $reservation->rooms->pluck('room.number')->implode(', ') . '.');
        });
    }

    /**
     * @param  string|null  $balanceReason  Required to check out while the guest still owes money
     *                                      (caller must hold the "Check out with balance" permission).
     */
    public function checkOut(Reservation $reservation, ?string $balanceReason = null): void
    {
        $reservation->load('rooms.room');

        if ($reservation->status !== ReservationStatus::CheckedIn) {
            throw new BookingException('Only checked-in guests can be checked out.');
        }

        // Bill every night used before checking the balance. A same-day (day-use) stay is billed one night.
        $folio = $this->folios->forReservation($reservation);
        $this->folios->postRoomNights($reservation);
        if (! $folio->charges()->where('type', \App\Enums\ChargeType::Room->value)->exists()) {
            $this->folios->postRoomNights($reservation, $reservation->check_in_date->copy()->addDay());
        }

        $balance = $folio->balance();
        if ($balance >= 0.01 && blank($balanceReason)) {
            throw new OutstandingBalanceException($balance);
        }

        DB::transaction(function () use ($reservation, $balance, $balanceReason, $folio) {
            foreach ($reservation->rooms as $line) {
                // Spec §3 automation: the room needs cleaning as soon as the guest leaves.
                $line->room?->changeStatus(RoomStatus::Dirty, "Checked out — {$reservation->reference}");
            }

            $early = $reservation->check_out_date->gt(today());
            $reservation->forceFill([
                'status'         => ReservationStatus::CheckedOut,
                'checked_out_at' => now(),
                'checked_out_by' => auth()->id(),
            ])->save();
            $reservation->log('checked_out', 'Guest checked out' . ($early ? ' early (booked until ' . $reservation->check_out_date->format('D j M') . ')' : '') . '. Room(s) set to Needs Cleaning.'
                . ($balance >= 0.01 ? ' Left owing ' . $this->folios->money($balance) . ". Reason: {$balanceReason}" : ''));
            $this->folios->syncStatus($folio);
        });
    }

    public function cancel(Reservation $reservation, string $reason): void
    {
        if (! in_array($reservation->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true)) {
            throw new BookingException("A reservation that is {$reservation->status->label()} can't be cancelled.");
        }

        DB::transaction(function () use ($reservation, $reason) {
            $reservation->forceFill([
                'status'        => ReservationStatus::Cancelled,
                'cancel_reason' => $reason,
                'cancelled_at'  => now(),
                'cancelled_by'  => auth()->id(),
            ])->save();
            $reservation->log('cancelled', "Cancelled. Reason: {$reason}");
            $this->releaseAllReservedRooms($reservation);
            $this->folios->syncStatus($this->folios->forReservation($reservation));
        });
    }

    public function markNoShow(Reservation $reservation): void
    {
        if (! in_array($reservation->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true)
            || $reservation->check_in_date->gt(today())) {
            throw new BookingException('Only arrivals due today or earlier can be marked as no-show.');
        }

        DB::transaction(function () use ($reservation) {
            $reservation->forceFill(['status' => ReservationStatus::NoShow])->save();
            $reservation->log('no_show', 'Guest did not arrive. Marked as no-show.');
            $this->releaseAllReservedRooms($reservation);
            $this->folios->syncStatus($this->folios->forReservation($reservation));
        });
    }

    /**
     * Change dates (modification / extension). Nights already on the booking
     * keep their original rate; new nights are priced at today's rates.
     */
    public function changeDates(Reservation $reservation, $in, $out, ?string $reason = null): void
    {
        $reservation->load('rooms.room', 'rooms.roomType');
        $in = Carbon::parse($in)->startOfDay();
        $out = Carbon::parse($out)->startOfDay();
        $checkedIn = $reservation->status === ReservationStatus::CheckedIn;

        if (! $reservation->status->isOpen()) {
            throw new BookingException("A reservation that is {$reservation->status->label()} can't be changed.");
        }
        if ($checkedIn && ! $in->eq($reservation->check_in_date)) {
            throw new BookingException('The guest is already checked in; only the departure date can change.');
        }
        if ($out->lte($in)) {
            throw new BookingException('Check-out must be after check-in.');
        }
        if (! $checkedIn && $in->lt(today())) {
            throw new BookingException('Arrival date cannot be in the past.');
        }
        if ($checkedIn && $out->lt(today())) {
            throw new BookingException('Departure date cannot be in the past.');
        }

        $oldIn = $reservation->check_in_date->copy();
        $oldOut = $reservation->check_out_date->copy();

        DB::transaction(function () use ($reservation, $in, $out, $reason, $oldIn, $oldOut) {
            RoomType::whereIn('id', $reservation->rooms->pluck('room_type_id'))->lockForUpdate()->get();

            // Type inventory: each line uses a room of its assigned room's type (or its booked type).
            foreach ($reservation->rooms->groupBy(fn ($l) => $l->room?->room_type_id ?? $l->room_type_id) as $typeId => $group) {
                $available = $this->availability->availableForType((int) $typeId, $in, $out, $reservation->id);
                if ($group->count() > $available) {
                    $name = RoomType::find($typeId)?->name ?? 'room';
                    throw new BookingException("Not enough {$name} rooms for the new dates (only {$available} free).");
                }
            }
            // …and each assigned room must stay free.
            foreach ($reservation->rooms->whereNotNull('room_id') as $line) {
                if (! $this->availability->roomIsFree($line->room, $in, $out, $reservation->id)) {
                    throw new BookingException("Room {$line->room->number} is booked by someone else on some of the new nights. Move this guest to another room first.");
                }
            }

            foreach ($reservation->rooms as $line) {
                $fresh = $this->availability->quote($line->roomType, $in, $out, $line->room);
                $kept = array_intersect_key($line->nightly_rates ?? [], $fresh);
                $rates = array_merge($fresh, $kept);
                ksort($rates);
                $line->update(['nightly_rates' => $rates, 'amount' => array_sum($rates)]);
            }

            $reservation->forceFill(['check_in_date' => $in, 'check_out_date' => $out])->save();
            $reservation->recalculateTotals();
            $reservation->log('dates_changed', sprintf(
                'Dates changed from %s – %s to %s – %s (%d night(s)).%s',
                $oldIn->format('j M'), $oldOut->format('j M Y'), $in->format('j M'), $out->format('j M Y'),
                $reservation->nights, $reason ? " Reason: {$reason}" : ''
            ));

            // Arrival moved away from today → release any Reserved room; moved to today → reserve.
            if (! $in->isToday()) {
                $this->releaseAllReservedRooms($reservation);
            }
            $this->markArrivalRoomsReserved($reservation->load('rooms.room'));
        });
    }

    public function applyDiscount(Reservation $reservation, float $amount, string $reason): void
    {
        if (in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)) {
            throw new BookingException('This reservation is closed.');
        }

        $amount = round(min(max($amount, 0), (float) $reservation->room_total), 2);
        $reservation->forceFill(['discount_amount' => $amount, 'discount_reason' => $amount > 0 ? $reason : null])->save();
        $reservation->recalculateTotals();
        $reservation->log('discount', $amount > 0
            ? 'Discount of ' . config('hotel.currency_symbol') . number_format($amount, 2) . " applied. Reason: {$reason}"
            : 'Discount removed.');

        // Already billing (in house / checked out)? Keep the bill's discount line in step.
        $this->folios->postRoomNights($reservation->fresh());
    }

    /**
     * Rooms assigned to today's arrivals are shown as Reserved on the room board.
     * Run daily by the rooms:prepare-arrivals command and after each change.
     */
    public function markArrivalRoomsReserved(Reservation $reservation): int
    {
        if (! in_array($reservation->status, [ReservationStatus::Pending, ReservationStatus::Confirmed], true)
            || ! $reservation->check_in_date->isToday()) {
            return 0;
        }

        $marked = 0;
        foreach ($reservation->rooms as $line) {
            if ($line->room && $line->room->status === RoomStatus::Available) {
                $marked += (int) $line->room->changeStatus(RoomStatus::Reserved, "Arrival today — {$reservation->reference}");
            }
        }

        return $marked;
    }

    /** Mark rooms for all of today's arrivals as Reserved. Returns how many rooms changed. */
    public function prepareTodaysArrivals(): int
    {
        return Reservation::arrivingOn(today())->with('rooms.room', 'guest')->get()
            ->sum(fn (Reservation $r) => $this->markArrivalRoomsReserved($r));
    }

    // ------------------------------------------------------------------

    private function assertRoomsFree(array $lines, $in, $out): void
    {
        $ids = collect($lines)->pluck('room_id')->filter();
        if ($ids->duplicates()->isNotEmpty()) {
            throw new BookingException('The same room was picked twice.');
        }

        $booked = $this->availability->bookedRoomIds($in, $out);
        foreach (Room::whereIn('id', $ids)->get() as $room) {
            if (in_array($room->id, $booked, true)
                || ! $room->is_active
                || in_array($room->status, AvailabilityService::BLOCKING_ROOM_STATUSES, true)
                || (Carbon::parse($in)->lte(today()) && $room->status === RoomStatus::Occupied)) {
                throw new BookingException("Room {$room->number} is not available for these dates.");
            }
        }
    }

    private function releaseAllReservedRooms(Reservation $reservation): void
    {
        foreach ($reservation->rooms()->with('room')->get() as $line) {
            if ($line->room) {
                $this->releaseReservedRoom($line->room, $reservation);
            }
        }
    }

    /** A room this booking had marked Reserved goes back to Available (unless another arrival today holds it). */
    private function releaseReservedRoom(Room $room, Reservation $reservation): void
    {
        $room->refresh();
        if ($room->status !== RoomStatus::Reserved) {
            return;
        }

        $heldByAnother = ReservationRoom::where('room_id', $room->id)
            ->whereHas('reservation', fn ($q) => $q->arrivingOn(today())->where('id', '!=', $reservation->id))
            ->exists();

        if (! $heldByAnother) {
            $room->changeStatus(RoomStatus::Available, "Released — {$reservation->reference}");
        }
    }
}
