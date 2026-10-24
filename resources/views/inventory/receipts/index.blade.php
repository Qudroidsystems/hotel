@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Goods Received" icon="ri-truck-line" subtitle="Deliveries booked into stock">
        <x-slot:actions>
            @can('Receive stock')<a class="cb-hero-btn" href="{{ route('inventory.receipts.create') }}" style="background:#16a34a;border-color:#16a34a"><i class="ri-truck-line"></i> Receive stock</a>@endcan
        </x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.nav')
    @include('rooms.partials.flash')

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="GRN or supplier invoice no…" aria-label="Search"></div>
            <select name="supplier" class="cb-select" aria-label="Supplier" onchange="this.form.submit()">
                <option value="">All suppliers</option>
                @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(request('supplier') == $s->id)>{{ $s->name }}</option>@endforeach
            </select>
            <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Filter</button>
        </form>
        @if ($receipts->isEmpty())
            <div class="empty-state py-5"><i class="ri-truck-line"></i><h6>No deliveries yet</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>GRN</th><th>Supplier</th><th>Into</th><th>Received</th><th class="num">Value</th></tr></thead>
                    <tbody>
                        @foreach ($receipts as $r)
                            <tr>
                                <td data-label="GRN"><a href="{{ route('inventory.receipts.show', $r) }}" class="rs-ref">{{ $r->number }}</a>
                                    @if ($r->purchaseOrder)<div class="small text-muted">for {{ $r->purchaseOrder->number }}</div>@endif</td>
                                <td data-label="Supplier"><span class="fw-semibold">{{ $r->supplier?->name ?? '—' }}</span>@if ($r->supplier_invoice)<div class="small text-muted">Invoice {{ $r->supplier_invoice }}</div>@endif</td>
                                <td data-label="Into">{{ $r->location->name }}<div class="small text-muted">{{ $r->lines_count }} item{{ $r->lines_count === 1 ? '' : 's' }}</div></td>
                                <td data-label="Received" class="small">{{ $r->received_on->format('j M Y') }}<div class="text-muted">{{ $r->receivedBy?->name }}</div></td>
                                <td data-label="Value" class="num fw-bold">@money($r->total)</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $receipts->links() }}</div>
        @endif
    </div>
</div>
</div>
</div>
@endsection
