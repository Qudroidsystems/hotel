<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Booking\GuestRequest;
use App\Models\Messaging\GuestMessage;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\GuestRequestService;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The guest side: loyalty points and tiers from the demo stays, requests from guests in house, and a message
 * history (sent, delivered, a reply waiting to be read) so the Guest Messages page has something to show.
 * Nothing is actually sent.
 *   php artisan db:seed --class=GuestServicesDemoSeeder   (after ReservationDemoSeeder)
 */
class GuestServicesDemoSeeder extends Seeder
{
    public function run(GuestRequestService $requests, LoyaltyService $loyalty): void
    {
        $admin = User::role('Super Admin')->first() ?? User::first();
        auth()->login($admin);

        // Points for settled stays, member numbers and tiers; and a goodwill top-up for one regular.
        Artisan::call('loyalty:sync');
        // The demo has no finished stays yet, so give a few regulars points to show the programme.
        $regulars = \App\Models\Guest::withCount('reservations')->orderByDesc('reservations_count')->take(3)->get();
        foreach ($regulars as $i => $guest) {
            if (! $guest->loyaltyEntries()->exists()) {
                $loyalty->adjust($guest, [4200, 1800, 650][$i], 'Points from earlier stays (demo)', $admin);
            }
        }

        // Requests from guests in house.
        $inHouse = Reservation::where('status', ReservationStatus::CheckedIn->value)->get();
        if (! GuestRequest::exists()) {
            foreach ($inHouse->take(3)->values() as $i => $r) {
                $type = ['towels', 'transport', 'repair'][$i];
                $details = ['Two extra bath towels, please.', 'Airport drop-off tomorrow at 6 am.', 'The bedside lamp does not switch on.'][$i];
                try {
                    $req = $requests->create($r, $type, $details);
                    if ($i === 1) {
                        $req->forceFill(['status' => 'done', 'handled_by' => $admin->id, 'handled_at' => now(), 'staff_note' => 'Taxi booked for 5:30 am.'])->save();
                    }
                } catch (\Throwable $e) {
                    $this->command?->warn('Request not added: '.$e->getMessage());
                }
            }
        }

        // A message history (logged only — nothing is sent).
        if (! GuestMessage::exists()) {
            foreach (Reservation::with('guest')->whereNotNull('guest_id')->latest()->take(4)->get() as $i => $r) {
                $g = $r->guest;
                $to = $g?->phone ? '+234'.ltrim(preg_replace('/\D/', '', $g->phone), '0') : null;
                GuestMessage::create([
                    'guest_id' => $g?->id, 'reservation_id' => $r->id, 'direction' => 'out', 'channel' => 'sms', 'to' => $to, 'from' => 'BLuxury',
                    'body' => "Hi {$g?->first_name}, your stay at ".config('hotel.name')." is confirmed: ref {$r->reference}.",
                    'template' => 'booking_confirmed', 'status' => $i === 3 ? 'failed' : 'delivered', 'error' => $i === 3 ? 'Not delivered (DND active on phone number)' : null,
                    'provider_id' => 'demo-'.$r->id,
                ]);
            }
            $first = $inHouse->first()?->load('guest');
            if ($first?->guest) {
                GuestMessage::create([
                    'guest_id' => $first->guest_id, 'reservation_id' => $first->id, 'direction' => 'in', 'channel' => 'sms', 'from' => $first->guest->phone, 'to' => 'BLuxury',
                    'body' => 'Thank you! Can I get a late check-out tomorrow, around 2pm?', 'template' => 'reply', 'status' => 'received', 'provider_id' => 'demo-in-'.$first->id,
                ]);
            }
        }

        $this->command?->info('Guest services demo added: loyalty, requests and messages.');
    }
}
