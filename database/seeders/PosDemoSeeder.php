<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\Pos\PosCategory;
use App\Models\Pos\PosItem;
use App\Models\Pos\PosOrder;
use App\Models\Pos\PosOutlet;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Pos\PosService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A sample bar & restaurant menu and a day of sales:
 *   php artisan db:seed --class=PosDemoSeeder
 * (after RoomDemoSeeder and ReservationDemoSeeder, so room charges have guests).
 */
class PosDemoSeeder extends Seeder
{
    public function run(PosService $pos): void
    {
        if (PosOutlet::exists()) {
            $this->command?->warn('POS outlets already exist — demo menu not added.');
            return;
        }
        $admin = User::role('Super Admin')->first() ?? User::first();
        auth()->login($admin);

        $outlets = collect([
            ['Pool Bar', 'POOL', 'bar', '#0ea5e9', 'Happy hour 5–7pm daily'],
            ['Main Restaurant', 'REST', 'restaurant', '#ea580c', 'Thank you for dining with us'],
            ['Room Service', 'RMSV', 'room_service', '#7c3aed', 'Dial 0 for room service'],
        ])->mapWithKeys(fn ($o, $i) => [$o[1] => PosOutlet::create(['name' => $o[0], 'code' => $o[1], 'type' => $o[2], 'color' => $o[3], 'receipt_footer' => $o[4], 'sort_order' => $i])]);

        $menu = [
            // category, colour, charge type, outlets, items [name, price, sku]
            ['Beers', '#ca8a04', 'beverage', ['POOL', 'REST', 'RMSV'], [['Star Lager 60cl', 1500, 'BR-STAR'], ['Gulder 60cl', 1500, 'BR-GULD'], ['Heineken 33cl', 1800, 'BR-HEIN'], ['Guinness Stout', 1800, 'BR-GUIN']]],
            ['Soft drinks', '#0ea5e9', 'beverage', ['POOL', 'REST', 'RMSV'], [['Coca-Cola 35cl', 500, 'SD-COKE'], ['Fanta 35cl', 500, 'SD-FANT'], ['Bottled water 75cl', 300, 'SD-WATR'], ['Malt', 700, 'SD-MALT'], ['Fresh orange juice', 2000, 'SD-ORNG']]],
            ['Cocktails & spirits', '#db2777', 'beverage', ['POOL', 'REST'], [['Chapman', 2500, 'CK-CHAP'], ['Mojito', 4000, 'CK-MOJI'], ['Hennessy VS (shot)', 4500, 'SP-HENN'], ['Red wine (glass)', 3500, 'WN-RED']]],
            ['Mains', '#16a34a', 'food', ['REST', 'RMSV'], [['Jollof rice & chicken', 6500, 'MN-JOLL'], ['Fried rice & turkey', 7000, 'MN-FRIC'], ['Pounded yam & egusi', 6000, 'MN-POUN'], ['Grilled fish & chips', 9000, 'MN-FISH']]],
            ['Grills & small chops', '#dc2626', 'food', ['POOL', 'REST', 'RMSV'], [['Beef suya', 3500, 'GR-SUYA'], ['Asun (spicy goat)', 4000, 'GR-ASUN'], ['Goat meat pepper soup', 4500, 'GR-PSOU'], ['Small chops platter', 5000, 'GR-CHOP']]],
            ['Breakfast', '#f59e0b', 'food', ['REST', 'RMSV'], [['English breakfast', 6000, 'BF-ENGL'], ['Yam & egg sauce', 4500, 'BF-YAME'], ['Club sandwich', 5500, 'BF-CLUB']]],
        ];
        foreach ($menu as $sort => [$cat, $color, $type, $codes, $items]) {
            $category = PosCategory::create(['name' => $cat, 'color' => $color, 'sort_order' => $sort]);
            foreach ($items as $j => [$name, $price, $sku]) {
                $item = PosItem::create(['category_id' => $category->id, 'name' => $name, 'price' => $price, 'sku' => $sku, 'charge_type' => $type, 'sort_order' => $j]);
                $item->outlets()->sync($outlets->only($codes)->pluck('id'));
            }
        }

        // A day of sales, sent the same way a till sends them.
        $cashier = User::role('Cashier')->first() ?? $admin;
        $items = PosItem::all()->keyBy('sku');
        $guests = Reservation::inHouse()->with('rooms.room')->get();
        $line = fn ($sku, $q = 1, $note = null) => ['item_id' => $items[$sku]->id, 'name' => $items[$sku]->name, 'charge_type' => $items[$sku]->charge_type->value,
            'quantity' => $q, 'unit_price' => (float) $items[$sku]->price, 'note' => $note];
        $seq = 0;
        $sale = function ($outlet, $mins, array $lines, string $method, array $extra = []) use ($outlets, $cashier, &$seq) {
            $seq++;
            return ['uuid' => (string) Str::uuid(), 'number' => $outlets[$outlet]->code . '-DEMO-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                'outlet_id' => $outlets[$outlet]->id, 'ordered_at' => now()->subMinutes($mins)->toIso8601String(), 'method' => $method,
                'items' => $lines, 'cashier_id' => $cashier->id, 'device_id' => 'DEMO'] + $extra;
        };

        $orders = [
            $sale('POOL', 600, [$line('BR-STAR', 3), $line('GR-SUYA', 2)], 'cash', ['tendered' => 15000, 'table_label' => 'Pool table 2']),
            $sale('POOL', 540, [$line('CK-CHAP', 2), $line('SD-WATR', 2)], 'card', ['reference' => 'POS-118273']),
            $sale('REST', 480, [$line('MN-JOLL', 2), $line('SD-COKE', 2)], 'transfer', ['reference' => 'TRF-55120']),
            $sale('REST', 420, [$line('MN-POUN'), $line('GR-PSOU', 1, 'Extra hot'), $line('BR-GUIN', 2)], 'cash', ['tendered' => 15000, 'table_label' => 'Table 4', 'discount_amount' => 1000, 'discount_reason' => 'Regular guest']),
            $sale('POOL', 300, [$line('CK-MOJI', 2), $line('GR-CHOP')], 'cash', ['tendered' => 13000, 'was_offline' => true]),
            $sale('POOL', 120, [$line('BR-HEIN', 4)], 'cash', ['tendered' => 7200]),
            $sale('REST', 60, [$line('MN-FISH'), $line('WN-RED', 2)], 'card'),
        ];
        foreach ($guests->take(3)->values() as $i => $r) {
            $orders[] = $sale($i === 2 ? 'RMSV' : 'REST', 200 - $i * 40, $i === 2 ? [$line('BF-CLUB'), $line('SD-ORNG')] : [$line('MN-FRIC'), $line('BR-STAR', 2)], 'room',
                ['reservation_id' => $r->id, 'room_number' => $r->rooms->pluck('room.number')->filter()->join(', '), 'was_offline' => $i === 1]);
        }
        // A room charge for a booking that was cancelled — lands in "Needs attention".
        if ($cancelled = Reservation::where('status', ReservationStatus::Cancelled->value)->first()) {
            $orders[] = $sale('POOL', 90, [$line('BR-GULD', 2)], 'room', ['reservation_id' => $cancelled->id, 'room_number' => '???', 'was_offline' => true]);
        }

        $pos->sync($orders, $cashier);

        // One mistake, voided by a supervisor.
        $mistake = PosOrder::where('method', 'cash')->latest('id')->first();
        $pos->void($mistake, 'Rung up twice', $admin);

        $this->command?->info(PosItem::count() . ' menu items in ' . $outlets->count() . ' outlets, ' . PosOrder::count() . ' demo sales.');
    }
}
