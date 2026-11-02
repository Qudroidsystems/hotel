<?php

namespace Database\Seeders;

use App\Models\Energy\EnergySource;
use App\Models\Energy\EnergyZone;
use App\Models\Events\Hall;
use App\Models\Floor;
use App\Models\Pos\PosOutlet;
use App\Services\Energy\EnergyAdvisor;
use Illuminate\Database\Seeder;

/**
 * Demo Energy Saver: the grid (off, as it often is), three generators of different sizes with fuel in their
 * tanks, and the hotel's other places — corridors on each floor, the halls, the bar and restaurant, the lobby,
 * kitchen, laundry, pool and the server room — then a first check so the control room has its list.
 */
class EnergyDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (EnergySource::exists()) {
            return;
        }
        EnergySource::create(['name' => 'Grid (public power)', 'kind' => 'grid', 'capacity_kw' => 0, 'running' => false, 'running_since' => now()->subHours(2)]);
        EnergySource::create(['name' => 'Perkins 30 kVA', 'kind' => 'generator', 'capacity_kw' => 24, 'litres_per_hour' => 6.5, 'tank_litres' => 200, 'fuel_litres' => 140, 'fuel_at' => now()->subHours(3)]);
        EnergySource::create(['name' => 'Mikano 100 kVA', 'kind' => 'generator', 'capacity_kw' => 80, 'litres_per_hour' => 21, 'tank_litres' => 600, 'fuel_litres' => 410, 'fuel_at' => now()->subHours(3)]);
        EnergySource::create(['name' => 'Cummins 250 kVA', 'kind' => 'generator', 'capacity_kw' => 200, 'litres_per_hour' => 52, 'tank_litres' => 1500, 'fuel_litres' => 900, 'fuel_at' => now()->subHours(3),
            'running' => true, 'running_since' => now()->subHours(2)]);

        $i = 0;
        foreach (Floor::with('building')->orderBy('level')->get() as $f) {
            EnergyZone::create(['name' => 'Corridor: ' . ($f->building ? $f->building->name . ' ' : '') . $f->name, 'kind' => 'corridor', 'floor_id' => $f->id, 'watts' => 1800, 'low_watts' => 300, 'sort_order' => 10 + $i++]);
        }
        foreach (Hall::where('is_active', true)->get() as $h) {
            EnergyZone::create(['name' => $h->name, 'kind' => 'hall', 'hall_id' => $h->id, 'watts' => 18000, 'low_watts' => 300, 'sort_order' => 30]);
        }
        foreach (PosOutlet::where('is_active', true)->get() as $o) {
            EnergyZone::create(['name' => $o->name, 'kind' => 'outlet', 'outlet_id' => $o->id, 'watts' => 9000, 'low_watts' => 1500,
                'opens' => $o->guest_hours ? null : '07:00', 'closes' => $o->guest_hours ? null : '23:00', 'notes' => 'Cold rooms stay on', 'sort_order' => 40]);
        }
        foreach ([
            ['Lobby & reception', 'common', 6000, 2000, null, null, false, 'Night lights only after midnight'],
            ['Kitchen extraction & lights', 'service', 12000, 2500, '05:30', '23:30', false, 'Fridges and freezers on their own circuit'],
            ['Laundry', 'service', 15000, 0, '08:00', '18:00', false, 'Washers, dryers, ironing'],
            ['Pool pumps & lights', 'outdoor', 5500, 0, '06:00', '21:00', false, null],
            ['Garden & car park lights', 'outdoor', 2500, 2500, null, null, false, 'Security: on at night'],
            ['Admin offices', 'office', 4000, 200, '08:00', '18:00', false, null],
            ['Server room & CCTV', 'service', 3000, 3000, null, null, true, 'Never off'],
        ] as $n => [$name, $kind, $w, $low, $open, $close, $always, $note]) {
            EnergyZone::create(['name' => $name, 'kind' => $kind, 'watts' => $w, 'low_watts' => $low, 'opens' => $open, 'closes' => $close, 'always_on' => $always, 'notes' => $note, 'sort_order' => $n]);
        }

        app(EnergyAdvisor::class)->sync(alert: false);
    }
}
