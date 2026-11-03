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

        $this->history();
        app(EnergyAdvisor::class)->sync(alert: false);
    }

    /**
     * A week behind today for the Fuel & energy report: two grid outages a day carried by the Mikano, its tank read
     * each morning, two deliveries — and one night where more diesel went than it burned (the diesel check flags it).
     */
    private function history(): void
    {
        $grid = EnergySource::where('kind', 'grid')->first();
        $gen = EnergySource::where('name', 'Mikano 100 kVA')->first();
        $big = EnergySource::where('name', 'Cummins 250 kVA')->first();
        $start = today()->subDays(7);
        $event = fn ($src, $kind, $at, $note, $value = null) => \App\Models\Energy\EnergyEvent::create(['source_id' => $src->id, 'kind' => $kind, 'value' => $value, 'by' => 'staff', 'note' => $note])->forceFill(['created_at' => $at])->save();
        $level = 520.0;
        for ($day = 0; $day < 7; $day++) {
            $d = $start->copy()->addDays($day);
            $outages = [[$d->copy()->setTime(1 + $day % 3, 0), 3 + $day % 3], [$d->copy()->setTime(14, 30), 2 + ($day + 1) % 3]];
            for ($t = $d->copy(); $t->lt($d->copy()->addDay()); $t->addMinutes(15)) {
                $hour = $t->hour + $t->minute / 60;
                $kw = round(38 + 22 * max(0, sin(($hour - 6) / 24 * 2 * M_PI)) + ($hour >= 18 && $hour < 23 ? 12 : 0), 1);
                $off = collect($outages)->first(fn ($o) => $t->gte($o[0]) && $t->lt($o[0]->copy()->addHours($o[1])));
                \App\Models\Energy\EnergySample::create(['at' => $t->copy(), 'kw' => $kw, 'grid_on' => ! $off, 'source_id' => $off ? $gen->id : null,
                    'litres_per_hour' => $off ? $gen->litresAt($kw) : 0, 'rooms_in_use' => 8 + $day % 5, 'waste_kw' => round(4 + ($hour < 7 ? 0 : 3), 1)]);
                if ($off) {
                    $level -= $gen->litresAt($kw) / 4;
                }
            }
            foreach ($outages as [$at, $hours]) {
                $event($grid, 'off', $at, 'Grid power off');
                $event($gen, 'on', $at->copy()->addMinutes(2), "{$gen->name} started");
                $event($gen, 'off', $at->copy()->addHours($hours), "{$gen->name} stopped");
                $event($grid, 'on', $at->copy()->addHours($hours), 'Grid power back');
            }
            if ($day === 2 || $day === 5) {
                $level += 300;
                \App\Models\Energy\EnergyFuelDelivery::create(['source_id' => $gen->id, 'delivered_at' => $d->copy()->setTime(11, 0), 'litres' => 300, 'price_per_litre' => 1250,
                    'total' => 375000, 'supplier_name' => 'Total filling station', 'invoice_ref' => 'WB-' . (4410 + $day)]);
                $event($gen, 'delivery', $d->copy()->setTime(11, 0), "300 L of diesel delivered into {$gen->name} by Total filling station", 300);
            }
            if ($day === 4) {
                $level -= 70;   // gone overnight, not burned
            }
            $event($gen, 'fuel', $d->copy()->addDay()->setTime(7, 30), 'Fuel ' . round($level) . ' L', round($level));
        }
        $gen->forceFill(['fuel_litres' => round($level), 'fuel_at' => today()->setTime(7, 30)])->save();
        // Now: the grid went off two hours ago and the big set was started (Energy Saver suggests the smaller one).
        $event($grid, 'off', now()->subHours(2), 'Grid power off');
        $event($big, 'on', now()->subHours(2)->addMinutes(3), "{$big->name} started");
    }
}
