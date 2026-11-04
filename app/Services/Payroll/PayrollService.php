<?php

namespace App\Services\Payroll;

use App\Exceptions\BookingException;
use App\Models\Attendance\StaffLeave;
use App\Models\Attendance\StaffProfile;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseCategory;
use App\Models\Payroll\PayrollRun;
use App\Models\Payroll\Payslip;
use App\Models\Payroll\StaffPay;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Finance\ExpenseService;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Monthly payroll from attendance.
 *
 * Each person's pay is set once (salary parts or a daily rate, monthly deductions, pension / NHF / PAYE). A run for
 * a month reads the attendance timesheet: monthly staff lose a day's pay for each day absent or on unpaid leave
 * (if the hotel deducts them), daily staff are paid for the days they worked, and overtime can be paid. Then
 * pension (employee and employer), NHF and PAYE under the Nigeria Tax Act 2025 bands (Hotel Settings → Payroll),
 * the monthly deductions and anything added for the month. The run is submitted, approved by someone else,
 * then marked paid — recorded under Expenses, with a bank schedule to upload.
 */
class PayrollService
{
    public function __construct(private AttendanceService $attendance)
    {
    }

    // ── Tax ───────────────────────────────────────────────────────────

    /**
     * The bands from settings: "800000:0, 2200000:15, …, *:25" — each is the width of a band and its rate; "*" is
     * everything above.
     *
     * @return array<int, array{0: ?float, 1: float}> [[width or null, percent]]
     */
    public function bands(): array
    {
        $out = [];
        foreach (preg_split('/[,\n;]+/', (string) config('hotel.payroll.tax_bands')) as $part) {
            if (preg_match('/^\s*(\*|[\d.]+)\s*:\s*([\d.]+)\s*$/', $part, $m)) {
                $out[] = [$m[1] === '*' ? null : (float) $m[1], (float) $m[2]];
            }
        }

        return $out ?: [[800000, 0], [2200000, 15], [9000000, 18], [13000000, 21], [25000000, 23], [null, 25]];
    }

    /** Tax on a year's taxable income. */
    public function annualTax(float $taxable): float
    {
        $left = max(0, $taxable);
        $tax = 0.0;
        foreach ($this->bands() as [$width, $rate]) {
            if ($left <= 0) {
                break;
            }
            $part = $width === null ? $left : min($left, $width);
            $tax += $part * $rate / 100;
            $left -= $part;
        }

        return Money::round($tax);
    }

    public function rentRelief(?float $annualRent): float
    {
        return min((float) $annualRent * (float) config('hotel.payroll.rent_relief_percent', 20) / 100, (float) config('hotel.payroll.rent_relief_cap', 500000));
    }

    // ── One payslip ───────────────────────────────────────────────────

