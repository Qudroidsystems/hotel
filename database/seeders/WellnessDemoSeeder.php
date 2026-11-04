<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Wellness\WellnessBooking;
use App\Models\Wellness\WellnessTherapist;
use App\Models\Wellness\WellnessVenue;
use App\Services\Wellness\WellnessBookings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/** Demo spa (treatments, three therapists), gym and pool, and a few of today's bookings. */
class WellnessDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (WellnessVenue::exists()) {
            return;
        }
        $spa = WellnessVenue::create(['name' => 'Serenity Spa', 'kind' => 'spa', 'description' => 'Massages, facials and nail care in a quiet garden room.', 'opens' => '09:00', 'closes' => '21:00', 'capacity' => 3, 'sort_order' => 1]);
        foreach ([['Swedish massage', 60, 25000, 30000], ['Deep tissue massage', 60, 35000, 40000], ['Hot stone massage', 90, 45000, 50000], ['Signature facial', 45, 20000, 25000], ['Manicure & pedicure', 60, 15000, 18000]] as $i => [$n, $m, $p, $vp]) {
            $spa->services()->create(['name' => $n, 'minutes' => $m, 'price' => $p, 'visitor_price' => $vp, 'sort_order' => $i + 1]);
        }
        foreach (['Ifeoma', 'Grace', 'Tolu'] as $n) {
            WellnessTherapist::create(['venue_id' => $spa->id, 'name' => $n]);
        }
        $gym = WellnessVenue::create(['name' => 'Fitness Centre', 'kind' => 'gym', 'description' => 'Treadmills, bikes, free weights. Towels and water provided.', 'opens' => '06:00', 'closes' => '22:00', 'capacity' => 15, 'session_minutes' => 60, 'guest_price' => 0, 'visitor_price' => 5000, 'sort_order' => 2]);
        WellnessVenue::create(['name' => 'Pool', 'kind' => 'pool', 'description' => 'Outdoor pool with loungers and towels.', 'opens' => '07:00', 'closes' => '19:00', 'capacity' => 25, 'session_minutes' => 120, 'guest_price' => 0, 'visitor_price' => 7000, 'sort_order' => 3]);

        $svc = app(WellnessBookings::class);
        $staff = User::permission('Manage wellness bookings')->first() ?? User::role('Super Admin')->first();
        $stays = Reservation::where('status', ReservationStatus::CheckedIn)->with('guest')->orderBy('id')->limit(2)->get();
        if (! $staff) {
            return;
        }
        $day = now()->hour < 16 ? today() : today()->addDay();   // late in the evening, book tomorrow
        $slot = fn (WellnessVenue $v, $s, int $n) => collect($svc->slots($v, $s, $day, 1, true))->get($n)['time'] ?? null;
        $massage = $spa->services()->first();
        foreach ($stays as $i => $r) {
            if ($t = $slot($spa, $massage, 4 + $i * 3)) {
                $svc->deskBook($staff, $spa, $massage, Carbon::parse($day->toDateString() . ' ' . $t), 1, $r, [], $i ? null : 'Medium pressure please');
            }
        }
        if ($t = $slot($gym, null, 2)) {
            $svc->deskBook($staff, $gym, null, Carbon::parse($day->toDateString() . ' ' . $t), 2, null, ['name' => 'Kunle Adebayo', 'phone' => '08031234567'], null);
        }
        WellnessBooking::query()->update(['via' => 'app']);
    }
}
