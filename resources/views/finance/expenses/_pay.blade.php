{{-- Payment fields. Expects $accounts, $shift (the user's open cash shift or null). --}}
<div class="col-sm-4"><label for="p_method">Paid by</label>
    <select name="method" id="p_method" class="form-select">@foreach (\App\Models\Finance\Expense::METHODS as $k => $label)<option value="{{ $k }}" @selected(old('method', 'transfer') === $k)>{{ $label }}</option>@endforeach</select></div>
<div class="col-sm-4 p-bank"><label for="p_account">From account</label>
    <select name="bank_account_id" id="p_account" class="form-select"><option value="">—</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('bank_account_id') == $a->id)>{{ $a->bank_name }} {{ $a->account_number }}</option>@endforeach</select></div>
<div class="col-sm-4"><label for="p_ref">Payment reference</label><input name="payment_ref" id="p_ref" class="form-control" maxlength="100" value="{{ old('payment_ref') }}" placeholder="Transfer ref, cheque no."></div>
<div class="col-12 p-cash" style="display:none">
    @if ($shift)
        <label class="form-check"><input type="checkbox" class="form-check-input" name="from_till" value="1" checked> <span class="form-check-label">Taken from my till (shift {{ $shift->number }}, {{ $shift->station }}) — books a paid-out so the cash-up balances</span></label>
    @else
        <div class="small text-muted">Paid from the safe or petty cash box. (Open a cash shift first if it comes out of a till.)</div>
    @endif
</div>
