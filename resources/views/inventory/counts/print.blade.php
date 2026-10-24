@extends('billing.print-layout')
@php $c = $count; @endphp
@section('title', 'Count sheet ' . $c->number)
@section('heading', 'Stocktake count sheet')
@section('back', route('inventory.counts.show', $c))
@section('body')
    <div class="cols">
        <div><div class="lbl">Stocktake</div><div class="ref">{{ $c->number }}</div></div>
        <div><div class="lbl">Store</div><strong>{{ $c->location->name }}</strong>@if ($c->category)<div class="muted">{{ $c->category->name }}</div>@endif</div>
        <div><div class="lbl">Started</div><strong>{{ $c->created_at->format('j M Y, g:i A') }}</strong></div>
    </div>
    <table>
        <thead><tr><th>Item</th><th>Unit</th><th class="num" style="width:120px">Counted</th></tr></thead>
        <tbody>@foreach ($c->lines->sortBy(fn ($l) => [$l->item->category?->name, $l->item->name]) as $l)<tr><td>{{ $l->item->name }}</td><td class="muted">{{ $l->item->unit }}</td><td style="border-bottom:1px solid #94a3b8"></td></tr>@endforeach</tbody>
    </table>
    <div style="display:flex;gap:40px;margin-top:40px;font-size:12.5px">
        <div style="flex:1;border-top:1px solid #94a3b8;padding-top:6px">Counted by</div>
        <div style="flex:1;border-top:1px solid #94a3b8;padding-top:6px">Checked by</div>
    </div>
@endsection
