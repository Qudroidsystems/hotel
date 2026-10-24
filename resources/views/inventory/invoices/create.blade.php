@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-bill-line" subtitle="Enter the bill exactly as the supplier wrote it. It's checked against the delivery it charges for."
               :back="route('inventory.invoices.index')" back-label="Supplier bills" />
    @include('rooms.partials.flash')

    <div class="row g-3">
        <div class="col-xl-7">
            <form method="POST" action="{{ route('inventory.invoices.store') }}" class="cb-card p-3 rm-form row g-3" data-once>
                @csrf
                <div class="col-12"><label for="siReceipt">Delivery (goods received note)</label>
                    <select name="stock_receipt_id" id="siReceipt" class="form-select">
                        <option value="">Not linked to a delivery</option>
                        @if ($receipt && ! $receipts->contains('id', $receipt->id))<option value="{{ $receipt->id }}" data-total="{{ $receipt->total }}" data-inv="{{ $receipt->supplier_invoice }}" selected>{{ $receipt->number }} · {{ $receipt->supplier?->name }} · @money($receipt->total)</option>@endif
                        @foreach ($receipts as $r)<option value="{{ $r->id }}" data-total="{{ $r->total }}" data-inv="{{ $r->supplier_invoice }}" @selected((int) old('stock_receipt_id', $receipt?->id) === $r->id)>{{ $r->number }} · {{ $r->supplier?->name }} · {{ $r->received_on->format('j M') }} · @money($r->total)</option>@endforeach
                    </select>
                    <div class="form-text">The supplier comes from the delivery. Only pick a supplier below for bills with no delivery (services, repairs…).</div></div>
                <div class="col-md-6" id="siSupWrap"><label for="siSup">Supplier</label>
                    <select name="supplier_id" id="siSup" class="form-select"><option value="">Choose…</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected((int) old('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                <div class="col-md-6"><label for="siNo">Supplier's invoice number</label><input name="invoice_number" id="siNo" class="form-control" maxlength="60" required value="{{ old('invoice_number', $receipt?->supplier_invoice) }}"></div>
                <div class="col-md-4"><label for="siDate">Invoice date</label><input type="date" name="invoice_date" id="siDate" class="form-control" required max="{{ today()->toDateString() }}" value="{{ old('invoice_date', ($receipt?->received_on ?? today())->toDateString()) }}"></div>
                <div class="col-md-4"><label for="siDue">Due date</label><input type="date" name="due_date" id="siDue" class="form-control" value="{{ old('due_date') }}"><div class="form-text">Blank = supplier's payment terms.</div></div>
                <div class="col-md-4"><label for="siAmt">Amount</label><div class="input-group"><span class="input-group-text">{{ config('hotel.currency_symbol') }}</span><input type="number" name="amount" id="siAmt" class="form-control" min="0.01" step="0.01" required value="{{ old('amount', $receipt?->total) }}"></div>
                    <div class="form-text" id="siCheck"></div></div>
                <div class="col-12"><label for="siNotes">Notes</label><input name="notes" id="siNotes" class="form-control" maxlength="500" value="{{ old('notes') }}"></div>
                <div class="col-12 d-flex justify-content-end gap-2"><a href="{{ route('inventory.invoices.index') }}" class="btn btn-light">Cancel</a><button class="btn btn-primary"><i class="ri-save-line"></i> Record invoice</button></div>
            </form>
        </div>
    </div>
</div>
</div>
</div>
<script>
(function () {
    const rec = document.getElementById('siReceipt'), sup = document.getElementById('siSupWrap'), amt = document.getElementById('siAmt'), check = document.getElementById('siCheck');
    const sym = @json(config('hotel.currency_symbol')), tol = @json((float) config('hotel.procurement.invoice_tolerance'));
    function sync() {
        const o = rec.selectedOptions[0], total = o && o.value ? parseFloat(o.dataset.total) : null;
        sup.classList.toggle('d-none', !!(o && o.value));
        if (total === null) { check.textContent = ''; return; }
        const diff = (parseFloat(amt.value) || 0) - total;
        check.textContent = Math.abs(diff) <= tol ? 'Matches the goods received.' : sym + Math.abs(diff).toLocaleString('en-NG', { minimumFractionDigits: 2 }) + (diff > 0 ? ' more' : ' less') + ' than the goods received (' + sym + total.toLocaleString('en-NG', { minimumFractionDigits: 2 }) + ').';
        check.className = 'form-text ' + (Math.abs(diff) <= tol ? 'text-success' : 'text-danger fw-semibold');
    }
    rec.addEventListener('change', () => { const o = rec.selectedOptions[0]; if (o && o.value) { amt.value = o.dataset.total; if (o.dataset.inv && !document.getElementById('siNo').value) document.getElementById('siNo').value = o.dataset.inv; } sync(); });
    amt.addEventListener('input', sync); sync();
})();
</script>
@endsection
