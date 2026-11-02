<?php

namespace App\Http\Controllers\Finance;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseCategory;
use App\Models\HotelBankAccount;
use App\Models\Inventory\Supplier;
use App\Models\User;
use App\Services\Billing\CashShiftService;
use App\Services\Finance\ExpenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Running costs (spec §9, §14). People with only "Record expenses" see what
 * they recorded; "View / Approve / Pay expenses" see everything.
 */
class ExpenseController extends Controller
{
    private const ANY = 'permission:Record expenses|View expenses|Approve expenses|Pay expenses';

    public function __construct(private ExpenseService $service)
    {
        $this->middleware(self::ANY);
        $this->middleware('permission:Record expenses')->only(['store']);
        $this->middleware('permission:Approve expenses')->only(['approve', 'reject', 'void']);
        $this->middleware('permission:Pay expenses')->only(['pay']);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $seeAll = $this->seesAll($user);
        $month = $this->month($request->query('month'));
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $status = array_key_exists((string) $request->query('status'), Expense::STATUSES) || $request->query('status') === 'open' ? $request->query('status') : null;
        $categoryId = $request->integer('category') ?: null;
        $department = $request->query('department') ?: null;
        $q = trim((string) $request->query('q'));
        $allTime = in_array($status, ['pending', 'approved', 'open'], true);   // the to-do lists aren't limited to one month

        $expenses = Expense::with(['category:id,name', 'supplier:id,name', 'recorder:id,name'])
            ->when(! $seeAll, fn ($w) => $w->where('recorded_by', $user->id))
            ->when(! $allTime, fn ($w) => $w->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()]))
            ->when($status === 'open', fn ($w) => $w->whereIn('status', ['pending', 'approved']))
            ->when($status && $status !== 'open', fn ($w) => $w->where('status', $status))
            ->when($categoryId, fn ($w) => $w->where('category_id', $categoryId))
            ->when($department, fn ($w) => $w->where('department', $department))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('description', 'like', "%{$q}%")->orWhere('number', 'like', "%{$q}%")
                ->orWhere('payee', 'like', "%{$q}%")->orWhere('invoice_ref', 'like', "%{$q}%")))
            ->orderByDesc('expense_date')->orderByDesc('id')->paginate(40)->withQueryString();

        // Month totals by category and department, against budgets.
        $monthRows = Expense::counted()->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->when(! $seeAll, fn ($w) => $w->where('recorded_by', $user->id))->get(['category_id', 'department', 'amount', 'tax_amount', 'status']);
        $categories = ExpenseCategory::ordered()->get();
        $budget = $categories->map(fn ($c) => (object) [
            'category' => $c,
            'spent'    => (float) $monthRows->where('category_id', $c->id)->sum('amount'),
            'budget'   => $c->monthly_budget !== null ? (float) $c->monthly_budget : null,
        ])->filter(fn ($r) => $r->spent > 0 || $r->budget)->sortByDesc('spent')->values();
        $byDepartment = $monthRows->groupBy(fn ($e) => $e->department ?: 'Not given')->map(fn ($g) => (float) $g->sum('amount'))->sortDesc();

        $open = Expense::whereIn('status', ['pending', 'approved'])->when(! $seeAll, fn ($w) => $w->where('recorded_by', $user->id))->get(['status', 'amount', 'recorded_by']);
        $stats = [
            'month'        => (float) $monthRows->sum('amount'),
            'budget'       => (float) $categories->sum('monthly_budget'),
            'pending'      => $open->where('status', 'pending')->count(),
            'pending_sum'  => (float) $open->where('status', 'pending')->sum('amount'),
            'to_approve'   => $user->can('Approve expenses') ? $open->where('status', 'pending')->where('recorded_by', '!=', $user->id)->count() : 0,
            'unpaid'       => $open->where('status', 'approved')->count(),
            'unpaid_sum'   => (float) $open->where('status', 'approved')->sum('amount'),
        ];

        $pagetitle = $seeAll ? 'Expenses' : 'My Expenses';
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $departments = (array) config('hotel.expenses.departments', []);
        $accounts = HotelBankAccount::ordered()->where('is_active', true)->get();
        $shift = app(CashShiftService::class)->current($user);

        return view('finance.expenses.index', compact('pagetitle', 'expenses', 'month', 'status', 'categoryId', 'department', 'q', 'seeAll', 'budget', 'byDepartment', 'stats', 'categories', 'suppliers', 'departments', 'accounts', 'shift'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['pay_now'] = $request->boolean('pay_now') && $request->user()->can('Pay expenses');
        if ($data['pay_now']) {
            $data += $request->validate($this->payRules());
            $data['from_till'] = $request->boolean('from_till');
        }

        try {
            $expense = $this->service->record($data, $request->file('attachments', []), $request->user());
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $msg = match ($expense->status) {
            'paid'     => "{$expense->number} recorded and paid.",
            'approved' => "{$expense->number} recorded — within the approval limit, so it is approved and ready to pay.",
            default    => "{$expense->number} recorded and sent for approval (over " . config('hotel.currency_symbol') . number_format((float) config('hotel.expenses.approval_limit'), 2) . ').',
        };

        return redirect()->route('expenses.show', $expense)->with('success', $msg);
    }

    public function show(Request $request, Expense $expense)
    {
        $user = $request->user();
        abort_unless($this->seesAll($user) || $expense->recorded_by === $user->id, 403);
        $expense->load(['category', 'supplier', 'recorder', 'approver', 'payer', 'voider', 'bankAccount', 'cashShift']);
        $categories = ExpenseCategory::ordered()->get();
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $departments = (array) config('hotel.expenses.departments', []);
        $accounts = HotelBankAccount::ordered()->where('is_active', true)->get();
        $shift = app(CashShiftService::class)->current($user);
        $canEdit = in_array($expense->status, ['pending', 'approved'], true) && ($expense->recorded_by === $user->id || $user->can('Approve expenses'));
        $pagetitle = $expense->number;

        return view('finance.expenses.show', compact('pagetitle', 'expense', 'categories', 'suppliers', 'departments', 'accounts', 'shift', 'canEdit'));
    }

    public function update(Request $request, Expense $expense)
    {
        $user = $request->user();
        abort_unless(($expense->recorded_by === $user->id && $user->can('Record expenses')) || $user->can('Approve expenses'), 403);

        return $this->attempt(fn () => $this->service->update($expense, $this->validated($request), $user), 'Expense updated.');
    }

    public function approve(Request $request, Expense $expense)
    {
        return $this->attempt(fn () => $this->service->approve($expense, $request->user()), "{$expense->number} approved.");
    }

    public function reject(Request $request, Expense $expense)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->service->reject($expense, $request->user(), $data['reason']), "{$expense->number} rejected.");
    }

    public function pay(Request $request, Expense $expense)
    {
        $data = $request->validate($this->payRules()) + ['from_till' => $request->boolean('from_till')];

        return $this->attempt(fn () => $this->service->pay($expense, $data, $request->user()), "{$expense->number} marked paid.");
    }

    public function void(Request $request, Expense $expense)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->service->void($expense, $request->user(), $data['reason']),
            "{$expense->number} voided." . ($expense->cash_shift_id ? ' It was paid from a till — if the cash came back, record a paid-in on that shift.' : ''));
    }

    public function attach(Request $request, Expense $expense)
    {
        $user = $request->user();
        abort_unless($expense->recorded_by === $user->id || $user->canAny(['Approve expenses', 'Pay expenses']), 403);
        $request->validate(['attachments' => 'required|array|max:5', 'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:5120']);
        abort_if(count($expense->attachments ?? []) + count($request->file('attachments')) > 8, 422, 'At most 8 files per expense.');
        $this->service->attach($expense, $request->file('attachments'));

        return back()->with('success', 'Receipt added.');
    }

    /** Receipts are private: streamed only to people who can see the expense. */
    public function file(Request $request, Expense $expense, int $index)
    {
        $user = $request->user();
        abort_unless($this->seesAll($user) || $expense->recorded_by === $user->id, 403);
        $path = ($expense->attachments ?? [])[$index] ?? null;
        abort_unless($path && Storage::disk(ExpenseService::DISK)->exists($path), 404);

        return Storage::disk(ExpenseService::DISK)->response($path, $expense->number . '-' . ($index + 1) . '.' . pathinfo($path, PATHINFO_EXTENSION));
    }

    // ------------------------------------------------------------------

    private function validated(Request $request): array
    {
        return $request->validate([
            'category_id'   => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'department'    => 'nullable|string|max:80',
            'description'   => 'required|string|max:255',
            'supplier_id'   => 'nullable|integer|exists:suppliers,id',
            'payee'         => 'nullable|string|max:150',
            'amount'        => 'required|numeric|min:0.01|max:1000000000',
            'tax_amount'    => 'nullable|numeric|min:0|max:1000000000',
            'expense_date'  => 'required|date|before_or_equal:' . today()->addDays(31)->toDateString() . '|after:' . today()->subYears(2)->toDateString(),
            'invoice_ref'   => 'nullable|string|max:60',
            'notes'         => 'nullable|string|max:1000',
            'attachments'   => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ], ['attachments.*.mimes' => 'Receipts must be photos (JPG, PNG, WebP) or PDFs.', 'attachments.*.max' => 'Each receipt must be under 5 MB.']);
    }

    private function payRules(): array
    {
        return [
            'method'          => ['required', Rule::in(array_keys(Expense::METHODS))],
            'bank_account_id' => 'nullable|integer|exists:hotel_bank_accounts,id',
            'payment_ref'     => 'nullable|string|max:100',
        ];
    }

    private function seesAll(User $user): bool
    {
        return $user->canAny(['View expenses', 'Approve expenses', 'Pay expenses']);
    }

    private function month(?string $value): Carbon
    {
        try {
            return $value ? Carbon::createFromFormat('!Y-m', $value)->startOfMonth() : today()->startOfMonth();
        } catch (\Throwable) {
            return today()->startOfMonth();
        }
    }

    private function attempt(callable $fn, string $ok)
    {
        try {
            $fn();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }
}
