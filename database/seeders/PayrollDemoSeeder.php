<?php

namespace Database\Seeders;

use App\Models\Attendance\StaffProfile;
use App\Models\Payroll\PayrollRun;
use App\Models\Payroll\StaffPay;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Illuminate\Database\Seeder;

/** Demo payroll: pay for every member of staff (from their job), and last month's payroll prepared, approved and paid. */
class PayrollDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (StaffPay::exists()) {
            return;
        }
        $banks = ['GTBank', 'Access Bank', 'Zenith Bank', 'First Bank', 'UBA', 'Moniepoint'];
        foreach (StaffProfile::with('user')->get()->filter(fn ($p) => $p->user)->values() as $i => $p) {
            $job = mb_strtolower(($p->job_title ?? '') . ' ' . $p->user->getRoleNames()->implode(' '));
            $month = match (true) {
                str_contains($job, 'general manager') || str_contains($job, 'owner') => 850000,
                str_contains($job, 'manager') || str_contains($job, 'accountant') => 420000,
                str_contains($job, 'supervisor') || str_contains($job, 'it ') || str_contains($job, 'hr') => 260000,
                default => 150000,
            };
            $daily = $i % 9 === 8;   // a few casual staff paid by the day
            StaffPay::create([
                'user_id' => $p->user_id, 'pay_type' => $daily ? 'daily' : 'monthly',
                'basic' => $daily ? 0 : round($month * 0.6), 'housing' => $daily ? 0 : round($month * 0.25), 'transport' => $daily ? 0 : round($month * 0.15),
                'daily_rate' => $daily ? 7000 : null,
                'allowances' => $daily || $month < 200000 ? null : [['name' => 'Meal', 'amount' => 20000]],
                'deductions' => $i % 5 === 2 ? [['name' => 'Staff cooperative', 'amount' => 10000]] : null,
                'pension' => ! $daily, 'nhf' => ! $daily && $i % 2 === 0, 'paye' => true,
                'bank_name' => $banks[$i % count($banks)], 'account_number' => str_pad((string) (1000000000 + $i * 7919), 10, '0', STR_PAD_LEFT), 'account_name' => $p->user->name,
            ]);
        }

        $hr = User::role('HR Administrator')->first() ?? User::role('Accountant')->first();
        $boss = User::role('General Manager')->first() ?? User::role('Hotel Owner')->first() ?? User::role('Super Admin')->first();
        if (! $hr || ! $boss || $hr->is($boss) || PayrollRun::exists()) {
            return;
        }
        $svc = app(PayrollService::class);
        $run = $svc->start(today()->subMonthNoOverflow()->format('Y-m'), $hr);
        $first = $run->payslips()->first();
        if ($first) {
            $svc->addAdjustment($first, 'Best staff of the month', 25000, 'earning', $hr);
        }
        $svc->submit($run, $hr);
        $svc->approve($run->fresh(), $boss);
        $svc->markPaid($run->fresh(), $hr);
    }
}
