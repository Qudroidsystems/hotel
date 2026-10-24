@extends('billing.print-layout')
@php use App\Models\Inventory\StockItem; $r = $receipt; @endphp
@section('title', $r->number)
@section('heading', 'Goods received note')
@section('back', route('inventory.receipts.show', $r))
@section('body')
    <div class="cols">
        <div><div class="lbl">GRN</div><div class="ref">{{ $r->number }}</div></div>
        <div><div class="lbl">Supplier</div><strong>{{ $r->supplier?->name ?? '—' }}</strong>@if ($r->supplier_invoice)<div class="muted">Invoice {{ $r->supplier_invoice }}</div>@endif</div>
        <div><div class="lbl">Into store</div><strong>{{ $r->location->name }}</strong></div>
        <div><div class="lbl">Received</div><strong>{{ $r->received_on->format('j M Y') }}</strong>@if ($r->purchaseOrder)<div class="muted">Order {{ $r->purchaseOrder->number }}</div>@endif</div>
    </div>
    <table>
        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Total</th></tr></thead>
        <tbody>
            @foreach ($r->lines as $l)
                <tr><td>{{ $l->item->name }}@if ((float) $l->rejected_quantity > 0)<div class="muted">Refused {{ StockItem::qty($l->rejected_quantity) }}: {{ $l->reject_reason }}</div>@endif</td><td class="num">{{ StockItem::qty($l->quantity) }} {{ $l->item->unit }}</td><td class="num">@money($l->unit_cost)</td><td class="num">@money((float) $l->quantity * (float) $l->unit_cost)</td></tr>
            @endforeach
        </tbody>
    </table>
    <div class="totals"><div class="grand"><span>Total</span><span>@money($r->total)</span></div></div>
    @if ($r->notes)<div class="note">{{ $r->notes }}</div>@endif
    <div style="display:flex;gap:40px;margin-top:40px;font-size:12.5px">
        <div style="flex:1;border-top:1px solid #94a3b8;padding-top:6px">Received by — {{ $r->receivedBy?->name }}</div>
        <div style="flex:1;border-top:1px solid #94a3b8;padding-top:6px">Delivered by (supplier)</div>
    </div>
@endsection
