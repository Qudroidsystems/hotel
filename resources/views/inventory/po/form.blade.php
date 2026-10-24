@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Inventory\StockItem;
    $rows = old('lines') ? collect(old('lines'))->map(fn ($l) => ['item_id' => (int) ($l['item_id'] ?? 0) ?: null, 'quantity' => $l['quantity'] ?? '', 'unit_cost' => $l['unit_cost'] ?? ''])->values()->all()
        : ($po ? $po->lines->map(fn ($l) => ['item_id' => $l->item_id, 'quantity' => StockItem::qty($l->quantity), 'unit_cost' => (float) $l->unit_cost])->all() : []);
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-file-list-3-line" subtitle="Order goods from a supplier" :back="$po ? route('inventory.po.show', $po) : route('inventory.po.index')" :back-label="$po ? $po->number : 'Purchase orders'" />
    @include('rooms.partials.flash')

    <form method="POST" action="{{ $po ? route('inventory.po.update', $po) : route('inventory.po.store') }}" class="rm-form">
        @csrf @if ($po) @method('PUT') @endif
        <div class="row g-3">
            <div class="col-xl-3">
                <x-cb.card title="Order" icon="ri-file-text-line">
                    <div class="mb-3"><label for="p_sup">Supplier</label>
                        <select name="supplier_id" id="p_sup" class="form-select" required><option value="">Choose…</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id', $po?->supplier_id ?? $supplierId) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                    <div class="mb-3"><label for="p_loc">Deliver to</label>
                        <select name="location_id" id="p_loc" class="form-select" required>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected(old('location_id', $po?->location_id ?? $locations->firstWhere('type', 'store')?->id) == $l->id)>{{ $l->name }}</option>@endforeach</select></div>
                    <div class="mb-3"><label for="p_exp">Expected on</label><input type="date" name="expected_on" id="p_exp" class="form-control" value="{{ old('expected_on', $po?->expected_on?->toDateString()) }}"></div>
                    <div><label for="p_notes">Notes for the supplier</label><textarea name="notes" id="p_notes" class="form-control" rows="3" maxlength="500">{{ old('notes', $po?->notes) }}</textarea></div>
                </x-cb.card>
            </div>
            <div class="col-xl-9">
                <x-cb.card title="Items" icon="ri-archive-line" :flush="true">
                    @include('inventory.partials.lines', ['items' => $items, 'rows' => $rows, 'withCost' => true])
                </x-cb.card>
                <div class="d-flex justify-content-end gap-2 flex-wrap">
                    <a href="{{ $po ? route('inventory.po.show', $po) : route('inventory.po.index') }}" class="btn btn-light">Cancel</a>
                    @php $limit = (float) config('hotel.procurement.approval_limit'); @endphp
                    <span class="small text-muted me-auto align-self-center">{{ $limit > 0 ? 'Orders over ' . config('hotel.currency_symbol') . number_format($limit) . ' go to a manager for approval first.' : 'Every order goes to a manager for approval first.' }}</span>
                    <button type="submit" class="btn btn-outline-primary" name="send" value="0"><i class="ri-save-line"></i> Save draft</button>
                    <button type="submit" class="btn btn-primary" name="send" value="1"><i class="ri-send-plane-line"></i> Save &amp; send</button>
                </div>
            </div>
        </div>
    </form>
</div>
</div>
</div>
@endsection
