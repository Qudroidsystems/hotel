@extends('billing.print-layout')
@php use App\Models\Inventory\StockItem; $t = $transfer; @endphp
@section('title', $t->number)
@section('heading', 'Stock transfer')
@section('back', route('inventory.transfers.show', $t))
@section('body')
    <div class="cols">
        <div><div class="lbl">Transfer</div><div class="ref">{{ $t->number }}</div><div class="muted">{{ $t->created_at->format('j M Y, g:i A') }}</div></div>
        <div><div class="lbl">From</div><strong>{{ $t->from->name }}</strong></div>
        <div><div class="lbl">To</div><strong>{{ $t->to->name }}</strong></div>
    </div>
    <table>
        <thead><tr><th>Item</th><th class="num">Quantity</th><th class="num">Checked</th></tr></thead>
        <tbody>@foreach ($t->lines as $l)<tr><td>{{ $l->item->name }}</td><td class="num">{{ StockItem::qty($l->quantity) }} {{ $l->item->unit }}</td><td class="num">☐</td></tr>@endforeach</tbody>
    </table>
    @if ($t->notes)<div class="note">{{ $t->notes }}</div>@endif
    <div style="display:flex;gap:40px;margin-top:40px;font-size:12.5px">
        <div style="flex:1;border-top:1px solid #94a3b8;padding-top:6px">Issued by — {{ $t->createdBy?->name }}</div>
        <div style="flex:1;border-top:1px solid #94a3b8;padding-top:6px">Received by</div>
    </div>
@endsection
