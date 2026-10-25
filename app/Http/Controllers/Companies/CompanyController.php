<?php

namespace App\Http\Controllers\Companies;

use App\Enums\PaymentMethod;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyLedgerEntry;
use App\Models\Folio;
use App\Models\HotelBankAccount;
use App\Models\Rates\RatePlan;
use App\Services\Companies\CompanyLedger;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Company accounts (city ledger): companies, their statements, invoices, payments and credit. */
class CompanyController extends Controller
{
    public function __construct(private CompanyLedger $ledger)
    {
        $this->middleware('permission:View company accounts', ['only' => ['index', 'show', 'statement', 'invoice']]);
        $this->middleware('permission:Manage company accounts', ['only' => ['create', 'store', 'edit', 'update', 'entry', 'void']]);
        $this->middleware('permission:Receive company payments', ['only' => ['payment']]);
        $this->middleware('permission:Bill to company', ['only' => ['transfer']]);
    }

    public function index(Request $request)
    {
        $q = $request->query('q');
        $show = in_array($request->query('show'), ['owing', 'overdue', 'all', 'closed'], true) ? $request->query('show') : 'all';
        $companies = Company::query()->search($q)
            ->when($show !== 'closed', fn ($x) => $x->where('is_active', true), fn ($x) => $x->where('is_active', false))
            ->withSum(['entries as balance' => fn ($e) => $e->whereNull('voided_at')], 'amount')
            ->withCount(['reservations as stays_count'])
            ->orderBy('name')->get()
            ->each(function (Company $c) {
                $c->balance = round((float) $c->balance, 2);
                $c->ageing = $c->balance > 0 ? $this->ledger->ageing($c) : null;
            });
        if ($show === 'owing') {
            $companies = $companies->filter(fn ($c) => $c->balance >= 0.01)->values();
        } elseif ($show === 'overdue') {
            $companies = $companies->filter(fn ($c) => ($c->ageing['overdue'] ?? 0) >= 0.01)->values();
        }
        $totals = [
            'owed'    => round($companies->sum('balance'), 2),
            'overdue' => round($companies->sum(fn ($c) => $c->ageing['overdue'] ?? 0), 2),
            'count'   => $companies->count(),
            'over'    => $companies->filter(fn ($c) => (float) $c->credit_limit > 0 && $c->balance > (float) $c->credit_limit)->count(),
        ];
        $pagetitle = 'Company Accounts';

        return view('companies.index', compact('pagetitle', 'companies', 'totals', 'q', 'show'));
    }

    public function create()
    {
        return view('companies.form', ['pagetitle' => 'New Company Account', 'company' => new Company()]);
    }

    public function store(Request $request)
    {
        $company = $this->ledger->create($this->validated($request));

        return redirect()->route('companies.show', $company)->with('success', "{$company->name}'s account {$company->account_number} is open.");
    }

    public function edit(Company $company)
    {
        return view('companies.form', ['pagetitle' => 'Edit ' . $company->name, 'company' => $company]);
    }

    public function update(Request $request, Company $company)
    {
        $company->fill($this->validated($request, $company) + ['is_active' => $request->boolean('is_active')]);
        [$old, $new] = Audit::diff($company);
        $company->save();
        if ($new) {
            Audit::record('companies.changed', "Changed company account {$company->account_number} ({$company->name})", $company, $old, $new);
        }

        return redirect()->route('companies.show', $company)->with('success', 'Saved.');
    }

    public function show(Request $request, Company $company)
    {
        $balance = $company->balance();
        $ageing = $this->ledger->ageing($company);
        $entries = $company->entries()->with(['reservation.guest', 'creator', 'voider'])->latest('entry_date')->latest('id')->paginate(25)->withQueryString();
        $stays = $company->reservations()->with('guest')->latest('check_in_date')->limit(8)->get();
        $people = $company->guests()->orderBy('first_name')->limit(30)->get();
        $plans = RatePlan::where('company_id', $company->id)->get();
        $pagetitle = $company->name;

        return view('companies.show', compact('pagetitle', 'company', 'balance', 'ageing', 'entries', 'stays', 'people', 'plans'));
    }

