<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Booking\GuestFeedback;
use App\Models\MaintenancePlan;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\FeedbackService;
use Illuminate\Database\Seeder;

/**
 * Guest feedback from guests who already left, and a few servicing plans:
 *   php artisan db:seed --class=FeedbackPlansDemoSeeder
 */
class FeedbackPlansDemoSeeder extends Seeder
{
    public function run(FeedbackService $feedback): void
    {
        $admin = User::role('Super Admin')->first() ?? User::first();

        if (! GuestFeedback::exists()) {
            $answers = [
                [5, 10, 'Wonderful stay. Reception was so helpful with our airport pick-up.', ['room' => 5, 'cleanliness' => 5, 'staff' => 5, 'food' => 4, 'value' => 5], true],
                [4, 8, 'Comfortable room and quiet at night. Breakfast could have more choice.', ['room' => 4, 'cleanliness' => 5, 'staff' => 4, 'food' => 3, 'value' => 4], true],
                [2, 4, 'The AC in our room was not cooling and it took two calls before anyone came.', ['room' => 2, 'cleanliness' => 4, 'staff' => 3, 'value' => 2], false],
                [5, 9, null, ['room' => 5, 'staff' => 5], false],
                [3, 7, 'Fine for a business trip. Wi-Fi dropped a few times in the evening.', ['room' => 3, 'cleanliness' => 4, 'staff' => 4, 'food' => 3, 'value' => 3], false],
            ];
            $stays = Reservation::with('guest')->where('status', ReservationStatus::CheckedOut->value)->whereDoesntHave('feedback')->latest('check_out_date')->limit(count($answers))->get();
            foreach ($stays as $i => $r) {
                [$overall, $rec, $comment, $ratings, $quote] = $answers[$i];
                if (! $r->checked_out_at) {
                    $r->forceFill(['checked_out_at' => $r->check_out_date->copy()->setTime(11, 30)])->saveQuietly();
                }
                if ($r->checked_out_at->lt(now()->subDays((int) config('hotel.feedback.window_days', 30)))) {
                    $r->forceFill(['checked_out_at' => now()->subDays($i + 1)])->saveQuietly();
                }
                $f = $feedback->submit($r, ['overall' => $overall, 'recommend' => $rec, 'comment' => $comment, 'ratings' => $ratings, 'may_quote' => $quote, 'contact_ok' => $overall <= 2], $i % 2 ? 'app' : 'web');
                $f->forceFill(['created_at' => $r->checked_out_at->copy()->addHours(5)])->saveQuietly();
            }
        }

        if (! MaintenancePlan::exists()) {
            $plans = [
                ['title' => 'Generator oil and filter change', 'category' => 'electrical', 'location' => 'Generator house', 'every' => 1, 'unit' => 'month', 'next_due_on' => today()->addDays(2), 'priority' => 'high',
                    'description' => "Change oil and filter\nCheck battery, coolant and belts\nRun on load for 15 minutes and note the hours"],
                ['title' => 'Service AC unit (clean filters, check gas)', 'category' => 'air_conditioning', 'scope' => 'rooms', 'every' => 3, 'unit' => 'month', 'next_due_on' => today()->addDays(20), 'lead_days' => 5],
                ['title' => 'Check fire extinguishers', 'category' => 'building', 'location' => 'All floors', 'every' => 6, 'unit' => 'month', 'next_due_on' => today()->addMonths(2)],
                ['title' => 'Pool pump and filter backwash', 'category' => 'plumbing', 'location' => 'Pool', 'every' => 1, 'unit' => 'week', 'next_due_on' => today()->subDay(), 'lead_days' => 1],
                ['title' => 'Water tank cleaning', 'category' => 'plumbing', 'location' => 'Roof tanks', 'every' => 6, 'unit' => 'month', 'next_due_on' => today()->addMonths(4)],
            ];
            foreach ($plans as $p) {
                MaintenancePlan::create($p + ['scope' => 'one', 'lead_days' => 3, 'priority' => 'normal', 'active' => true, 'created_by' => $admin?->id]);
            }
        }
    }
}
