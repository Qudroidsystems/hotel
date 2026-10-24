<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\HotelBankAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The hotel's bank accounts (shown on invoices, chosen when a transfer comes in). */
class BankAccountController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage hotel settings');
    }

    public function index()
    {
        $accounts = HotelBankAccount::ordered()->withCount(['payments' => fn ($q) => $q->whereNull('voided_at')])
            ->withSum(['payments as received' => fn ($q) => $q->whereNull('voided_at')->where('kind', '!=', 'refund')], 'amount')
            ->get();
        $pagetitle = 'Bank Accounts';

        return view('settings.bank-accounts', compact('pagetitle', 'accounts'));
    }

    public function store(Request $request)
    {
        HotelBankAccount::create($this->validated($request));

        return back()->with('success', 'Bank account added.');
    }

    public function update(Request $request, HotelBankAccount $account)
    {
        $account->update($this->validated($request, $account));

        return back()->with('success', 'Bank account updated.');
    }

    public function destroy(HotelBankAccount $account)
    {
        // Accounts that have received money stay on record for reconciliation.
        if ($account->payments()->exists()) {
            $account->update(['is_active' => false]);

            return back()->with('success', 'This account has payments recorded against it, so it was switched off instead of deleted.');
        }
        $account->delete();

        return back()->with('success', 'Bank account deleted.');
    }

    private function validated(Request $request, ?HotelBankAccount $account = null): array
    {
        $data = $request->validate([
            'bank_name'      => 'required|string|max:100',
            'account_name'   => 'required|string|max:150',
            'account_number' => ['required', 'string', 'max:30', 'regex:/^[0-9A-Za-z \-]+$/',
                Rule::unique('hotel_bank_accounts')->where('bank_name', $request->input('bank_name'))->ignore($account?->id)],
            'branch'         => 'nullable|string|max:100',
            'sort_code'      => 'nullable|string|max:30',
            'currency'       => 'required|string|size:3',
            'purpose'        => 'nullable|string|max:100',
            'sort_order'     => 'nullable|integer|min:0|max:999',
        ], ['account_number.unique' => 'That account is already on the list.']);

        $data['currency'] = strtoupper($data['currency']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        foreach (['show_on_invoice', 'accepts_transfers', 'is_active'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        return $data;
    }
}