    /** A statement for a period, to print or save as PDF and send. */
    public function statement(Request $request, Company $company)
    {
        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->startOfDay() : today();
        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : $to->copy()->subMonthNoOverflow()->startOfMonth();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $statement = $this->ledger->statement($company, $from, $to);
        $ageing = $this->ledger->ageing($company, $to);
        $bankAccounts = HotelBankAccount::forInvoices();

        return view('companies.statement', compact('company', 'from', 'to', 'statement', 'ageing', 'bankAccounts'));
    }

    /** One invoice: for a moved bill, the guest's charges as they were on the bill. */
    public function invoice(Company $company, CompanyLedgerEntry $entry)
    {
        abort_unless($entry->company_id === $company->id && $entry->type === 'invoice', 404);
        $entry->load(['reservation.guest', 'reservation.rooms.room', 'folio']);
        $charges = $entry->folio ? $entry->folio->charges()->whereNull('voided_at')->orderBy('service_date')->orderBy('id')->get() : collect();
        $otherPaid = $entry->folio ? round((float) $entry->folio->payments()->whereNull('voided_at')->where('id', '!=', $entry->payment_id)->get()
            ->sum(fn ($p) => $p->kind->value === 'refund' ? -(float) $p->amount : (float) $p->amount), 2) : 0;
        $bankAccounts = HotelBankAccount::forInvoices();

        return view('companies.invoice', compact('company', 'entry', 'charges', 'otherPaid', 'bankAccounts'));
    }

    public function payment(Request $request, Company $company)
    {
        $data = $request->validate([
            'amount'    => 'required|numeric|min:0.01|max:1000000000',
            'method'    => ['required', Rule::in(array_map(fn ($m) => $m->value, PaymentMethod::takeable()))],
            'reference' => 'nullable|string|max:100',
            'date'      => 'nullable|date|before_or_equal:today',
            'note'      => 'nullable|string|max:150',
        ]);
        try {
            $entry = $this->ledger->receivePayment($company, (float) $data['amount'], PaymentMethod::from($data['method']), $data['reference'] ?? null, $data['date'] ?? null, $data['note'] ?? null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment ' . $entry->number . ' of ' . $this->ledger->money((float) $data['amount']) . ' recorded.');
    }

    /** A credit note or an adjustment. */
    public function entry(Request $request, Company $company)
    {
        $data = $request->validate([
            'type'   => 'required|in:credit,adjustment',
            'amount' => 'required|numeric|between:-1000000000,1000000000|not_in:0',
            'reason' => 'required|string|max:200',
        ]);
        try {
            $entry = $this->ledger->adjust($company, $data['type'], (float) $data['amount'], $data['reason']);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$entry->type_label} {$entry->number} added.");
    }

    public function void(Request $request, Company $company, CompanyLedgerEntry $entry)
    {
        abort_unless($entry->company_id === $company->id, 404);
        $data = $request->validate(['reason' => 'required|string|max:200']);
        try {
            $this->ledger->void($entry, $data['reason']);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$entry->number} voided." . ($entry->payment_id ? ' The amount is back on the guest’s bill.' : ''));
    }

    /** From a guest's bill: move what is owed (or part of it) to a company. */
    public function transfer(Request $request, Folio $folio)
    {
        $data = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'amount'     => 'required|numeric|min:0.01|max:1000000000',
            'note'       => 'nullable|string|max:150',
        ]);
        $company = Company::findOrFail($data['company_id']);
        try {
            $entry = $this->ledger->transferFolio($folio, $company, (float) $data['amount'], $data['note'] ?? null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $this->ledger->money((float) $data['amount']) . " moved to {$company->name}'s account (invoice {$entry->number}).");
    }

    private function validated(Request $request, ?Company $company = null): array
    {
        $data = $request->validate([
            'name'               => ['required', 'string', 'max:150', Rule::unique('companies', 'name')->ignore($company?->id)],
            'contact_name'       => 'nullable|string|max:120',
            'email'              => 'nullable|email|max:150',
            'phone'              => 'nullable|string|max:40',
            'address'            => 'nullable|string|max:500',
            'tax_number'         => 'nullable|string|max:40',
            'credit_limit'       => 'nullable|numeric|min:0|max:10000000000',
            'payment_terms_days' => 'required|integer|min:0|max:365',
            'notes'              => 'nullable|string|max:1000',
        ]);
        $data['credit_limit'] = (float) ($data['credit_limit'] ?? 0);

        return $data;
    }
}
