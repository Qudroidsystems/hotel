@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $inv = $invoice; $sym = config('hotel.currency_symbol'); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="'Invoice ' . $inv->invoice_number" icon="ri-bill-line" :subtitle="$inv->supplier->name . ' · ' . $inv->number . ' · dated ' . $inv->invoice_date->format('j M Y')"
               :back="route('inventory.invoices.index')" back-label="Supplier bills">
        <x-slot:pills><span class="cb-meta-pill">{{ $inv->status_label }}</span><span class="cb-meta-pill"><i class="ri-calendar-line"></i> Due {{ $inv->due_date->format('j M Y') }}</span></x-slot:pills>
    </x-cb.hero>
    @include('rooms.partials.flash')
    @if ($inv->isOverdue())<div class="cb-banner danger"><i class="ri-alarm-warning-line"></i><div>Overdue by {{ $inv->due_date->diffInDays(today()) }} days.</div></div>@endif
    @if ($inv->status === 'void')<div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Void: {{ $inv->void_reason }}</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="Check against the order and delivery" icon="ri-scales-3-line">
                <div class="row g-3 text-center">
                    <div class="col-4"><div class="small text-muted">Ordered</div><div class="fs-5 fw-semibold">{{ $match['ordered'] !== null ? $sym . number_format($match['ordered'], 2) : '—' }}</div>
                        @if ($inv->purchaseOrder)<a class="small" href="{{ route('inventory.po.show', $inv->purchaseOrder) }}">{{ $inv->purchaseOrder->number }}</a>@endif</div>
                    <div class="col-4"><div class="small text-muted">Received</div><div class="fs-5 fw-semibold">{{ $match['received'] !== null ? $sym . number_format($match['received'], 2) : '—' }}</div>
                        @if ($inv->receipt)<a class="small" href="{{ route('inventory.receipts.show', $inv->receipt) }}">{{ $inv->receipt->number }}</a>@endif</div>
                    <div class="col-4"><div class="small text-muted">Invoiced</div><div class="fs-5 fw-bold">@money($inv->amount)</div></div>
                </div>
                @if ($match['received'] === null)
                    <div class="cb-banner info mt-3 mb-0"><i class="ri-information-line"></i><div>Not linked to a delivery, so it can't be checked against goods received.</div></div>
                @elseif ($match['mismatch'])
                    <div class="cb-banner danger mt-3 mb-0"><i class="ri-error-warning-line"></i><div>The bill is @money(abs($match['difference'])) {{ $match['difference'] > 0 ? 'more' : 'less' }} than the goods received. Check it with the supplier before paying.</div></div>
                @else
                    <div class="cb-banner success mt-3 mb-0"><i class="ri-check-line"></i><div>The bill matches the goods received.</div></div>
                @endif
                @if ($inv->receipt && $inv->receipt->lines->where('rejected_quantity', '>', 0)->isNotEmpty())
                    <div class="small mt-3"><strong>Refused at delivery</strong> (should not be on the bill):
                        @foreach ($inv->receipt->lines->where('rejected_quantity', '>', 0) as $l)<div>{{ \App\Models\Inventory\StockItem::qty($l->rejected_quantity) }} {{ $l->item->unit }} {{ $l->item->name }} — {{ $l->reject_reason }}</div>@endforeach</div>
                @endif
            </x-cb.card>

            <x-cb.card title="Payments" icon="ri-hand-coin-line" :count="$inv->payments->count()">
                @forelse ($inv->payments as $p)
                    <div class="d-flex justify-content-between py-2 border-bottom small">
                        <span>{{ $p->paid_on->format('j M Y') }} · {{ $p->method_label }}{{ $p->reference ? ' · ' . $p->reference : '' }}<div class="text-muted">by {{ $p->paidBy?->name }}{{ $p->notes ? ' · ' . $p->notes : '' }}</div></span>
                        <strong>@money($p->amount)</strong>
                    </div>
                @empty
                    <div class="small text-muted">Nothing paid yet.</div>
                @endforelse
                <div class="d-flex justify-content-between pt-2"><span class="fw-bold">Still owed</span><span class="fw-bold">@money($inv->balance())</span></div>
            </x-cb.card>
        </div>
        <div class="col-xl-5">
            @if (in_array($inv->status, ['unpaid', 'part_paid'], true))
                @can('Pay suppliers')
                    <x-cb.card title="Record a payment" icon="ri-money-dollar-circle-line">
                        <form method="POST" action="{{ route('inventory.invoices.pay', $inv) }}" class="rm-form row g-2" data-once>@csrf
                            <div class="col-6"><label for="pyAmt">Amount</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" name="amount" id="pyAmt" class="form-control" min="0.01" step="0.01" max="{{ $inv->balance() }}" required value="{{ old('amount', $inv->balance()) }}"></div></div>
                            <div class="col-6"><label for="pyOn">Paid on</label><input type="date" name="paid_on" id="pyOn" class="form-control" required max="{{ today()->toDateString() }}" value="{{ old('paid_on', today()->toDateString()) }}"></div>
                            <div class="col-6"><label for="pyMethod">How</label><select name="method" id="pyMethod" class="form-select">@foreach (\App\Models\Inventory\SupplierInvoice::METHODS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></div>
                            <div class="col-6"><label for="pyRef">Reference</label><input name="reference" id="pyRef" class="form-control" maxlength="80" placeholder="Transfer ref / cheque no."></div>
                            <div class="col-12"><input name="notes" class="form-control" maxlength="255" placeholder="Notes (optional)" aria-label="Notes"></div>
                            <div class="col-12"><button class="btn btn-success"><i class="ri-check-line"></i> Record payment</button>
                                @if ($match['mismatch'])<div class="form-text text-danger">This bill doesn't match the delivery. Make sure it has been checked.</div>@endif</div>
                        </form>
                    </x-cb.card>
                @endcan
            @endif
            <x-cb.card title="Details" icon="ri-information-line">
                <dl class="rm-meta mb-0" style="grid-template-columns: 120px 1fr">
                    <dt>Supplier</dt><dd>{{ $inv->supplier->name }}<div class="small text-muted fw-normal">Pays in {{ $inv->supplier->payment_terms_days }} days · owed in total @money($inv->supplier->balance())</div></dd>
                    <dt>Recorded</dt><dd>{{ $inv->created_at->format('j M Y, g:i A') }}<div class="small text-muted fw-normal">by {{ $inv->recordedBy?->name }}</div></dd>
                    @if ($inv->notes)<dt>Notes</dt><dd>{{ $inv->notes }}</dd>@endif
                </dl>
            </x-cb.card>
            @if ($inv->status !== 'void' && (float) $inv->paid == 0)
                @can('Manage supplier invoices')
                    <form method="POST" action="{{ route('inventory.invoices.void', $inv) }}" class="rm-form cb-card p-3" data-once>@csrf
                        <label for="vdReason">Void this invoice</label>
                        <div class="input-group"><input name="reason" id="vdReason" class="form-control" maxlength="255" required placeholder="e.g. Entered twice, supplier sent a corrected bill"><button class="btn btn-outline-danger">Void</button></div>
                    </form>
                @endcan
            @endif
        </div>
    </div>
</div>
</div>
</div>
@endsection
