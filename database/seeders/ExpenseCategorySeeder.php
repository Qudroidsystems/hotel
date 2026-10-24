<?php

namespace Database\Seeders;

use App\Models\Finance\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * Standard running-cost categories for a hotel. Safe to run on a live system:
 * adds missing ones only and never changes budgets or names already set.
 *   php artisan db:seed --class=ExpenseCategorySeeder
 */
class ExpenseCategorySeeder extends Seeder
{
    public const DEFAULTS = [
        'staff'       => ['Salaries & wages', 'Staff allowances & overtime', 'Pension & NHF contributions', 'Staff meals & welfare', 'Uniforms', 'Training'],
        'utilities'   => ['Electricity (grid)', 'Diesel / generator fuel', 'Gas (cooking)', 'Water', 'Internet & phone', 'Cable TV subscription'],
        'operations'  => ['Cleaning supplies', 'Laundry & dry cleaning', 'Guest amenities', 'Security services', 'Waste disposal', 'Fumigation & pest control', 'Transport & logistics'],
        'maintenance' => ['Generator servicing', 'Building repairs', 'Plumbing & electrical repairs', 'Air-conditioning servicing', 'Equipment repairs'],
        'sales'       => ['Advertising & promotion', 'OTA / booking commissions', 'Printing & signage'],
        'admin'       => ['Rent & lease', 'Licences, permits & levies', 'Professional fees', 'Office supplies', 'Software & IT services', 'Insurance'],
        'finance'     => ['Bank charges', 'POS / card terminal fees'],
        'other'       => ['Miscellaneous'],
    ];

    public function run(): void
    {
        $order = 0;
        foreach (self::DEFAULTS as $group => $names) {
            foreach ($names as $name) {
                ExpenseCategory::firstOrCreate(['name' => $name], ['group' => $group, 'sort_order' => $order += 10, 'is_active' => true]);
            }
        }
    }
}