    /**
     * Work out a payslip. $att: {scheduled, present, absent, leave, unpaid_leave, late, late_minutes, overtime_minutes}
     * (null = this person doesn't clock in: paid in full). $adjustments: [{name, amount, kind}].
     */
    public function compute(StaffPay $pay, ?array $att, array $adjustments = []): array
    {
        $c = config('hotel.payroll');
        $earn = [];
        $ded = [];
        $base = (float) $pay->basic + (float) $pay->housing + (float) $pay->transport;   // pension base
        $hours = max(1, (int) ($c['hours_per_day'] ?? 8));

        if ($pay->pay_type === 'daily') {
            $days = (int) ($att['present'] ?? 0);
            $earn[] = ['name' => "{$days} " . str('day')->plural($days) . ' × ' . $this->money((float) $pay->daily_rate), 'amount' => Money::round($days * (float) $pay->daily_rate)];
            $hourly = (float) $pay->daily_rate / $hours;
            $base = $earn[0]['amount'];
        } else {
            foreach (['Basic salary' => $pay->basic, 'Housing' => $pay->housing, 'Transport' => $pay->transport] as $n => $v) {
                if ((float) $v > 0) {
                    $earn[] = ['name' => $n, 'amount' => (float) $v];
                }
            }
            foreach ($pay->allowances ?? [] as $a) {
                if ((float) ($a['amount'] ?? 0) > 0) {
                    $earn[] = ['name' => (string) $a['name'], 'amount' => (float) $a['amount']];
                }
            }
            $full = $pay->monthly_gross;
            $workDays = max(1, (int) ($att['scheduled'] ?? 0) + (int) ($att['leave'] ?? 0));
            $daily = $full / $workDays;
            $hourly = $daily / $hours;
            $missed = 0;
            if ($att && ($c['deduct_absence'] ?? true)) {
                $missed += (int) ($att['absent'] ?? 0);
            }
            if ($att && ($c['deduct_unpaid_leave'] ?? true)) {
                $missed += (int) ($att['unpaid_leave'] ?? 0);
            }
            if ($missed > 0) {
                $cut = Money::round(min($full, $daily * $missed));
                $earn[] = ['name' => "Days not worked ({$missed})", 'amount' => -$cut];
                $base = max(0, $base - Money::round($base / $workDays * $missed));
            }
        }
        if (($c['pay_overtime'] ?? false) && (int) ($att['overtime_minutes'] ?? 0) > 0) {
            $rate = $pay->overtime_rate !== null ? (float) $pay->overtime_rate : $hourly * (float) ($c['overtime_multiplier'] ?? 1.5);
            $h = round((int) $att['overtime_minutes'] / 60, 2);
            $earn[] = ['name' => "Overtime ({$h} h)", 'amount' => Money::round($h * $rate)];
        }
        foreach ($adjustments as $a) {
            if (($a['kind'] ?? 'earning') === 'earning') {
                $earn[] = ['name' => (string) $a['name'], 'amount' => (float) $a['amount']];
            }
        }
        $gross = Money::round(max(0, array_sum(array_column($earn, 'amount'))));

        $pensionE = $pay->pension ? Money::round($base * (float) ($c['pension_employee'] ?? 8) / 100) : 0.0;
        $pensionR = $pay->pension ? Money::round($base * (float) ($c['pension_employer'] ?? 10) / 100) : 0.0;
        $nhf = $pay->nhf ? Money::round(($pay->pay_type === 'daily' ? $gross : (float) $pay->basic) * (float) ($c['nhf'] ?? 2.5) / 100) : 0.0;
        $taxable = max(0, $gross - $pensionE - $nhf);
        $paye = $pay->paye ? Money::round($this->annualTax($taxable * 12 - $this->rentRelief((float) $pay->annual_rent)) / 12) : 0.0;

        if ($paye > 0) {
            $ded[] = ['name' => 'PAYE tax', 'amount' => $paye];
        }
        if ($pensionE > 0) {
            $ded[] = ['name' => 'Pension (' . rtrim(rtrim(number_format((float) ($c['pension_employee'] ?? 8), 2), '0'), '.') . '%)', 'amount' => $pensionE];
        }
        if ($nhf > 0) {
            $ded[] = ['name' => 'NHF', 'amount' => $nhf];
        }
        if ($att && (float) ($c['late_fine'] ?? 0) > 0 && (int) ($att['late'] ?? 0) > 0) {
            $ded[] = ['name' => "Lateness ({$att['late']} " . str('day')->plural((int) $att['late']) . ')', 'amount' => Money::round((int) $att['late'] * (float) $c['late_fine'])];
        }
        foreach ($pay->deductions ?? [] as $d) {
            if ((float) ($d['amount'] ?? 0) > 0) {
                $ded[] = ['name' => (string) $d['name'], 'amount' => (float) $d['amount']];
            }
        }
        foreach ($adjustments as $a) {
            if (($a['kind'] ?? 'earning') === 'deduction') {
                $ded[] = ['name' => (string) $a['name'], 'amount' => (float) $a['amount']];
            }
        }
        $total = Money::round(array_sum(array_column($ded, 'amount')));

        return [
            'pay_type' => $pay->pay_type, 'attendance' => $att, 'earnings' => $earn, 'deductions' => $ded, 'adjustments' => $adjustments ?: null,
            'gross' => $gross, 'taxable' => Money::round($taxable), 'paye' => $paye, 'pension_employee' => $pensionE, 'pension_employer' => $pensionR, 'nhf' => $nhf,
            'total_deductions' => $total, 'net' => Money::round($gross - $total),
            'bank_name' => $pay->bank_name, 'account_number' => $pay->account_number, 'account_name' => $pay->account_name,
        ];
    }

    // ── Runs ──────────────────────────────────────────────────────────

    public function canManage(User $u): bool
    {
        return $u->can('Manage payroll');
    }

