{{-- Expense fields. Expects $categories, $suppliers, $departments; optional $e (existing expense). --}}
@php $e = $e ?? null; $sym = config('hotel.currency_symbol'); @endphp
<div class="col-sm-6"><label for="x_category">Category <span class="text-danger">*</span></label>
    <select name="category_id" id="x_category" class="form-select" required><option value="">Choose…</option>
        @foreach ($categories->where('is_active', true)->groupBy('group') as $g => $cats)
            <optgroup label="{{ \App\Models\Finance\ExpenseCategory::GROUPS[$g] ?? $g }}">@foreach ($cats as $c)<option value="{{ $c->id }}" @selected(old('category_id', $e?->category_id) == $c->id)>{{ $c->name }}</option>@endforeach</optgroup>
        @endforeach
    </select></div>
<div class="col-sm-6"><label for="x_department">Department</label>
    <select name="department" id="x_department" class="form-select"><option value="">—</option>@foreach ($departments as $d)<option value="{{ $d }}" @selected(old('department', $e?->department) === $d)>{{ $d }}</option>@endforeach
        @if ($e?->department && ! in_array($e->department, $departments, true))<option value="{{ $e->department }}" selected>{{ $e->department }}</option>@endif</select></div>
<div class="col-12"><label for="x_description">What for <span class="text-danger">*</span></label><input name="description" id="x_description" class="form-control" maxlength="255" required value="{{ old('description', $e?->description) }}" placeholder="e.g. 400 litres diesel for generator"></div>
<div class="col-sm-6"><label for="x_supplier">Paid to (supplier)</label>
    <select name="supplier_id" id="x_supplier" class="form-select"><option value="">Someone else…</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id', $e?->supplier_id) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
<div class="col-sm-6"><label for="x_payee">…or payee name</label><input name="payee" id="x_payee" class="form-control" maxlength="150" value="{{ old('payee', $e?->payee) }}" placeholder="e.g. EKEDC, Total filling station"></div>
<div class="col-sm-4"><label for="x_amount">Amount paid <span class="text-danger">*</span></label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" name="amount" id="x_amount" class="form-control" min="0.01" step="0.01" required value="{{ old('amount', $e?->amount) }}"></div></div>
<div class="col-sm-4"><label for="x_tax">VAT included</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" name="tax_amount" id="x_tax" class="form-control" min="0" step="0.01" value="{{ old('tax_amount', $e?->tax_amount ?? 0) }}"></div><div class="form-text">Only if the invoice shows VAT.</div></div>
<div class="col-sm-4"><label for="x_date">Date of the cost <span class="text-danger">*</span></label><input type="date" name="expense_date" id="x_date" class="form-control" required value="{{ old('expense_date', $e?->expense_date?->toDateString() ?? today()->toDateString()) }}"></div>
<div class="col-sm-6"><label for="x_ref">Invoice / receipt number</label><input name="invoice_ref" id="x_ref" class="form-control" maxlength="60" value="{{ old('invoice_ref', $e?->invoice_ref) }}"></div>
<div class="col-sm-6"><label for="x_notes">Notes</label><input name="notes" id="x_notes" class="form-control" maxlength="1000" value="{{ old('notes', $e?->notes) }}"></div>
