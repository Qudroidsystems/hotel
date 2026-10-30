<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Network\NetworkLink;
use App\Models\Network\NetworkPlan;
use App\Models\Network\NetworkSample;
use App\Models\Reservation;
use App\Services\Network\Gateways\DemoGateway;
use App\Services\Network\NetworkService;
use Illuminate\Database\Seeder;

/**
 * Internet & Wi-Fi demo: three Starlink dishes and a 4G backup with a day of
 * readings, three guest Wi-Fi plans, and codes for the guests staying now:
 *   php artisan db:seed --class=NetworkDemoSeeder
 */
class NetworkDemoSeeder extends Seeder
{
    public function run(NetworkService $network): void
    {
        if (NetworkLink::exists()) {
            $this->command?->warn('Internet lines already exist — demo not added.');

            return;
        }

        $links = collect([
            ['name' => 'Starlink 1 – Main roof', 'provider' => 'starlink', 'role' => 'shared', 'identifier' => 'UT01000000-00000000-0001A2B3', 'address' => '192.168.100.1', 'router_port' => 'ether1', 'weight' => 3, 'location' => 'Main building roof', 'monthly_cost' => 95000],
            ['name' => 'Starlink 2 – Pool deck', 'provider' => 'starlink', 'role' => 'guests', 'identifier' => 'UT01000000-00000000-0004C5D6', 'address' => '192.168.100.1', 'router_port' => 'ether2', 'weight' => 3, 'location' => 'Pool bar roof', 'monthly_cost' => 95000],
            ['name' => 'Starlink 3 – Annex', 'provider' => 'starlink', 'role' => 'staff', 'identifier' => 'UT01000000-00000000-0007E8F9', 'address' => '192.168.100.1', 'router_port' => 'ether3', 'weight' => 2, 'location' => 'Annex roof', 'monthly_cost' => 95000, 'data_cap_gb' => 1000, 'billing_day' => 5],
            ['name' => '4G backup (MTN)', 'provider' => 'lte', 'role' => 'backup', 'identifier' => '0803 000 0000', 'router_port' => 'lte1', 'weight' => 1, 'location' => 'Server room', 'monthly_cost' => 25000, 'data_cap_gb' => 100],
        ])->map(fn ($l, $i) => NetworkLink::create($l + ['sort_order' => $i, 'is_active' => true]));

        // A day of readings every 10 minutes, then this minute's reading.
        $demo = new DemoGateway;
        foreach ($links as $link) {
            $rows = [];
            for ($t = now()->subDay()->startOfMinute(); $t->lt(now()->subMinutes(5)); $t->addMinutes(10)) {
                $r = $demo->reading($link, $t);
                $rows[] = ['hotel_id' => $link->hotel_id, 'link_id' => $link->id, 'sampled_at' => $t->toDateTimeString(), 'status' => $r['status'], 'signal' => $r['signal'],
                    'down_mbps' => $r['down_mbps'], 'up_mbps' => $r['up_mbps'], 'latency_ms' => $r['latency_ms'], 'packet_loss' => $r['packet_loss'],
                    'obstruction_pct' => $r['obstruction_pct'], 'used_mb' => $r['used_mb'] * 10];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                NetworkSample::insert($chunk);
            }
            $link->forceFill(['used_gb_month' => round(collect($rows)->sum('used_mb') / 1024 * max(1, $link->periodStart()->diffInDays(today())), 2)])->save();
        }
        mt_srand();
        $network->useGateway($demo);
        $network->poll();

        $standard = NetworkPlan::create(['name' => 'Standard', 'description' => 'Browsing, e-mail, social media and video calls', 'down_mbps' => 10, 'up_mbps' => 3, 'devices' => 3, 'price' => 0, 'is_default' => true, 'sort_order' => 1]);
        NetworkPlan::create(['name' => 'Premium', 'description' => 'Streaming and large uploads for business guests', 'down_mbps' => 30, 'up_mbps' => 8, 'devices' => 5, 'price' => 5000, 'sort_order' => 2]);
        $visitor = NetworkPlan::create(['name' => 'Visitor', 'description' => 'Restaurant, bar and day visitors', 'down_mbps' => 5, 'up_mbps' => 1, 'devices' => 1, 'data_cap_mb' => 1024, 'price' => 0, 'sort_order' => 3]);

        foreach (Reservation::with(['guest', 'rooms.room'])->where('status', ReservationStatus::CheckedIn)->get() as $r) {
            $network->issueForReservation($r);
        }
        $network->issue(['kind' => 'event', 'plan_id' => $standard->id, 'holder' => 'Conference: Lagos Tech Week', 'devices' => 40, 'expires_at' => today()->setTime(18, 0)->max(now()->addHours(3))]);
        $network->issue(['kind' => 'visitor', 'plan_id' => $visitor->id, 'holder' => 'Restaurant table 7', 'expires_at' => now()->addHours(4)]);
        $network->poll();
    }
}
