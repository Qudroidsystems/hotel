<?php

use App\Models\Attendance\StaffProfile;
use App\Models\Finance\Expense;
use App\Models\Payroll\PayrollRun;
use App\Models\Payroll\StaffPay;
use App\Models\User;
use App\Services\Mobile\StaffActions;
use App\Services\Payroll\PayrollService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    auth()->logout();
    $this->svc = app(PayrollService::class);
    $mk = function (string $name, string $role) {
        $u = User::create(['name' => $name, 'email' => str($name)->slug() . '@test.local', 'password' => bcrypt('Secret@123')]);
        $u->assignRole($role);

        return $u;
    };
    $this->hr = $mk('Halima HR', 'HR Administrator');
    $this->gm = $mk('Gbenga GM', 'General Manager');
    $this->staff = $mk('Sade Staff', 'Front Desk');
    StaffProfile::create(['user_id' => $this->staff->id, 'staff_no' => 'STF-9001', 'job_title' => 'Receptionist', 'tracks_attendance' => false, 'badge_token' => StaffProfile::newBadgeToken()]);
    $this->pay = StaffPay::create(['user_id' => $this->staff->id, 'basic' => 90000, 'housing' => 37500, 'transport' => 22500, 'pension' => true, 'nhf' => false, 'paye' => true,
        'bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Sade Staff']);
    $this->period = today()->subMonthNoOverflow()->format('Y-m');
});

test('PAYE follows the Nigeria Tax Act 2025 bands, after pension and NHF, with rent relief', function () {
    expect($this->svc->annualTax(800000))->toBe(0.0)
        ->and($this->svc->annualTax(1656000))->toBe(128400.0)
        ->and($this->svc->annualTax(60000000))->toBe(12930000.0);

    $slip = $this->svc->compute($this->pay, null);
    expect($slip['gross'])->toBe(150000.0)->and($slip['pension_employee'])->toBe(12000.0)->and($slip['pension_employer'])->toBe(15000.0)
        ->and($slip['paye'])->toBe(10700.0)->and($slip['net'])->toBe(127300.0);

    $this->pay->annual_rent = 1200000;   // 20% = 240,000 off the year's taxable pay
    expect($this->svc->compute($this->pay, null)['paye'])->toBe(7700.0);

    config(['hotel.payroll.tax_bands' => '1000000:0, *:10']);
    expect($this->svc->annualTax(1500000))->toBe(50000.0);
});

test('days not worked come off a salary; daily staff are paid for the days they worked; overtime when switched on', function () {
    $att = ['scheduled' => 20, 'present' => 18, 'absent' => 2, 'leave' => 2, 'unpaid_leave' => 1, 'late' => 3, 'late_minutes' => 40, 'overtime_minutes' => 120];
    $slip = $this->svc->compute($this->pay, $att);
    // 22 working days (20 scheduled + 2 leave); 3 unpaid (2 absent + 1 unpaid leave).
    expect(collect($slip['earnings'])->firstWhere('name', 'Days not worked (3)')['amount'])->toBe(-round(150000 / 22 * 3, 2))
        ->and($slip['gross'])->toBe(round(150000 - 150000 / 22 * 3, 2));

    config(['hotel.payroll.late_fine' => 500, 'hotel.payroll.pay_overtime' => true]);
    $slip = $this->svc->compute($this->pay, $att);
    expect(collect($slip['deductions'])->firstWhere('name', 'Lateness (3 days)')['amount'])->toBe(1500.0)
        ->and(collect($slip['earnings'])->pluck('name'))->toContain('Overtime (2 h)');

    $daily = new StaffPay(['pay_type' => 'daily', 'daily_rate' => 7000, 'pension' => false, 'paye' => true]);
    $d = $this->svc->compute($daily, $att);
    expect($d['earnings'][0]['amount'])->toEqual(18 * 7000.0);
});

