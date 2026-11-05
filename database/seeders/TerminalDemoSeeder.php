<?php

namespace Database\Seeders;

use App\Models\Billing\PaymentTerminal;
use App\Models\Pos\PosOutlet;
use Illuminate\Database\Seeder;

/**
 * Two demo card terminals (they "pay" by themselves after a few seconds), so
 * the bill page and the bar till can be tried without a Paystack, Moniepoint
 * or OPay account:  php artisan db:seed --class=TerminalDemoSeeder
 */
class TerminalDemoSeeder extends Seeder
{
    public function run(): void
    {
        PaymentTerminal::firstOrCreate(['provider' => 'demo', 'identifier' => 'DEMO-FRONT'], ['name' => 'Front desk (demo)', 'is_active' => true]);
        PaymentTerminal::firstOrCreate(['provider' => 'demo', 'identifier' => 'DEMO-BAR'], [
            'name' => 'Bar (demo)', 'is_active' => true, 'pos_outlet_id' => PosOutlet::orderBy('id')->value('id'),
        ]);
    }
}
