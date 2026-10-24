@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Inventory\StockItem;
    // Rows: what came back from a failed submit, else the order's outstanding lines, else empty.
    $rows = old('lines') ? collect(old('lines'))->map(fn ($l) => ['item_id' => (int) $l['item_id'], 'quantity' => $l['quantity'] ?? '', 'unit_cost' => $l['unit_cost'] ?? '', 'po_line_id' => $l['purchase_order_line_id'] ?? null, 'locked' => (bool) $po, 'rejected_quantity' => $l['rejected_quantity'] ?? '', 'reject_reason' => $l['reject_reason'] ?? ''])->values()->all()
        : ($po ? $po->lines->filter(fn ($l) => $l->outstanding() > 0)->map(fn ($l) => [
            'item_id' => $l->item_id, 'quantity' => StockItem::qty($l->outstanding()), 'unit_cost' => (float) $l->unit_cost, 'po_line_id' => $l->id, 'locked' => true,
            'hint' => 'Ordered ' . StockItem::qty($l->quantity) . ', received ' . StockItem::qty($l->received_quantity) . ' so far',
        ])->values()->all() : []);
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$po ? 'Receive ' . $po->number : 'Receive Stock'" icon="ri-truck-line" :subtitle="$po ? 'From ' . $po->supplier->name . ' into ' . $po->location->name . ' — enter what actually arrived' : 'Book a delivery into a store'"
               :back="$po ? route('inventory.po.show', $po) : route('inventory.receipts.index')" :back-label="$po ? $po->number : 'Goods received'" />
    @include('rooms.partials.flash')

    <form method="POST" action="{{ route('inventory.receipts.store') }}" class="rm-form">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $key) }}">
        @if ($po)<input type="hidden" name="purchase_order_id" value="{{ $po->id }}">@endif
        <div class="row g-3">
            <div class="col-xl-3">
                <x-cb.card title="Delivery" icon="ri-file-text-line">
                    <div class="mb-3"><label for="r_sup">Supplier</label>
                        @if ($po)
                            <input class="form-control" value="{{ $po->supplier->name }}" disabled>
                        @else
                            <select name="supplier_id" id="r_sup" class="form-select" required><option value="">Choose…</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id') == $s->id)>{{ $s->name }}</option>@endforeach</select>
                            @can('Manage suppliers')<a href="{{ route('inventory.suppliers.index') }}" class="small">Add a supplier</a>@endcan
                        @endif</div>
                    <div class="mb-3"><label for="r_loc">Into store</label>
                        @if ($po)
                            <input class="form-control" value="{{ $po->location->name }}" disabled>
                        @else
                            <select name="location_id" id="r_loc" class="form-select" required>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected(old('location_id', $locations->firstWhere('type', 'store')?->id) == $l->id)>{{ $l->name }}</option>@endforeach</select>
                        @endif</div>
                    <div class="mb-3"><label for="r_inv">Supplier invoice / waybill no.</label><input name="supplier_invoice" id="r_inv" class="form-control" maxlength="60" value="{{ old('supplier_invoice') }}"></div>
                    <div class="mb-3"><label for="r_date">Received on</label><input type="date" name="received_on" id="r_date" class="form-control" required max="{{ today()->toDateString() }}" value="{{ old('received_on', today()->toDateString()) }}"></div>
                    <div><label for="r_notes">Notes</label><textarea name="notes" id="r_notes" class="form-control" rows="2" maxlength="500" placeholder="e.g. 2 crates short, will come Monday">{{ old('notes') }}</textarea></div>
                </x-cb.card>
            </div>
            <div class="col-xl-9">
                <x-cb.card title="What arrived" icon="ri-archive-line" :flush="true">
                    @if ($po)<div class="cb-banner info m-3 mb-0"><i class="ri-information-line"></i><div>Quantities show what is still due on the order. Change them to what actually arrived — anything not delivered stays open on the order. Goods refused at the door (damaged, expired, wrong item) also stay open and are listed on the delivery note.</div></div>@endif
                    @include('inventory.partials.lines', ['items' => $items, 'rows' => $rows, 'withCost' => true, 'withReject' => true])
                </x-cb.card>
                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ $po ? route('inventory.po.show', $po) : route('inventory.receipts.index') }}" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-success"><i class="ri-check-line"></i> Book into stock</button>
                </div>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
