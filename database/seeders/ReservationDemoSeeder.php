<?php

namespace Database\Seeders;

use App\Enums\ChargeType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\HotelBankAccount;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Reservations\AvailabilityService;
use App\Services\Reservations\ReservationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sample guests and bookings around today so the front desk has something to show:
 *   php artisan db:seed --class=RoomDemoSeeder
 *   php artisan db:seed --class=ReservationDemoSeeder
 * Run once on a fresh demo database.
 */
class ReservationDemoSeeder extends Seeder
{
    public function run(ReservationService $service, AvailabilityService $availability, FolioService $folios): void
    {
        if (Reservation::exists()) {
            $this->command?->warn('Reservations already exist — demo bookings not added.');
            return;
        }

        auth()->login(User::role('Super Admin')->first() ?? User::first());

        // Sample hotel bank accounts (replace with your own under Bank Accounts).
        if (! HotelBankAccount::exists()) {
            HotelBankAccount::create(['bank_name' => 'GTBank', 'account_name' => config('hotel.name'), 'account_number' => '0123456789', 'purpose' => 'Room payments', 'sort_order' => 1]);
            HotelBankAccount::create(['bank_name' => 'Zenith Bank', 'account_name' => config('hotel.name') . ' — F&B', 'account_number' => '1012345678', 'purpose' => 'Bar & restaurant', 'show_on_invoice' => false, 'sort_order' => 2]);
        }
        $account = HotelBankAccount::orderBy('sort_order')->first();

        $names = [
            ['Chinedu', 'Okafor', 'Okafor Logistics'], ['Amina', 'Bello', null], ['Tunde', 'Adeyemi', 'Zenith Bank'],
            ['Ngozi', 'Eze', null], ['Ibrahim', 'Musa', 'NNPC'], ['Funke', 'Akindele', null], ['Emeka', 'Nwosu', null],
            ['Halima', 'Sani', 'MTN Nigeria'], ['David', 'Johnson', null], ['Grace', 'Udo', null], ['Segun', 'Ogunleye', 'Dangote Group'],
            ['Aisha', 'Lawal', null], ['Kelechi', 'Obi', null], ['Bisi', 'Ade', null],
        ];
        $guests = collect($names)->map(fn ($n, $i) => Guest::create([
            'title' => $i % 2 ? 'Mrs' : 'Mr', 'first_name' => $n[0], 'last_name' => $n[1], 'company' => $n[2],
            'phone' => '+23480' . str_pad((string) (31000000 + $i * 7919), 8, '0', STR_PAD_LEFT),
            'email' => strtolower($n[0] . '.' . $n[1]) . '@example.com', 'nationality' => 'Nigerian', 'is_vip' => $i === 4,
        ]));

        $types = RoomType::orderBy('base_rate')->get()->values();
        $book = function (Guest $guest, int $typeIndex, $in, $out, bool $assign, array $extra = []) use ($service, $availability, $types) {
            $type = $types[$typeIndex % $types->count()];
            $room = $assign ? $availability->freeRooms($in, $out, $type->id)->first(fn ($r) => $r->status->value === 'available') : null;
            if ($assign && ! $room) {
                return null;
            }

            return $service->create($guest, $extra + ['check_in_date' => $in, 'check_out_date' => $out, 'source' => $extra['source'] ?? 'phone'],
                [['room_type_id' => $type->id, 'room_id' => $room?->id, 'adults' => 2]]);
        };

        // Move a stay's dates after the fact (demo only) and re-price its nights to match.
        $backdate = function (Reservation $r, $in, $out, $checkedInAt) {
            $r->forceFill(['check_in_date' => $in, 'check_out_date' => $out, 'checked_in_at' => $checkedInAt])->save();
            foreach ($r->rooms()->with(['room.roomType', 'roomType'])->get() as $line) {
                $rates = [];
                foreach (Reservation::nightsBetween($in, $out) as $night) {
                    $rates[$night] = $line->room ? $line->room->rateFor(Carbon::parse($night)) : $line->roomType->rateFor(Carbon::parse($night));
                }
                $line->update(['nightly_rates' => $rates, 'amount' => array_sum($rates)]);
            }
            $r->recalculateTotals();
        };

        DB::transaction(function () use ($guests, $book, $service, $backdate, $folios, $account) {
            // In house: booked "from today", checked in, then moved back so the stay started earlier.
            foreach ([[0, 0, 3], [1, 1, 2], [2, 1, 4], [3, 2, 2], [4, 3, 5]] as $i => [$g, $t, $nights]) {
                $r = $book($guests[$g], $t, today(), today()->addDays($nights), true, ['source' => 'walk_in']);
                if (! $r) continue;
                $service->checkIn($r->fresh());
                $back = $i + 1;
                $backdate($r, today()->subDays($back), $r->check_out_date, now()->subDays($back)->setTime(14, 10));
            }
            // Departing today.
            foreach ([[5, 0], [6, 1]] as [$g, $t]) {
                $r = $book($guests[$g], $t, today(), today()->addDay(), true);
                if (! $r) continue;
                $service->checkIn($r->fresh());
                $backdate($r, today()->subDays(2), today(), now()->subDays(2)->setTime(15, 0));
            }
            // Bills: post the nights already used, then some extras and payments.
            $folios->nightAudit();
            $methods = [PaymentMethod::Transfer, PaymentMethod::Cash, PaymentMethod::Card];
            foreach (Reservation::inHouse()->orderBy('id')->get()->values() as $i => $r) {
                $folio = $folios->forReservation($r);
                if ($i % 2 === 0) {
                    $folios->postCharge($folio, ChargeType::Food, 'Room service — jollof rice & chicken', 2, 4500);
                }
                if ($i % 3 === 0) {
                    $folios->postCharge($folio, ChargeType::Laundry, 'Laundry — 6 items', 6, 800);
                }
                if ($i === 1) {
                    $folios->postCharge($folio, ChargeType::Minibar, 'Minibar — water & juice', 3, 1200);
                }
                // Most guests paid something up front; one has paid in full; one has paid nothing.
                $balance = $folio->balance();
                $paid = match ($i % 4) { 0 => $balance, 1 => round($balance / 2, -3), 2 => 0, default => 20000 };
                if ($paid > 0) {
                    $folios->receivePayment($folio, PaymentKind::Payment, $methods[$i % 3], $paid, $methods[$i % 3]->needsReference() ? 'TRF' . (4810 + $i) : null, null,
                        ['bank_account_id' => $methods[$i % 3] === PaymentMethod::Transfer ? $account->id : null]);
                }
            }
            // Arriving today (two with rooms, one still to be assigned).
            $book($guests[7], 1, today(), today()->addDays(2), true, ['expected_arrival_time' => '14:00', 'special_requests' => 'Late check-out if possible']);
            $book($guests[8], 0, today(), today()->addDays(1), true, ['expected_arrival_time' => '18:30', 'source' => 'website']);
            $book($guests[9], 2, today(), today()->addDays(3), false, ['source' => 'corporate']);
            // Upcoming.
            foreach ([[10, 3, 2, 3], [11, 0, 3, 2], [12, 1, 5, 4], [13, 2, 8, 2], [0, 1, 12, 3]] as [$g, $t, $from, $nights]) {
                $book($guests[$g], $t, today()->addDays($from), today()->addDays($from + $nights), $from < 6, ['source' => ['phone', 'email', 'website', 'agent', 'referral'][$from % 5]]);
            }
            // One pending, one cancelled.
            $book($guests[3], 0, today()->addDays(4), today()->addDays(6), false, ['status' => ReservationStatus::Pending->value, 'source' => 'email']);
            // A deposit on an upcoming booking.
            if ($u = Reservation::where('status', ReservationStatus::Confirmed->value)->where('check_in_date', '>', today()->toDateString())->orderBy('check_in_date')->first()) {
                $folios->receivePayment($folios->forReservation($u), PaymentKind::Deposit, PaymentMethod::Transfer, 15000, 'DEP-2291', 'Deposit to hold the room', ['bank_account_id' => $account->id]);
            }
            if ($c = $book($guests[1], 1, today()->addDays(9), today()->addDays(11), false)) {
                $service->cancel($c, 'Guest changed travel plans');
            }
        });

        $this->command?->info(Reservation::count() . ' demo reservations created.');
    }
}
