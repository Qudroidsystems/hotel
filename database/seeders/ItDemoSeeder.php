<?php

namespace Database\Seeders;

use App\Models\It\ItAsset;
use App\Models\It\ItMaintenanceWindow;
use App\Models\It\ItMonitor;
use App\Models\User;
use App\Services\It\MonitorService;
use App\Services\It\ServiceDesk;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * A starter IT control room: typical hotel network equipment, the monitors
 * every installation should have, a few service-desk tickets and a planned
 * maintenance window:
 *   php artisan db:seed --class=ItDemoSeeder
 * Change the IP addresses under IT Support → Equipment / Monitors to match
 * the hotel's real network.
 */
class ItDemoSeeder extends Seeder
{
    public function run(MonitorService $monitors, ServiceDesk $desk): void
    {
        if (ItMonitor::exists()) {
            $this->command?->warn('IT monitors already exist — demo not added.');
            return;
        }
        $admin = User::role('Super Admin')->first() ?? User::first();
        auth()->login($admin);

        $a = fn (array $d) => ItAsset::create($d + ['is_active' => true]);
        $router = $a(['name' => 'Main router', 'type' => 'router', 'ip_address' => '192.168.1.1', 'location' => 'Server room', 'make_model' => 'MikroTik RB4011', 'warranty_until' => today()->addMonths(14)]);
        $isp = $a(['name' => 'Fibre internet (primary)', 'type' => 'internet', 'location' => 'Server room', 'make_model' => 'ISP fibre 100 Mbps', 'notes' => 'Support line on the ISP contract.']);
        $core = $a(['name' => 'Core switch', 'type' => 'switch', 'ip_address' => '192.168.1.2', 'location' => 'Server room', 'make_model' => 'Cisco CBS350-24P', 'warranty_until' => today()->addDays(40)]);
        $ap1 = $a(['name' => 'AP Lobby', 'type' => 'access_point', 'ip_address' => '192.168.1.21', 'location' => 'Lobby / reception', 'make_model' => 'Ubiquiti U6 Lite']);
        $ap2 = $a(['name' => 'AP Floor 2', 'type' => 'access_point', 'ip_address' => '192.168.1.22', 'location' => '2nd floor corridor', 'make_model' => 'Ubiquiti U6 Lite']);
        $a(['name' => 'AP Pool bar', 'type' => 'access_point', 'ip_address' => '192.168.1.23', 'location' => 'Pool bar', 'make_model' => 'Ubiquiti U6 Mesh', 'warranty_until' => today()->subMonths(2)]);
        $a(['name' => 'Hotel server', 'type' => 'server', 'ip_address' => '192.168.1.10', 'location' => 'Server room', 'make_model' => 'Dell PowerEdge T150']);
        $a(['name' => 'CCTV recorder', 'type' => 'cctv', 'ip_address' => '192.168.1.30', 'location' => 'Security office', 'make_model' => 'Hikvision 16-channel NVR']);
        $a(['name' => 'Server room UPS', 'type' => 'ups', 'location' => 'Server room', 'make_model' => 'APC Smart-UPS 3000']);
        $printer = $a(['name' => 'Front desk printer', 'type' => 'printer', 'ip_address' => '192.168.1.50', 'location' => 'Reception', 'make_model' => 'HP LaserJet M404']);

        // A sample backup so the backup monitor has something to look at.
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        if (! File::files($dir)) {
            File::put($dir . '/demo-backup-' . now()->format('Y-m-d') . '.sql', str_repeat("-- demo backup\n", 200));
        }

        $m = fn (array $d) => ItMonitor::create($d + ['severity' => 'high', 'interval_minutes' => 5, 'timeout_seconds' => 5, 'alert_after' => 2, 'is_active' => true]);
        $m(['name' => 'Internet (Google DNS)', 'category' => 'network', 'type' => 'ping', 'target' => '8.8.8.8', 'asset_id' => $isp->id, 'severity' => 'critical', 'interval_minutes' => 1]);
        $m(['name' => 'Main router', 'category' => 'network', 'type' => 'ping', 'target' => $router->ip_address, 'asset_id' => $router->id, 'severity' => 'critical', 'interval_minutes' => 1]);
        $m(['name' => 'Core switch', 'category' => 'network', 'type' => 'ping', 'target' => $core->ip_address, 'asset_id' => $core->id]);
        $m(['name' => 'Wi-Fi: Lobby', 'category' => 'network', 'type' => 'ping', 'target' => $ap1->ip_address, 'asset_id' => $ap1->id, 'severity' => 'normal']);
        $m(['name' => 'Wi-Fi: Floor 2', 'category' => 'network', 'type' => 'ping', 'target' => $ap2->ip_address, 'asset_id' => $ap2->id, 'severity' => 'normal']);
        $m(['name' => 'Hotel system (this app)', 'category' => 'application', 'type' => 'http', 'target' => rtrim(config('app.url'), '/') . '/up', 'severity' => 'critical', 'interval_minutes' => 1]);
        $m(['name' => 'Background jobs', 'category' => 'application', 'type' => 'queue', 'severity' => 'normal', 'interval_minutes' => 10]);
        $m(['name' => 'Database', 'category' => 'infrastructure', 'type' => 'database', 'severity' => 'critical', 'interval_minutes' => 1]);
        $m(['name' => 'Server disk', 'category' => 'infrastructure', 'type' => 'disk', 'interval_minutes' => 15]);
        $m(['name' => 'Nightly backup', 'category' => 'infrastructure', 'type' => 'backup', 'target' => 'storage/app/backups', 'interval_minutes' => 30, 'alert_after' => 1]);

        $monitors->runDue(true);

        $staff = fn (string $role) => User::role($role)->first() ?? $admin;
        $desk->open(['title' => 'Bar till not printing receipts', 'description' => "The receipt printer at the main bar shows a red light. Sales still go through but guests can't get receipts.", 'category' => 'pos', 'priority' => 'high', 'department' => 'Bar & Restaurant', 'location' => 'Main bar'], $staff('Bar Manager'));
        $desk->open(['title' => 'Weak Wi-Fi in rooms 214–220', 'description' => 'Guests on the east wing of the 2nd floor complain the Wi-Fi keeps dropping.', 'category' => 'wifi', 'priority' => 'normal', 'department' => 'Front Desk', 'location' => '2nd floor east', 'asset_id' => $ap2->id], $staff('Front Desk'));
        $t = $desk->open(['title' => 'Front desk printer jammed', 'description' => 'Paper jam, cleared it but it jams again on every registration card.', 'category' => 'printer', 'priority' => 'normal', 'department' => 'Front Desk', 'location' => 'Reception', 'asset_id' => $printer->id], $staff('Front Desk'));
        $desk->update($t, $admin, 'Replaced the pickup roller. Printing normally now.', 'resolved');
        $desk->open(['title' => 'New login for night auditor', 'description' => 'Please create an account for our new night auditor starting Monday.', 'category' => 'account', 'priority' => 'low', 'department' => 'Accounts'], $staff('Accountant'));

        ItMaintenanceWindow::create(['title' => 'Router firmware update', 'description' => 'Security update for the main router.', 'affects' => 'No internet or Wi-Fi for about 15 minutes',
            'starts_at' => now()->addDays(2)->setTime(3, 0), 'ends_at' => now()->addDays(2)->setTime(3, 30), 'monitor_ids' => ItMonitor::whereIn('name', ['Internet (Google DNS)', 'Main router'])->pluck('id')->all(), 'created_by' => $admin->id]);

        $this->command?->info(ItAsset::count() . ' devices, ' . ItMonitor::count() . ' monitors (checked once), 4 tickets, 1 planned maintenance. Change the IP addresses to match the hotel network.');
    }
}
