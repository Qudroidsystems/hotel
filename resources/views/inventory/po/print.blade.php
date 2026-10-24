@extends('billing.print-layout')
@php use App\Models\Inventory\StockItem; @endphp
@section('title', $po->number)
@section('heading', 'Purchase order')
@section('back', route('inventory.po.show', $po))
@section('body')
    <div class="cols">
        <div><div class="lbl">To</div><strong>{{ $po->supplier->name }}</strong>
            @if ($po->supplier->contact_person)<div class="muted">{{ $po->supplier->contact_person }}</div>@endif
            @if ($po->supplier->phone)<div class="muted">{{ $po->supplier->phone }}</div>@endif
            @if ($po->supplier->address)<div class="muted">{{ $po->supplier->address }}</div>@endif</div>
        <div><div class="lbl">Order no.</div><div class="ref">{{ $po->number }}</div><div class="muted">{{ ($po->ordered_at ?? $po->created_at)->format('j M Y') }}</div></div>
        <div><div class="lbl">Deliver to</div><strong>{{ config('hotel.name') }}</strong><div class="muted">{{ $po->location->name }}</div>
            @if ($po->expected_on)<div class="muted">By {{ $po->expected_on->format('l j M Y') }}</div>@endif</div>
        @if ($po->status === 'draft')<div><span class="stamp" style="background:#64748b">DRAFT</span></div>@endif
    </div>
    <table>
        <thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">Unit price</th><th class="num">Total</th></tr></thead>
        <tbody>
            @foreach ($po->lines as $l)
                <tr><td>{{ $l->item->name }}</td><td class="num">{{ StockItem::qty($l->quantity) }} {{ $l->item->unit }}</td><td class="num">@money($l->unit_cost)</td><td class="num">@money((float) $l->quantity * (float) $l->unit_cost)</td></tr>
            @endforeach
        </tbody>
    </table>
    <div class="totals"><div class="grand"><span>Total</span><span>@money($po->total)</span></div></div>
    @if ($po->notes)<div class="note">{{ $po->notes }}</div>@endif
    <div class="note">Please quote <strong>{{ $po->number }}</strong> on your invoice and delivery note.</div>
    <div style="display:flex;gap:40px;margin-top:40px;font-size:12.5px">
        <div style="flex:1;border-top:1px solid #94a3b8;padding-top:6px">Authorised by</div>
        <div style="flex:1;border-top:1px solid #94a3b8;padding-top:6px">Date</div>
    </div>
@endsection
