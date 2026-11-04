<?php

namespace App\Http\Controllers\Payroll;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Attendance\StaffProfile;
use App\Models\HotelBankAccount;
use App\Models\Payroll\PayrollRun;
use App\Models\Payroll\Payslip;
use App\Models\Payroll\StaffPay;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Payroll: staff pay, monthly runs, approval, the bank schedule, payslips — and each person's own payslips. */
class PayrollController extends Controller
{
    public function __construct(private PayrollService $payroll)
    {
    }

    private function gate(Request $request): void
    {
        abort_unless($request->user()->canAny(['Manage payroll', 'Approve payroll']), 403);
    }

    public function index(Request $request)
    {
        $this->gate($request);
        $staff = StaffProfile::count();
        $paid = StaffPay::where('is_active', true)->count();
        $suggest = (today()->day >= 20 ? today() : today()->subMonthNoOverflow())->format('Y-m');

        return view('payroll.index', [
            'pagetitle' => 'Payroll', 'runs' => PayrollRun::orderByDesc('period')->limit(24)->get(), 'staff' => $staff, 'withPay' => $paid, 'suggest' => $suggest,
            'canManage' => $this->payroll->canManage($request->user()),
        ]);
    }

    public function start(Request $request)
    {
        $data = $request->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        try {
            $run = $this->payroll->start($data['period'], $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('payroll.show', $run);
    }

    public function show(Request $request, PayrollRun $run)
    {
        $this->gate($request);

        return view('payroll.show', [
            'pagetitle' => "Payroll {$run->month_label}", 'run' => $run->load('creator:id,name', 'submitter:id,name', 'approver:id,name', 'payer:id,name', 'expense'),
            'slips' => $run->payslips()->get(), 'svc' => $this->payroll, 'me' => $request->user(),
            'banks' => HotelBankAccount::where('is_active', true)->orderBy('bank_name')->get(),
        ]);
    }

    public function act(Request $request, PayrollRun $run, string $action)
    {
        $this->gate($request);
        $data = $request->validate(['reason' => 'nullable|string|max:300', 'expense' => 'nullable|boolean', 'bank_account_id' => 'nullable|integer']);
        try {
            $msg = match ($action) {
                'recalculate' => tap('Worked out again from attendance and pay.', fn () => $this->payroll->recalculate($run, $request->user())),
                'submit'      => tap('Sent for approval.', fn () => $this->payroll->submit($run, $request->user())),
                'approve'     => tap('Approved. Download the bank schedule and pay, then mark it paid.', fn () => $this->payroll->approve($run, $request->user())),
                'reject'      => ! empty($data['reason']) ? tap('Sent back to draft.', fn () => $this->payroll->reject($run, $request->user(), $data['reason'])) : throw new BookingException('Say why it is being sent back.'),
                'paid'        => tap('Marked paid' . (($data['expense'] ?? true) ? ' and recorded under Expenses.' : '.'), fn () => $this->payroll->markPaid($run, $request->user(), (bool) ($data['expense'] ?? true), $data['bank_account_id'] ?? null)),
                'email'       => ($n = $this->payroll->emailSlips($run, $request->user())) . ' ' . str('payslip')->plural($n) . ' e-mailed.',
                default       => abort(404),
            };
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $msg);
    }

    public function adjust(Request $request, PayrollRun $run, Payslip $payslip)
    {
        abort_unless($payslip->run_id === $run->id, 404);
        $data = $request->validate(['name' => 'required|string|max:80', 'amount' => 'required|numeric|min:0.01|max:100000000', 'kind' => 'required|in:earning,deduction']);
        try {
            $this->payroll->addAdjustment($payslip, $data['name'], (float) $data['amount'], $data['kind'], $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Added to {$payslip->name}'s payslip.");
    }

    public function unadjust(Request $request, PayrollRun $run, Payslip $payslip, int $index)
    {
        abort_unless($payslip->run_id === $run->id, 404);
        try {
            $this->payroll->removeAdjustment($payslip, $index, $request->user());
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Removed.');
    }

    /** The bank schedule as CSV (bulk transfer upload). */
    public function bank(Request $request, PayrollRun $run)
    {
        $this->gate($request);
        abort_unless(in_array($run->status, ['approved', 'paid'], true), 403, 'The bank schedule is ready once the payroll is approved.');
        Audit::record('payroll.bank_schedule', "Downloaded the bank schedule for {$run->number}", $run, by: $request->user());
        $rows = $this->payroll->bankRows($run);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Account name', 'Bank', 'Account number', 'Amount', 'Narration', 'Staff no']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['name'], $r['bank'], $r['account'] !== null ? "\t" . $r['account'] : '', $r['amount'], $r['narration'], $r['staff_no']]);
            }
            fclose($out);
        }, "bank-schedule-{$run->period}.csv", ['Content-Type' => 'text/csv']);
    }

    /** One payslip (or all of them, to print). */
    public function slip(Request $request, PayrollRun $run, ?Payslip $payslip = null)
    {
        $this->gate($request);
        abort_if($payslip && $payslip->run_id !== $run->id, 404);

        return view('payroll.slip', ['run' => $run, 'slips' => $payslip ? collect([$payslip]) : $run->payslips()->get(), 'svc' => $this->payroll]);
    }

    // ── Staff pay ─────────────────────────────────────────────────────

    public function staff(Request $request)
    {
        abort_unless($this->payroll->canManage($request->user()), 403);
        $profiles = StaffProfile::with(['user:id,name,email', 'department:id,name'])->get()->filter(fn ($p) => $p->user)->sortBy(fn ($p) => $p->user->name)->values();

        return view('payroll.staff', ['pagetitle' => 'Staff pay', 'profiles' => $profiles, 'pays' => StaffPay::get()->keyBy('user_id')]);
    }

    public function editStaff(Request $request, User $user)
    {
        abort_unless($this->payroll->canManage($request->user()), 403);

        return view('payroll.staff-edit', ['pagetitle' => 'Pay · ' . $user->name, 'user' => $user, 'pay' => StaffPay::firstOrNew(['user_id' => $user->id]),
            'profile' => StaffProfile::where('user_id', $user->id)->first()]);
    }

    public function saveStaff(Request $request, User $user)
    {
        abort_unless($this->payroll->canManage($request->user()), 403);
        $data = $request->validate([
            'pay_type' => ['required', Rule::in(['monthly', 'daily'])],
            'basic' => 'nullable|numeric|min:0', 'housing' => 'nullable|numeric|min:0', 'transport' => 'nullable|numeric|min:0', 'daily_rate' => 'nullable|numeric|min:0', 'overtime_rate' => 'nullable|numeric|min:0',
            'allowances' => 'nullable|array|max:10', 'allowances.*.name' => 'nullable|string|max:60', 'allowances.*.amount' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|array|max:10', 'deductions.*.name' => 'nullable|string|max:60', 'deductions.*.amount' => 'nullable|numeric|min:0',
            'pension' => 'nullable|boolean', 'nhf' => 'nullable|boolean', 'paye' => 'nullable|boolean', 'annual_rent' => 'nullable|numeric|min:0',
            'bank_name' => 'nullable|string|max:80', 'account_number' => ['nullable', 'regex:/^\d{10}$/'], 'account_name' => 'nullable|string|max:120',
            'tin' => 'nullable|string|max:30', 'pension_pin' => 'nullable|string|max:30', 'pfa' => 'nullable|string|max:80', 'is_active' => 'nullable|boolean',
        ], ['account_number.regex' => 'An account number (NUBAN) is 10 digits.']);
        if ($data['pay_type'] === 'daily' && (float) ($data['daily_rate'] ?? 0) <= 0) {
            return back()->withInput()->with('error', 'Give the daily rate.');
        }
        if ($data['pay_type'] === 'monthly' && (float) ($data['basic'] ?? 0) <= 0) {
            return back()->withInput()->with('error', 'Give the basic salary.');
        }
        $lines = fn ($rows) => collect($rows ?? [])->filter(fn ($r) => trim((string) ($r['name'] ?? '')) !== '' && (float) ($r['amount'] ?? 0) > 0)
            ->map(fn ($r) => ['name' => trim($r['name']), 'amount' => (float) $r['amount']])->values()->all() ?: null;
        $pay = StaffPay::firstOrNew(['user_id' => $user->id]);
        $pay->fill([
            'pay_type' => $data['pay_type'], 'basic' => (float) ($data['basic'] ?? 0), 'housing' => (float) ($data['housing'] ?? 0), 'transport' => (float) ($data['transport'] ?? 0),
            'daily_rate' => $data['daily_rate'] ?? null, 'overtime_rate' => $data['overtime_rate'] ?? null, 'allowances' => $lines($data['allowances'] ?? []), 'deductions' => $lines($data['deductions'] ?? []),
            'pension' => (bool) ($data['pension'] ?? false), 'nhf' => (bool) ($data['nhf'] ?? false), 'paye' => (bool) ($data['paye'] ?? false), 'annual_rent' => $data['annual_rent'] ?? null,
            'bank_name' => $data['bank_name'] ?? null, 'account_name' => $data['account_name'] ?? null,
            'tin' => $data['tin'] ?? null, 'pension_pin' => $data['pension_pin'] ?? null, 'pfa' => $data['pfa'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        if (! empty($data['account_number'])) {
            $pay->account_number = $data['account_number'];   // left empty = keep the one saved
        }
        $changed = array_keys($pay->getDirty());
        $pay->save();
        Audit::record('payroll.staff_pay', "Changed {$user->name}'s pay (" . implode(', ', array_diff($changed, ['account_number'])) . (in_array('account_number', $changed, true) ? ', bank account' : '') . ')', $pay, by: $request->user(), flagged: in_array('account_number', $changed, true));

        return redirect()->route('payroll.staff')->with('success', "{$user->name}'s pay saved.");
    }

    // ── My payslips (everyone) ────────────────────────────────────────

    public function my(Request $request)
    {
        return view('payroll.my', ['pagetitle' => 'My payslips', 'slips' => $this->payroll->mine($request->user())]);
    }

    public function myShow(Request $request, Payslip $payslip)
    {
        $u = $request->user();
        abort_unless($payslip->user_id === $u->id || $u->canAny(['Manage payroll', 'Approve payroll']), 403);
        abort_unless(in_array($payslip->run->status, ['approved', 'paid'], true) || $u->canAny(['Manage payroll', 'Approve payroll']), 404);

        return view('payroll.slip', ['run' => $payslip->run, 'slips' => collect([$payslip]), 'svc' => $this->payroll]);
    }
}
