<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Laundry\LaundryOrder;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Laundry\LaundryService;
use Illuminate\Database\Seeder;

/**
 * Demo guest laundry: the typical price list, and a few in-house guests' bags — one to collect with the
 * guest's list, one with no list, one at the laundry (on the bill) and one ready to take up.
 */
class LaundryDemoSeeder extends Seeder
{
    public function run(): void
    {
        $svc = app(LaundryService::class);
        $svc->seedDefaults();
        if (LaundryOrder::exists()) {
            return;
        }
        $staff = User::permission('Handle laundry')->first() ?? User::role('Super Admin')->first();
        $stays = Reservation::where('status', ReservationStatus::CheckedIn)->orderBy('id')->limit(4)->get();
        if (! $staff || $stays->isEmpty()) {
            return;
        }
        $items = \App\Models\Laundry\LaundryItem::pluck('id', 'name');
        $plans = [
            [[['Shirt', 'wash', 3], ['Trousers', 'wash', 2]], false, 'No starch on the shirts, please.', null],
            [[], true, null, null],
            [[['Suit (2 pieces)', 'dry', 1], ['Shirt', 'press', 2]], false, null, 'collect'],
            [[['Senator / kaftan', 'wash', 2], ['Underwear', 'wash', 4]], true, null, 'ready'],
        ];
        auth()->setUser($staff);
        foreach ($stays as $i => $r) {
            [$lines, $express, $note, $then] = $plans[$i];
            $order = $svc->request($r, array_map(fn ($l) => ['item_id' => $items[$l[0]] ?? 0, 'service' => $l[1], 'quantity' => $l[2]], $lines), $express, $note, 'app');
            $order->forceFill(['created_at' => now()->subMinutes(20 + $i * 50)])->saveQuietly();
            if ($then) {
                $order = $svc->collect($order, $staff);
                if ($then === 'ready') {
                    $svc->ready($order, $staff);
                }
            }
        }
        auth()->forgetUser();
    }
}