    /** Start (or open) the payroll for a month, worked out from attendance. */
    public function start(string $period, User $by): PayrollRun
    {
        $this->authorize($by, 'Manage payroll');
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new BookingException('Choose a month.');
        }
        $from = Carbon::createFromFormat('!Y-m', $period)->startOfMonth();
        if ($from->gt(today())) {
            throw new BookingException('That month hasn’t started yet.');
        }
        if ($existing = PayrollRun::where('period', $period)->first()) {
            return $existing;
        }
        if (! StaffPay::where('is_active', true)->exists()) {
            throw new BookingException('Set up staff pay first (Payroll → Staff pay).');
        }
        $run = PayrollRun::create(['period' => $period, 'from_date' => $from, 'to_date' => $from->copy()->endOfMonth(), 'status' => 'draft', 'created_by' => $by->id]);
        $this->build($run);
        Audit::record('payroll.started', "Started payroll {$run->number} for {$run->month_label}", $run, by: $by);

        return $run->fresh();
    }

    /** Work out every payslip again from attendance and pay (draft only); one-off additions are kept. */
    public function recalculate(PayrollRun $run, User $by): void
    {
        $this->authorize($by, 'Manage payroll');
        $this->draftOnly($run);
        $this->build($run);
        Audit::record('payroll.recalculated', "Recalculated payroll {$run->number}", $run, by: $by);
    }

    private function build(PayrollRun $run): void
    {
        $pays = StaffPay::where('is_active', true)->with('user')->get()->filter(fn ($p) => $p->user && $p->user->status !== 'inactive')->keyBy('user_id');
        $profiles = StaffProfile::with('department')->whereIn('user_id', $pays->keys())->get()->keyBy('user_id');
        $sheet = $this->attendance->timesheet($run->from_date, $run->to_date)->keyBy(fn ($r) => $r['profile']->user_id);
        $unpaid = StaffLeave::where('status', 'approved')->where('type', 'unpaid')->whereIn('user_id', $pays->keys())
            ->where('from_date', '<=', $run->to_date)->where('to_date', '>=', $run->from_date)->get()->groupBy('user_id');
        $old = Payslip::where('run_id', $run->id)->get()->keyBy('user_id');

        DB::transaction(function () use ($run, $pays, $profiles, $sheet, $unpaid, $old) {
            foreach ($pays as $uid => $pay) {
                $t = $sheet->get($uid);
                $att = null;
                if ($t) {
                    $unpaidDays = 0;
                    foreach ($unpaid->get($uid, collect()) as $l) {
                        $unpaidDays += collect($t['days'])->filter(fn ($d, $date) => $date >= $l->from_date->toDateString() && $date <= $l->to_date->toDateString() && ($d['leave'] ?? false))->count();
                    }
                    $att = ['scheduled' => $t['scheduled'], 'present' => $t['present'], 'absent' => $t['absent'], 'leave' => $t['leave'], 'unpaid_leave' => $unpaidDays,
                        'late' => $t['late'], 'late_minutes' => $t['late_minutes'], 'overtime_minutes' => $t['overtime']];
                }
                $p = $profiles->get($uid);
                $slip = $old->get($uid) ?? new Payslip(['run_id' => $run->id, 'user_id' => $uid]);
                $slip->fill($this->compute($pay, $att, $slip->adjustments ?? []) + [
                    'run_id' => $run->id, 'user_id' => $uid, 'staff_no' => $p?->staff_no, 'name' => $pay->user->name,
                    'department' => $p?->department?->name, 'job_title' => $p?->job_title,
                ])->save();
            }
            // People whose pay was switched off since the draft was made.
            Payslip::where('run_id', $run->id)->whereNotIn('user_id', $pays->keys())->delete();
            $this->totals($run);
        });
    }

    private function totals(PayrollRun $run): void
    {
        $s = Payslip::where('run_id', $run->id)->selectRaw('count(*) n, sum(gross) gross, sum(net) net, sum(paye) paye, sum(pension_employee) pe, sum(pension_employer) pr, sum(nhf) nhf, sum(total_deductions) ded')->first();
        $run->forceFill(['staff_count' => (int) $s->n, 'gross' => (float) $s->gross, 'net' => (float) $s->net, 'paye' => (float) $s->paye, 'pension_employee' => (float) $s->pe,
            'pension_employer' => (float) $s->pr, 'nhf' => (float) $s->nhf, 'deductions' => (float) $s->ded])->save();
    }

    /** A one-off line on this month's payslip: a bonus (earning) or a salary advance being repaid (deduction). */
    public function addAdjustment(Payslip $slip, string $name, float $amount, string $kind, User $by): void
    {
        $this->authorize($by, 'Manage payroll');
        $this->draftOnly($slip->run);
        if ($amount <= 0 || ! in_array($kind, ['earning', 'deduction'], true) || trim($name) === '') {
            throw new BookingException('Give a name and an amount.');
        }
        $adj = $slip->adjustments ?? [];
        $adj[] = ['name' => mb_substr(trim($name), 0, 80), 'amount' => Money::round($amount), 'kind' => $kind];
        $this->rework($slip, $adj);
        Audit::record('payroll.adjusted', ($kind === 'earning' ? 'Added ' : 'Deducted ') . $this->money($amount) . " ({$name}) on {$slip->name}'s payslip, {$slip->run->number}", $slip->run, by: $by);
    }

    public function removeAdjustment(Payslip $slip, int $index, User $by): void
    {
        $this->authorize($by, 'Manage payroll');
        $this->draftOnly($slip->run);
        $adj = $slip->adjustments ?? [];
        unset($adj[$index]);
        $this->rework($slip, array_values($adj));
    }

    private function rework(Payslip $slip, array $adjustments): void
    {
        $pay = StaffPay::where('user_id', $slip->user_id)->first() ?? throw new BookingException('This person has no pay set up any more. Recalculate the payroll.');
        $slip->fill($this->compute($pay, $slip->attendance, $adjustments))->save();
        $this->totals($slip->run);
    }

    public function submit(PayrollRun $run, User $by): void
    {
        $this->authorize($by, 'Manage payroll');
        $this->draftOnly($run);
        if (! $run->payslips()->exists()) {
            throw new BookingException('There is no one on this payroll.');
        }
        if ($run->payslips()->where('net', '<', 0)->exists()) {
            throw new BookingException('Someone’s deductions are more than their pay. Fix their payslip first.');
        }
        $run->forceFill(['status' => 'submitted', 'submitted_by' => $by->id, 'submitted_at' => now()])->save();
        Audit::record('payroll.submitted', "Sent payroll {$run->number} ({$this->money((float) $run->net)} net for {$run->staff_count} staff) for approval", $run, by: $by);
    }

    /** Approve: someone other than the person who sent it. */
    public function approve(PayrollRun $run, User $by): void
    {
        $this->authorize($by, 'Approve payroll');
        if ($run->status !== 'submitted') {
            throw new BookingException('Only a payroll waiting for approval can be approved.');
        }
        if ($run->submitted_by === $by->id && ! $by->hasRole('Super Admin')) {
            throw new BookingException('Someone else must approve a payroll you sent.');
        }
        $run->forceFill(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()])->save();
        Audit::record('payroll.approved', "Approved payroll {$run->number}: {$this->money((float) $run->net)} net, {$run->staff_count} staff", $run, by: $by, flagged: true);
    }

    /** Send it back to draft (with why). */
    public function reject(PayrollRun $run, User $by, string $reason): void
    {
        $this->authorize($by, 'Approve payroll');
        if (! in_array($run->status, ['submitted', 'approved'], true)) {
            throw new BookingException('Only a payroll waiting for approval (or approved and not paid) can be sent back.');
        }
        $run->forceFill(['status' => 'draft', 'approved_by' => null, 'approved_at' => null, 'notes' => mb_substr('Sent back: ' . $reason, 0, 500)])->save();
        Audit::record('payroll.rejected', "Sent payroll {$run->number} back: {$reason}", $run, by: $by);
    }

    /** Paid (the bank schedule was uploaded): record the cost under Expenses, paid by transfer. */
    public function markPaid(PayrollRun $run, User $by, bool $expense = true, ?int $bankAccountId = null): void
    {
        $this->authorize($by, 'Manage payroll');
        if ($run->status !== 'approved') {
            throw new BookingException('Only an approved payroll can be marked paid.');
        }
        DB::transaction(function () use ($run, $by, $expense, $bankAccountId) {
            $exp = null;
            $cat = ExpenseCategory::where('is_active', true)->where('name', 'like', 'Salaries%')->first() ?? ExpenseCategory::where('is_active', true)->where('group', 'staff')->orderBy('sort_order')->first();
            if ($expense && $cat && (float) $run->gross > 0) {
                $exp = app(ExpenseService::class)->record([
                    'category_id' => $cat->id, 'department' => null, 'description' => "Payroll {$run->month_label} ({$run->staff_count} staff)",
                    'payee' => 'Staff (payroll)', 'amount' => Money::round((float) $run->gross + (float) $run->pension_employer), 'tax_amount' => 0,
                    'expense_date' => now()->toDateString(), 'invoice_ref' => $run->number,
                    'notes' => "Net pay {$this->money((float) $run->net)}, PAYE {$this->money((float) $run->paye)}, pension {$this->money((float) $run->pension_employee + (float) $run->pension_employer)}, NHF {$this->money((float) $run->nhf)}. From Payroll.",
                ], [], $by);
                $exp->forceFill(['status' => 'paid', 'approved_by' => $run->approved_by, 'approved_at' => $run->approved_at, 'paid_at' => now(), 'paid_by' => $by->id,
                    'method' => 'transfer', 'bank_account_id' => $bankAccountId, 'payment_ref' => $run->number])->save();
            }
            $run->forceFill(['status' => 'paid', 'paid_by' => $by->id, 'paid_at' => now(), 'expense_id' => $exp?->id])->save();
        });
        Audit::record('payroll.paid', "Payroll {$run->number} marked paid", $run, by: $by);
    }

    /** The bank schedule: one row per person, ready for the bank's bulk transfer upload. */
    public function bankRows(PayrollRun $run): Collection
    {
        return $run->payslips()->get()->map(fn (Payslip $s) => [
            'name' => $s->account_name ?: $s->name, 'bank' => $s->bank_name, 'account' => $s->account_number, 'amount' => number_format((float) $s->net, 2, '.', ''),
            'narration' => 'Salary ' . $run->month_label, 'staff_no' => $s->staff_no,
        ]);
    }

    /** E-mail each person their payslip (approved or paid runs). */
    public function emailSlips(PayrollRun $run, User $by): int
    {
        $this->authorize($by, 'Manage payroll');
        if (! in_array($run->status, ['approved', 'paid'], true)) {
            throw new BookingException('Payslips are sent once the payroll is approved.');
        }
        $n = 0;
        foreach ($run->payslips()->with('user')->get() as $s) {
            if (! $s->user?->email) {
                continue;
            }
            try {
                Mail::send('payroll.email', ['s' => $s, 'run' => $run], fn ($m) => $m->to($s->user->email, $s->name)->subject("Your payslip for {$run->month_label}"));
                $s->forceFill(['emailed_at' => now()])->save();
                $n++;
            } catch (\Throwable $e) {
                Log::warning("Payslip {$s->id} not e-mailed: " . $e->getMessage());
            }
        }

        return $n;
    }

    /** A person's own payslips (approved and paid runs only). */
    public function mine(User $u): Collection
    {
        return Payslip::where('user_id', $u->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->with('run')->get()
            ->sortByDesc(fn ($s) => $s->run->period)->values();
    }

    public function slipJson(Payslip $s): array
    {
        return [
            'id' => $s->id, 'month' => $s->run->month_label, 'period' => $s->run->period, 'status' => $s->run->status, 'paid' => $s->run->status === 'paid',
            'gross_label' => $this->money((float) $s->gross), 'net_label' => $this->money((float) $s->net), 'deductions_label' => $this->money((float) $s->total_deductions),
            'earnings' => collect($s->earnings)->map(fn ($l) => ['name' => $l['name'], 'amount_label' => ($l['amount'] < 0 ? '−' : '') . $this->money(abs((float) $l['amount']))])->values(),
            'deductions' => collect($s->deductions)->map(fn ($l) => ['name' => $l['name'], 'amount_label' => $this->money((float) $l['amount'])])->values(),
            'attendance' => $s->attendance, 'bank' => trim(($s->bank_name ?? '') . ($s->account_number ? ' •••• ' . substr($s->account_number, -4) : '')) ?: null,
            'url' => route('payroll.my.show', $s),
        ];
    }

    private function draftOnly(PayrollRun $run): void
    {
        if (! $run->isDraft()) {
            throw new BookingException('This payroll has been sent for approval. Ask the approver to send it back to change it.');
        }
    }

    private function authorize(User $u, string $permission): void
    {
        if (! $u->can($permission)) {
            throw new AuthorizationException('You are not allowed to do that.');
        }
    }

    public function money(float $v): string
    {
        return config('hotel.currency_symbol') . number_format($v, 2);
    }
}
