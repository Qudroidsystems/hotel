<?php

namespace App\Services\Mobile\Modules;

use App\Exceptions\BookingException;
use App\Models\Finance\Expense;
use App\Models\Finance\ExpenseCategory;
use App\Models\User;
use App\Services\Finance\ExpenseService;
use Illuminate\Database\Eloquent\Model;

/**
 * Record a running cost with a photo of the receipt where it happens; follow
 * your own. Approving is on the Approvals tab (it already lists expenses).
 */
class ExpensesModule extends StaffModule
{
    public function __construct(private ExpenseService $expenses)
    {
    }

    public function key(): string { return 'expenses'; }
    public function title(): string { return 'Expenses'; }
    public function icon(): string { return 'receipt_long'; }
    public function section(): string { return 'Money'; }
    public function blurb(): string { return 'Record a cost with its receipt'; }

    public function allowed(User $user): bool
    {
        return $user->canAny(['Record expenses', 'View expenses', 'Approve expenses', 'Pay expenses']);
    }

    private function seesAll(User $user): bool
    {
        return $user->canAny(['View expenses', 'Approve expenses', 'Pay expenses']);
    }

    public function tabs(User $user): array
    {
        return array_values(array_filter([
            ['key' => 'mine', 'label' => 'Mine'],
            $this->seesAll($user) ? ['key' => 'all', 'label' => 'Everyone'] : null,
        ]));
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        return Expense::with('category')
            ->when($tab !== 'all' || ! $this->seesAll($user), fn ($q) => $q->where('recorded_by', $user->id))
            ->latest('id')->limit(60)->get()->map(fn (Expense $e) => $this->item($user, $e))->all();
    }

    public function find(int $id): ?Model
    {
        return Expense::with('category')->find($id);
    }

    public function item(User $user, Model $e): array
    {
        /** @var Expense $e */
        abort_unless($this->seesAll($user) || $e->recorded_by === $user->id, 403);
        [$label, $pill] = Expense::STATUSES[$e->status] ?? [ucfirst($e->status), 'st-muted'];

        return [
            'id' => $e->id,
            'title' => $e->description,
            'subtitle' => trim(($e->number ?? '') . ' · ' . ($e->category?->name ?? '') . ' · ' . $e->expense_date?->format('j M')),
            'badge' => ['label' => $label, 'tone' => self::tone($pill)],
            'amount' => self::money($e->amount),
            'lines' => self::lines(['Paid to' => $e->payee ?? $e->supplier?->name, 'Invoice' => $e->invoice_ref, 'Department' => $e->department, 'Notes' => $e->notes,
                'Receipts' => count($e->attachments ?? []) ?: null]),
            'actions' => [],
        ];
    }

    public function actions(User $user, Model $e): array
    {
        return [];
    }

    public function perform(User $user, Model $e, string $action, array $input, array $files): string
    {
        throw new BookingException('Approve expenses on the Approvals tab.');
    }

    public function form(User $user): ?array
    {
        if (! $user->can('Record expenses')) {
            return null;
        }

        return ['title' => 'Record an expense', 'submit' => 'Record', 'fields' => [
            self::field('description', 'What for', 'text', ['required' => true]),
            self::field('category_id', 'Category', 'select', ['required' => true, 'options' => ExpenseCategory::where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->name])->all()]),
            self::field('amount', 'Amount', 'money', ['required' => true, 'min' => 0.01, 'max' => 1000000000]),
            self::field('tax_amount', 'VAT included (if any)', 'money'),
            self::field('expense_date', 'Date', 'date', ['required' => true, 'value' => today()->toDateString()]),
            self::field('payee', 'Paid to', 'text', ['max' => 150]),
            self::field('invoice_ref', 'Invoice / receipt number', 'text', ['max' => 60]),
            self::field('notes', 'Notes', 'textarea', ['max' => 1000]),
            self::field('photos', 'Receipt photos', 'photos'),
        ]];
    }

    public function create(User $user, array $input, array $files): array
    {
        abort_unless($user->can('Record expenses'), 403);
        $date = \Illuminate\Support\Carbon::parse($input['expense_date']);
        if ($date->gt(today()->addDays(31)) || $date->lt(today()->subYears(2))) {
            throw new BookingException('Check the date.');
        }
        $input['expense_date'] = $date->toDateString();
        $e = $this->expenses->record($input, array_values(array_filter((array) ($files['photos'] ?? []))), $user);

        return [match ($e->status) {
            'approved' => "{$e->number} recorded — within the approval limit, so it’s approved.",
            'paid' => "{$e->number} recorded and paid.",
            default => "{$e->number} recorded and sent for approval.",
        }, $e];
    }

    public function emptyText(?string $tab): string
    {
        return 'No expenses yet.';
    }
}