test('a payroll is started from attendance, approved by someone else, paid into Expenses, with a bank schedule', function () {
    $this->actingAs($this->hr)->post(route('payroll.start'), ['period' => $this->period])->assertRedirect();
    $run = PayrollRun::first();
    expect($run->status)->toBe('draft')->and($run->staff_count)->toBe(1)->and((float) $run->net)->toBe(127300.0);
    $this->get(route('payroll.show', $run))->assertOk()->assertSee('Sade Staff');

    $slip = $run->payslips()->first();
    $this->post(route('payroll.adjust', [$run, $slip]), ['name' => 'Bonus', 'amount' => 20000, 'kind' => 'earning'])->assertSessionHas('success');
    expect((float) $slip->fresh()->gross)->toBe(170000.0);
    $this->post(route('payroll.act', [$run, 'submit']))->assertSessionHas('success');
    $this->post(route('payroll.adjust', [$run, $slip]), ['name' => 'Late', 'amount' => 100, 'kind' => 'deduction'])->assertSessionHas('error');   // not in draft
    $this->post(route('payroll.act', [$run, 'approve']))->assertForbidden();   // HR can't approve

    // The staff member can't see it until it's approved.
    $this->actingAs($this->staff)->get(route('payroll.my'))->assertOk()->assertDontSee($run->month_label);

    $this->actingAs($this->gm)->post(route('payroll.act', [$run, 'approve']))->assertSessionHas('success');
    expect($run->fresh()->status)->toBe('approved');

    $this->actingAs($this->hr);
    $csv = $this->get(route('payroll.bank', $run))->assertOk()->streamedContent();
    expect($csv)->toContain('0123456789')->toContain(number_format((float) $run->fresh()->net, 2, '.', ''));
    $this->post(route('payroll.act', [$run, 'paid']), ['expense' => 1])->assertSessionHas('success');
    $run->refresh();
    $e = Expense::find($run->expense_id);
    expect($run->status)->toBe('paid')->and($e->status)->toBe('paid')->and((float) $e->amount)->toBe((float) $run->gross + (float) $run->pension_employer);

    $this->actingAs($this->staff)->get(route('payroll.my'))->assertOk()->assertSee($run->month_label);
    $this->get(route('payroll.my.show', $slip))->assertOk()->assertSee('Net pay');
    $other = User::factory()->create();
    $this->actingAs($other)->get(route('payroll.my.show', $slip))->assertForbidden();
});

test('an approver sends a payroll back; the staff app shows it for approval; staff see their payslips in the app', function () {
    $run = $this->svc->start($this->period, $this->hr);
    $this->svc->submit($run, $this->hr);
    $actions = app(StaffActions::class);
    $item = collect($actions->approvals($this->gm))->firstWhere('key', "payroll:{$run->id}");
    expect($item)->not->toBeNull()->and(collect($item['actions'])->pluck('id')->all())->toBe(['approve', 'reject'])
        ->and(collect($actions->approvals($this->hr))->firstWhere('key', "payroll:{$run->id}"))->toBeNull();
    $actions->perform($this->gm, 'payroll', $run->id, 'reject', 'Check Sade’s overtime');
    expect($run->fresh()->status)->toBe('draft')->and($run->fresh()->notes)->toContain('overtime');

    $this->svc->submit($run->fresh(), $this->hr);
    $actions->perform($this->gm, 'payroll', $run->id, 'approve');
    if (class_exists(\Laravel\Sanctum\Sanctum::class)) {
        $token = $this->staff->createToken('t')->plainTextToken;
        $list = $this->withToken($token)->getJson('/api/v1/me/payslips')->assertOk()->json('data');
        expect($list)->toHaveCount(1)->and($list[0]['net_label'])->toContain('127,300.00')->and($list[0]['bank'])->toBe('GTBank •••• 6789');
        $this->withToken($token)->getJson("/api/v1/me/payslips/{$list[0]['id']}")->assertOk();
    }
});

test('staff pay is set by payroll managers; the bank account is kept encrypted', function () {
    $this->actingAs($this->hr)->get(route('payroll.staff'))->assertOk()->assertSee('Sade Staff');
    $this->post(route('payroll.staff.save', $this->staff), ['pay_type' => 'monthly', 'basic' => 0])->assertSessionHas('error');
    $this->post(route('payroll.staff.save', $this->staff), ['pay_type' => 'monthly', 'basic' => 100000, 'housing' => 40000, 'paye' => 1, 'pension' => 1, 'account_number' => '12345',
    ])->assertSessionHasErrors('account_number');
    $this->post(route('payroll.staff.save', $this->staff), ['pay_type' => 'monthly', 'basic' => 100000, 'housing' => 40000, 'paye' => 1, 'pension' => 1,
        'allowances' => [['name' => 'Meal', 'amount' => 15000], ['name' => '', 'amount' => '']], 'account_number' => '9876543210'])->assertRedirect(route('payroll.staff'));
    $pay = $this->pay->fresh();
    expect($pay->monthly_gross)->toBe(155000.0)->and($pay->account_number)->toBe('9876543210')
        ->and(Illuminate\Support\Facades\DB::table('staff_pay')->where('id', $pay->id)->value('account_number'))->not->toContain('9876543210');

    $this->actingAs($this->staff)->get(route('payroll.index'))->assertForbidden();
    $this->actingAs($this->gm)->get(route('payroll.staff'))->assertForbidden();   // approvers don't set pay
});
