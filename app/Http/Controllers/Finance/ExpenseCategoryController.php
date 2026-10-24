<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseCategory;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Expense categories and their monthly budgets. */
class ExpenseCategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage expense categories');
    }

    public function index()
    {
        $start = today()->startOfMonth();
        $categories = ExpenseCategory::ordered()->withCount('expenses')
            ->withSum(['expenses as month_spent' => fn ($q) => $q->counted()->where('expense_date', '>=', $start->toDateString())], 'amount')
            ->withSum(['expenses as year_spent' => fn ($q) => $q->counted()->where('expense_date', '>=', today()->startOfYear()->toDateString())], 'amount')
            ->get();
        $pagetitle = 'Expense Categories & Budgets';

        return view('finance.expenses.categories', compact('pagetitle', 'categories'));
    }

    public function store(Request $request)
    {
        $c = ExpenseCategory::create($this->validated($request) + ['is_active' => true]);
        Audit::record('finance.category_added', "Added expense category {$c->name}" . ($c->monthly_budget ? ' with a monthly budget of ' . config('hotel.currency_symbol') . number_format((float) $c->monthly_budget, 2) : ''), $c);

        return back()->with('success', "{$c->name} added.");
    }

    public function update(Request $request, ExpenseCategory $category)
    {
        $category->fill($this->validated($request, $category) + ['is_active' => $request->boolean('is_active')]);
        [$old, $new] = Audit::diff($category);
        $category->save();
        if ($new) {
            Audit::record('finance.category_changed', "Changed expense category {$category->name}", $category, $old, $new);
        }

        return back()->with('success', "{$category->name} saved.");
    }

    private function validated(Request $request, ?ExpenseCategory $c = null): array
    {
        $data = $request->validate([
            'name'           => ['required', 'string', 'max:80', Rule::unique('expense_categories', 'name')->ignore($c?->id)],
            'group'          => ['required', Rule::in(array_keys(ExpenseCategory::GROUPS))],
            'monthly_budget' => 'nullable|numeric|min:0|max:1000000000',
            'sort_order'     => 'nullable|integer|min:0|max:999',
        ]);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['monthly_budget'] = isset($data['monthly_budget']) && $data['monthly_budget'] !== '' ? $data['monthly_budget'] : null;

        return $data;
    }
}
