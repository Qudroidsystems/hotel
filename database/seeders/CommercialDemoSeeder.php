<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Models\Company;
use App\Models\Events\Event;
use App\Models\Events\EventPackage;
use App\Models\Events\Hall;
use App\Models\Rates\RateDay;
use App\Models\Rates\RatePlan;
use App\Models\Rates\RateSeason;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Companies\CompanyLedger;
use App\Services\Events\EventService;
use Illuminate\Database\Seeder;

/**
 * Rates, company accounts and events for the demo hotel: rate plans (bed & breakfast, corporate, a promo
 * code, weekend getaway), Christmas and rainy-season prices, three companies with bills and payments, and
 * halls with a month of events.
 *   php artisan db:seed --class=CommercialDemoSeeder   (after RoomDemoSeeder and ReservationDemoSeeder)
 */
class CommercialDemoSeeder extends Seeder
{
    public function run(CompanyLedger $ledger, EventService $events, FolioService $folios): void
    {
        $admin = User::role('Super Admin')->first() ?? User::first();
        auth()->login($admin);
        $year = today()->month === 12 && today()->day > 20 ? today()->year + 1 : today()->year;

        // ── Rates & seasons
        if (! RatePlan::exists()) {
            RatePlan::create(['name' => 'Bed & breakfast', 'code' => 'BB', 'inclusions' => 'Breakfast for two at the restaurant', 'nightly_extra' => 8000, 'online' => true, 'sort_order' => 1]);
            RatePlan::create(['name' => 'Weekend getaway', 'code' => 'WKND', 'inclusions' => 'Late check-out until 2 pm', 'discount_percent' => 10, 'arrival_days' => [5, 6], 'min_nights' => 2, 'online' => true, 'sort_order' => 2]);
            RatePlan::create(['name' => 'Early bird', 'code' => 'EARLY', 'discount_percent' => 15, 'min_advance_days' => 21, 'online' => true, 'non_refundable' => true, 'sort_order' => 3]);
            RatePlan::create(['name' => 'Launch offer', 'code' => 'LAUNCH', 'discount_percent' => 20, 'promo_code' => 'BLUX20', 'online' => true, 'front_desk' => false, 'valid_to' => today()->addMonths(3), 'sort_order' => 4]);
            RatePlan::create(['name' => 'Long stay (7+ nights)', 'code' => 'LONG', 'discount_percent' => 18, 'min_nights' => 7, 'sort_order' => 5]);
        }
        if (! RateSeason::exists()) {
            RateSeason::create(['name' => 'Christmas & New Year', 'starts_on' => "{$year}-12-20", 'ends_on' => ($year + 1) . '-01-03', 'change_percent' => 25, 'min_nights' => 2, 'priority' => 10, 'color' => '#b91c1c']);
            RateSeason::create(['name' => 'Rainy season', 'starts_on' => "{$year}-07-01", 'ends_on' => "{$year}-08-31", 'change_percent' => -10, 'color' => '#0284c7']);
            $suite = RoomType::where('code', 'STE')->first();
            if ($suite) {
                RateDay::create(['room_type_id' => $suite->id, 'date' => "{$year}-12-31", 'price' => 250000, 'min_nights' => 2, 'note' => 'New Year’s Eve gala']);
            }
        }

        // ── Company accounts
        if (! Company::exists()) {
            $shell = $ledger->create(['name' => 'Shell Nigeria', 'contact_name' => 'Mrs Funke Ade', 'email' => 'travel@shell.example', 'phone' => '+234 803 000 1111', 'address' => "Freeman House\n21–22 Marina, Lagos", 'tax_number' => '01234567-0001', 'credit_limit' => 5000000, 'payment_terms_days' => 30]);
            $mtn = $ledger->create(['name' => 'MTN Nigeria', 'contact_name' => 'Mr Tunde Bello', 'email' => 'events@mtn.example', 'credit_limit' => 3000000, 'payment_terms_days' => 45]);
            $ledger->create(['name' => 'Federal Ministry of Health', 'contact_name' => 'Dr Amina Yusuf', 'payment_terms_days' => 60]);
            RatePlan::create(['name' => 'Shell corporate', 'code' => 'SHELL', 'discount_percent' => 20, 'inclusions' => 'Breakfast and airport pickup', 'nightly_extra' => 5000, 'company_id' => $shell->id, 'sort_order' => 6]);

            // An older invoice paid, one overdue, and a guest's bill moved over.
            $old = $ledger->invoice($shell, 850000, 'Stay — Mr K. Okafor, 3 nights (demo)');
            $old->forceFill(['entry_date' => today()->subDays(75), 'due_date' => today()->subDays(45)])->save();
            $paid = $ledger->receivePayment($shell, 850000, PaymentMethod::Transfer, 'SHELL-PAY-2231');
            $paid->forceFill(['entry_date' => today()->subDays(40)])->save();
            $late = $ledger->invoice($mtn, 420000, 'Board meeting lunch (demo)');
            $late->forceFill(['entry_date' => today()->subDays(70), 'due_date' => today()->subDays(25)])->save();

            $guest = Reservation::where('status', ReservationStatus::CheckedIn->value)->with('guest')->first();
            if ($guest) {
                $guest->forceFill(['company_id' => $shell->id])->save();
                $guest->guest->forceFill(['company_id' => $shell->id])->save();
                $folio = $folios->forReservation($guest);
                $folios->postRoomNights($guest);
                if ($folio->fresh()->balance() > 0) {
                    $ledger->transferFolio($folio->fresh(), $shell, $folio->fresh()->balance(), 'LPO 4471');
                }
            }
        }

        // ── Halls, packages and events
        if (! Hall::exists()) {
            $ball = Hall::create(['name' => 'Grand Ballroom', 'capacity_theatre' => 400, 'capacity_banquet' => 250, 'capacity_classroom' => 180, 'hourly_rate' => 80000, 'half_day_rate' => 450000, 'full_day_rate' => 750000, 'color' => '#b7832f', 'description' => 'Ground floor, own entrance, stage and PA system', 'sort_order' => 1]);
            $conf = Hall::create(['name' => 'Lagoon Conference Room', 'capacity_theatre' => 80, 'capacity_banquet' => 50, 'capacity_classroom' => 40, 'capacity_boardroom' => 24, 'hourly_rate' => 30000, 'half_day_rate' => 150000, 'full_day_rate' => 250000, 'color' => '#0f766e', 'sort_order' => 2]);
            $board = Hall::create(['name' => 'Executive Boardroom', 'capacity_boardroom' => 14, 'hourly_rate' => 20000, 'half_day_rate' => 70000, 'full_day_rate' => 120000, 'color' => '#14213d', 'sort_order' => 3]);
            $day = EventPackage::create(['name' => 'Full-day delegate', 'price_per_person' => 15000, 'description' => 'Two tea breaks, buffet lunch, bottled water, notepads']);
            $half = EventPackage::create(['name' => 'Half-day delegate', 'price_per_person' => 9000, 'description' => 'One tea break, lunch, bottled water']);
            $banquet = EventPackage::create(['name' => 'Wedding banquet', 'price_per_person' => 25000, 'description' => 'Three-course Nigerian & continental buffet, soft drinks, cake table']);

            $shell = Company::where('name', 'Shell Nigeria')->first();
            $plan = [
                [$conf, 'Shell quarterly review', 'conference', 'confirmed', 6, '09:00', '17:00', 'classroom', 35, $day, $shell?->id, 'Mrs Funke Ade', 'Projector, 2 microphones, flip charts'],
                [$ball, 'Okafor–Adeyemi wedding reception', 'wedding', 'confirmed', 13, '14:00', '23:00', 'banquet', 220, $banquet, null, 'Mrs Okafor', "High table for 10\nDJ from 6 pm\nCake cutting at 8 pm"],
                [$board, 'Board meeting — Lekki Holdings', 'meeting', 'tentative', 9, '10:00', '13:00', 'boardroom', 12, $half, null, 'Mr Bello', 'Tea at 11:00'],
                [$conf, 'First-aid training', 'training', 'enquiry', 17, '09:00', '15:00', 'classroom', 25, $day, null, 'Red Cross Lagos', null],
                [$ball, 'MTN product launch', 'launch', 'tentative', 24, '18:00', '22:00', 'theatre', 300, null, Company::where('name', 'MTN Nigeria')->value('id'), 'Mr Tunde Bello', 'Stage, LED screen, cocktail reception after'],
            ];
            foreach ($plan as [$hall, $title, $type, $status, $in, $from, $to, $setup, $guests, $pkg, $company, $who, $req]) {
                $date = today()->addDays($in)->toDateString();
                $e = $events->save([
                    'title' => $title, 'event_type' => $type, 'status' => $status, 'hall_id' => $hall->id, 'starts_at' => "{$date} {$from}", 'ends_at' => "{$date} {$to}",
                    'setup' => $setup, 'guests_expected' => $guests, 'package_id' => $pkg?->id, 'company_id' => $company, 'organiser_name' => $who,
                    'organiser_phone' => '+234 80' . rand(10000000, 99999999), 'hall_rate_type' => (int) explode(':', $to)[0] - (int) explode(':', $from)[0] <= 4 ? 'half_day' : 'full_day',
                    'deposit_due' => $status === 'enquiry' ? 0 : 200000, 'requirements' => $req,
                ]);
                if ($status === 'confirmed') {
                    $events->receivePayment($e, 'payment', PaymentMethod::Transfer, 200000, 'DEP-' . $e->id);
                }
                if ($title === 'Okafor–Adeyemi wedding reception') {
                    $events->addItem($e, ['kind' => 'equipment', 'description' => 'Extra lighting & stage decor', 'quantity' => 1, 'unit_price' => 180000]);
                    $events->addItem($e, ['kind' => 'catering', 'description' => 'Chapman & mocktails', 'quantity' => 220, 'unit_price' => 1500]);
                }
            }
        }

        $this->command?->info('Rates, company accounts and events demo added. Promo code for the booking page: BLUX20.');
    }
}
