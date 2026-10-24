<?php

namespace Database\Seeders;

use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseCategory;
use App\Models\User;
use App\Services\Finance\ExpenseService;
use Illuminate\Database\Seeder;

/**
 * Three months of sample running costs with budgets, some waiting for
 * approval and some approved but unpaid:
 *   php artisan db:seed --class=ExpenseDemoSeeder
 */
class ExpenseDemoSeeder extends Seeder
{
    public function run(ExpenseService $service): void
    {
        $this->call(ExpenseCategorySeeder::class);
        if (Expense::exists()) {
            $this->command?->warn('Expenses already exist — demo not added.');
            return;
        }
        $admin = User::role('Super Admin')->first() ?? User::first();
        $clerk = User::role('Accountant')->first() ?? $admin;
        auth()->login($admin);

        $budgets = ['Diesel / generator fuel' => 900000, 'Electricity (grid)' => 350000, 'Salaries & wages' => 4500000, 'Cleaning supplies' => 120000,
            'Generator servicing' => 150000, 'Internet & phone' => 85000, 'Laundry & dry cleaning' => 100000, 'Advertising & promotion' => 150000, 'Bank charges' => 30000];
        foreach ($budgets as $name => $b) {
            ExpenseCategory::where('name', $name)->update(['monthly_budget' => $b]);
        }
        $cat = fn ($n) => ExpenseCategory::where('name', $n)->value('id');

        $plan = [
            // [category, department, description, payee, amount, vat, day]
            ['Salaries & wages', 'Administration', 'Monthly payroll', 'Staff payroll', 4380000, 0, 26],
            ['Diesel / generator fuel', 'Maintenance & Engineering', 'Diesel for generator (1,000 L)', 'Total filling station', 1050000, 0, 3],
            ['Diesel / generator fuel', 'Maintenance & Engineering', 'Diesel top-up (300 L)', 'Total filling station', 315000, 0, 17],
            ['Electricity (grid)', 'Administration', 'Prepaid electricity units', 'EKEDC', 300000, 20930, 5],
            ['Internet & phone', 'IT', 'Fibre internet subscription', 'ISP', 85000, 5930, 1],
            ['Cleaning supplies', 'Housekeeping', 'Detergent, bleach, mops', 'Market', 42500, 0, 9],
            ['Laundry & dry cleaning', 'Housekeeping', 'Outsourced linen laundry', 'CleanPro Laundry', 64000, 0, 14],
            ['Generator servicing', 'Maintenance & Engineering', 'Generator service and oil change', 'PowerGen Services', 95000, 6627, 20],
            ['Advertising & promotion', 'Sales & Marketing', 'Instagram & Facebook ads', 'Meta', 60000, 0, 11],
            ['Bank charges', 'Administration', 'Monthly bank charges', 'GTBank', 18500, 0, 28],
        ];

        foreach ([2, 1, 0] as $back) {
            $month = today()->subMonthsNoOverflow($back)->startOfMonth();
            foreach ($plan as [$c, $dept, $desc, $payee, $amount, $vat, $day]) {
                $date = $month->copy()->day(min($day, $month->daysInMonth));
                if ($date->isFuture()) {
                    continue;
                }
                $amt = round($amount * (1 + (crc32($desc . $back) % 15 - 7) / 100), -2);   // a little variation month to month
                $e = $service->record(['category_id' => $cat($c), 'department' => $dept, 'description' => $desc, 'payee' => $payee,
                    'amount' => $amt, 'tax_amount' => $vat ? round($amt * 0.075 / 1.075, 2) : 0, 'expense_date' => $date->toDateString()], [], $clerk);
                if ($e->status === 'pending' && ($back > 0 || $day < 20)) {
                    $service->approve($e, $admin);
                    $e->refresh();
                }
                if ($e->status === 'approved' && ($back > 0 || $day < 12)) {
                    $service->pay($e, ['method' => $amt > 50000 ? 'transfer' : 'cash'], $admin);
                }
            }
        }

        $this->command?->info(Expense::count() . ' demo expenses over 3 months; budgets set on ' . count($budgets) . ' categories.');
    }
}
