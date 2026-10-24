@extends('layouts.staff')

@section('content')
<form method="GET" class="mb-3">
    <div class="input-group"><input type="search" name="q" class="form-control" value="{{ $q }}" placeholder="Item name or SKU" autofocus aria-label="Search stock"><button class="sa-btn go" style="flex:0 0 auto;border-radius:0 12px 12px 0"><i class="ri-search-line"></i></button></div>
</form>
@if ($q !== '' && mb_strlen($q) < 2)<div class="small text-muted">Type at least 2 letters.</div>@endif
@if (mb_strlen($q) >= 2 && $items->isEmpty())<div class="sa-empty" style="padding-top:10px"><div class="fw-bold">Nothing found for “{{ $q }}”</div></div>@endif
@foreach ($items as $i)
    @php $total = (float) collect($i->by_store)->sum(); $low = (float) $i->reorder_level > 0 && $total <= (float) $i->reorder_level; @endphp
    <div class="sa-item">
        <div class="d-flex justify-content-between gap-2"><div><div class="t">{{ $i->name }}</div><div class="m">{{ $i->category?->name }}{{ $i->sku ? ' · ' . $i->sku : '' }}</div></div>
            <div class="text-end"><div class="big {{ $total <= 0 ? 'text-danger' : ($low ? 'text-warning' : '') }}" style="font-size:22px">{{ \App\Models\Inventory\StockItem::qty($total) }}</div><div class="m">{{ $i->unit }} in total</div></div></div>
        <div class="mt-2">
            @foreach ($locations as $l)
                @php $q2 = (float) ($i->by_store[$l->id] ?? 0); @endphp
                @if ($q2 != 0)<div class="d-flex justify-content-between small py-1 border-top"><span>{{ $l->name }}</span><strong class="{{ $q2 < 0 ? 'text-danger' : '' }}">{{ \App\Models\Inventory\StockItem::qty($q2) }}</strong></div>@endif
            @endforeach
        </div>
        @if ($low)<div class="small text-warning mt-1"><i class="ri-error-warning-line"></i> At or below the reorder level ({{ \App\Models\Inventory\StockItem::qty($i->reorder_level) }})</div>@endif
        <a href="{{ route('inventory.items.show', $i) }}" class="small">Full history →</a>
    </div>
@endforeach
@endsection
