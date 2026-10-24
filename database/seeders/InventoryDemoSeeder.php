<?php

namespace Database\Seeders;

use App\Enums\StockMovementType;
use App\Models\Inventory\StockCategory;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockLocation;
use App\Models\Inventory\Supplier;
use App\Models\Pos\PosItem;
use App\Models\Pos\PosOrder;
use App\Models\Pos\PosOutlet;
use App\Models\User;
use App\Services\Inventory\StockService;
use Illuminate\Database\Seeder;

/**
 * Sample stores, stock, suppliers and recipes:
 *   php artisan db:seed --class=InventoryDemoSeeder
 * Run after PosDemoSeeder (it links the menu to stock and books the demo sales).
 */
class InventoryDemoSeeder extends Seeder
{
    public function run(StockService $stock): void
    {
        if (StockItem::exists()) {
            $this->command?->warn('Stock items already exist — demo inventory not added.');
            return;
        }
        auth()->login(User::role('Super Admin')->first() ?? User::first());

        $loc = collect([
            ['Main Store', 'MAIN', 'store'], ['Pool Bar', 'POOLBAR', 'bar'], ['Restaurant & Kitchen', 'KITCHEN', 'kitchen'], ['Housekeeping Store', 'HSKP', 'housekeeping'],
        ])->mapWithKeys(fn ($l, $i) => [$l[1] => StockLocation::create(['name' => $l[0], 'code' => $l[1], 'type' => $l[2], 'sort_order' => $i])]);

        // Outlets sell from these stores.
        PosOutlet::where('code', 'POOL')->update(['stock_location_id' => $loc['POOLBAR']->id]);
        PosOutlet::whereIn('code', ['REST', 'RMSV'])->update(['stock_location_id' => $loc['KITCHEN']->id]);

        $sup = collect([
            ['Nigerian Breweries Distributor', 'Mr Femi Adebayo', '+2348031110001'],
            ['Lagos Fresh Foods Ltd', 'Mrs Kemi Ajayi', '+2348031110002'],
            ['Coca-Cola HBC Depot', 'Mr Sani Bello', '+2348031110003'],
            ['CleanPro Hotel Supplies', 'Ms Joy Okon', '+2348031110004'],
        ])->map(fn ($s) => Supplier::create(['name' => $s[0], 'contact_person' => $s[1], 'phone' => $s[2]]))->values();

        $cats = collect(['Beer & stout', 'Soft drinks & water', 'Spirits & wine', 'Food ingredients', 'Guest amenities', 'Cleaning'])
            ->mapWithKeys(fn ($c, $i) => [$c => StockCategory::create(['name' => $c, 'sort_order' => $i])]);

        // name, category, unit, cost, reorder level, reorder qty, supplier index, [store => opening qty]
        $items = [
            ['Star Lager 60cl', 'Beer & stout', 'bottle', 950, 48, 120, 0, ['MAIN' => 120, 'POOLBAR' => 24, 'KITCHEN' => 12]],
            ['Gulder 60cl', 'Beer & stout', 'bottle', 950, 48, 120, 0, ['MAIN' => 96, 'POOLBAR' => 24, 'KITCHEN' => 12]],
            ['Heineken 33cl', 'Beer & stout', 'bottle', 1100, 48, 96, 0, ['MAIN' => 72, 'POOLBAR' => 24]],
            ['Guinness Stout', 'Beer & stout', 'bottle', 1150, 24, 72, 0, ['MAIN' => 30, 'POOLBAR' => 6, 'KITCHEN' => 12]],
            ['Coca-Cola 35cl', 'Soft drinks & water', 'bottle', 250, 48, 96, 2, ['MAIN' => 96, 'POOLBAR' => 24, 'KITCHEN' => 24]],
            ['Fanta 35cl', 'Soft drinks & water', 'bottle', 250, 48, 96, 2, ['MAIN' => 30, 'POOLBAR' => 12, 'KITCHEN' => 12]],
            ['Bottled water 75cl', 'Soft drinks & water', 'bottle', 120, 60, 120, 2, ['MAIN' => 200, 'POOLBAR' => 36, 'KITCHEN' => 24, 'HSKP' => 60]],
            ['Malt 33cl', 'Soft drinks & water', 'bottle', 380, 24, 48, 2, ['MAIN' => 48, 'POOLBAR' => 12]],
            ['Hennessy VS 70cl', 'Spirits & wine', 'bottle', 38000, 2, 6, null, ['MAIN' => 4, 'POOLBAR' => 1]],
            ['Red wine (house) 75cl', 'Spirits & wine', 'bottle', 6500, 4, 12, null, ['MAIN' => 8, 'KITCHEN' => 3]],
            ['Rice (parboiled)', 'Food ingredients', 'kg', 1400, 25, 50, 1, ['MAIN' => 50, 'KITCHEN' => 10]],
            ['Chicken (cut)', 'Food ingredients', 'kg', 4200, 10, 20, 1, ['KITCHEN' => 12]],
            ['Turkey wings', 'Food ingredients', 'kg', 5200, 8, 15, 1, ['KITCHEN' => 9]],
            ['Beef (suya cut)', 'Food ingredients', 'kg', 5800, 5, 10, 1, ['POOLBAR' => 3, 'KITCHEN' => 4]],
            ['Goat meat', 'Food ingredients', 'kg', 6200, 5, 10, 1, ['KITCHEN' => 6]],
            ['Fresh oranges', 'Food ingredients', 'pcs', 150, 30, 100, 1, ['KITCHEN' => 60]],
            ['Bath soap (guest)', 'Guest amenities', 'pcs', 180, 100, 300, 3, ['MAIN' => 400, 'HSKP' => 120]],
            ['Toilet roll', 'Guest amenities', 'roll', 260, 100, 240, 3, ['MAIN' => 240, 'HSKP' => 60]],
            ['Toothpaste & brush kit', 'Guest amenities', 'set', 350, 60, 200, 3, ['MAIN' => 30, 'HSKP' => 20]],
            ['Liquid detergent 5L', 'Cleaning', 'pcs', 6500, 3, 6, 3, ['MAIN' => 5, 'HSKP' => 2]],
        ];
        $made = [];
        foreach ($items as [$name, $cat, $unit, $cost, $rl, $rq, $s, $opening]) {
            $item = StockItem::create(['name' => $name, 'category_id' => $cats[$cat]->id, 'unit' => $unit, 'reorder_level' => $rl, 'reorder_qty' => $rq,
                'preferred_supplier_id' => $s !== null ? $sup[$s]->id : null]);
            foreach ($opening as $code => $qty) {
                $stock->opening($item, $loc[$code]->id, $qty, $cost);
            }
            $made[$name] = $item;
        }

        // Recipes: what one sale uses.
        $recipes = [
            'Star Lager 60cl' => ['Star Lager 60cl' => 1], 'Gulder 60cl' => ['Gulder 60cl' => 1], 'Heineken 33cl' => ['Heineken 33cl' => 1],
            'Guinness Stout' => ['Guinness Stout' => 1], 'Coca-Cola 35cl' => ['Coca-Cola 35cl' => 1], 'Fanta 35cl' => ['Fanta 35cl' => 1],
            'Bottled water 75cl' => ['Bottled water 75cl' => 1], 'Malt' => ['Malt 33cl' => 1],
            'Hennessy VS (shot)' => ['Hennessy VS 70cl' => 0.05], 'Red wine (glass)' => ['Red wine (house) 75cl' => 0.2],
            'Chapman' => ['Fanta 35cl' => 0.5], 'Fresh orange juice' => ['Fresh oranges' => 4],
            'Jollof rice & chicken' => ['Rice (parboiled)' => 0.2, 'Chicken (cut)' => 0.3], 'Fried rice & turkey' => ['Rice (parboiled)' => 0.2, 'Turkey wings' => 0.35],
            'Beef suya' => ['Beef (suya cut)' => 0.2], 'Goat meat pepper soup' => ['Goat meat' => 0.25], 'Asun (spicy goat)' => ['Goat meat' => 0.25],
        ];
        foreach ($recipes as $posName => $parts) {
            $posItem = PosItem::where('name', $posName)->first();
            foreach ($posItem ? $parts : [] as $stockName => $qty) {
                $posItem->components()->create(['stock_item_id' => $made[$stockName]->id, 'quantity' => $qty]);
            }
        }

        // Book the demo sales against stock (they were rung up before recipes existed).
        foreach (PosOrder::where('status', '!=', PosOrder::VOIDED)->get() as $order) {
            $stock->consumePosOrder($order);
        }

        // A delivery, a transfer to the bar, some breakage and amenities used.
        $po = $stock->savePurchaseOrder(null, ['supplier_id' => $sup[0]->id, 'location_id' => $loc['MAIN']->id, 'expected_on' => today()->addDays(2)],
            [['item_id' => $made['Star Lager 60cl']->id, 'quantity' => 120, 'unit_cost' => 980], ['item_id' => $made['Heineken 33cl']->id, 'quantity' => 48, 'unit_cost' => 1120]]);
        $po->forceFill(['status' => 'approved', 'submitted_at' => now(), 'approved_at' => now()])->save();   // over the approval limit
        $stock->markOrdered($po);
        $po->load('lines');
        $receipt = $stock->receive(['supplier_invoice' => 'NB-44821', 'received_on' => today()], [
            ['item_id' => $made['Star Lager 60cl']->id, 'quantity' => 120, 'unit_cost' => 980, 'purchase_order_line_id' => $po->lines[0]->id],
            ['item_id' => $made['Heineken 33cl']->id, 'quantity' => 0, 'unit_cost' => 1120, 'purchase_order_line_id' => $po->lines[1]->id, 'rejected_quantity' => 6, 'reject_reason' => 'Crate damaged, bottles leaking'],
        ], $po);
        $admin = \App\Models\User::role('Super Admin')->first();
        if ($admin) {
            app(\App\Services\Inventory\ProcurementService::class)->recordInvoice(['stock_receipt_id' => $receipt->id, 'invoice_number' => 'NB-44821', 'invoice_date' => today(), 'amount' => 117600], $admin);
        }
        $stock->savePurchaseOrder(null, ['supplier_id' => $sup[1]->id, 'location_id' => $loc['KITCHEN']->id, 'notes' => 'Weekly fresh food'],
            [['item_id' => $made['Chicken (cut)']->id, 'quantity' => 20, 'unit_cost' => 4200], ['item_id' => $made['Goat meat']->id, 'quantity' => 10, 'unit_cost' => 6200]]);
        $stock->transfer($loc['MAIN']->id, $loc['POOLBAR']->id, [['item_id' => $made['Star Lager 60cl']->id, 'quantity' => 24], ['item_id' => $made['Coca-Cola 35cl']->id, 'quantity' => 24]], 'Evening top-up');
        $stock->adjust($made['Heineken 33cl'], $loc['POOLBAR']->id, StockMovementType::Waste, 2, 'Dropped crate — 2 bottles broken');
        $stock->adjust($made['Bath soap (guest)'], $loc['HSKP']->id, StockMovementType::Issue, 40, 'Rooms restocked, floors 1–3');

        $this->command?->info(StockItem::count() . ' stock items in ' . $loc->count() . ' stores, ' . count($recipes) . ' recipes.');
    }
}
