<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\Booking\OnlineBookingService;
use App\Services\Pos\GuestOrderService;
use App\Support\Audit;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Http\Request;

/**
 * The QR card in each room. Scanning it opens this page; the guest confirms
 * their surname (or booking reference) once, so a photo of the card is no use
 * to anyone else, then goes straight to ordering or their booking page.
 */
class RoomCardController extends Controller
{
    public function show(string $code, GuestOrderService $orders)
    {
        [$room, $stay] = $this->find($code);
        if ($stay && session("room_qr.{$room->id}") === $stay->guest_token) {
            return redirect()->route($orders->canOrder($stay) ? 'stay.order' : 'stay.show', $stay->guest_token);
        }

        return view('booking.room-card', ['room' => $room, 'stay' => $stay, 'ordering' => $orders->outlets()->isNotEmpty()]);
    }

    public function verify(Request $request, string $code, OnlineBookingService $booking, GuestOrderService $orders)
    {
        [$room, $stay] = $this->find($code);
        $data = $request->validate(['name' => 'required|string|max:100']);
        $given = mb_strtolower(trim($data['name']));
        $ok = $stay && in_array($given, array_filter([mb_strtolower(trim((string) $stay->guest?->last_name)), mb_strtolower($stay->reference)]), true);
        if (! $ok) {
            Audit::record('guest.room_card_failed', "Room {$room->number} QR card: name did not match the guest staying", new: ['ip' => $request->ip()]);

            return back()->withInput()->with('error', 'That doesn’t match the guest staying in this room. Use the surname on the booking, or call reception.');
        }
        $token = $booking->ensureToken($stay);
        session(["room_qr.{$room->id}" => $token, 'room_qr_last' => $token]);

        return redirect()->route($orders->canOrder($stay) ? 'stay.order' : 'stay.show', $token);
    }

    /** Printable cards for every room (staff). */
    public function cards(Request $request)
    {
        abort_unless($request->user()->canAny(['Update rooms', 'Manage POS menu']), 403);
        $rooms = Room::active()->with('floor:id,name,level')->get()->sortBy(fn ($r) => sprintf('%04d|%08s', $r->floor?->level ?? 999, $r->number), SORT_NATURAL)->values();
        foreach ($rooms->whereNull('guest_code') as $r) {
            $r->forceFill(['guest_code' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12))])->saveQuietly();
        }

        return view('booking.room-cards', ['rooms' => $rooms, 'ordering' => app(GuestOrderService::class)->outlets()->isNotEmpty()]);
    }

    /** The room (any hotel: the card works before anyone picks a hotel) and who is staying in it now. */
    private function find(string $code): array
    {
        abort_unless(preg_match('/^[a-z0-9]{8,16}$/', $code), 404);
        $room = Room::withoutGlobalScope('hotel')->where('guest_code', $code)->where('is_active', true)->firstOrFail();
        CurrentHotel::get()->adopt($room);
        $stay = Reservation::inHouse()->whereHas('rooms', fn ($q) => $q->where('room_id', $room->id))->with('guest')->latest('check_in_date')->first();

        return [$room, $stay];
    }
}
