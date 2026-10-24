@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $rows = old('lines') ? collect(old('lines'))->map(fn ($l) => ['item_id' => (int) $l['item_id'], 'quantity' => $l['quantity'] ?? ''])->values()->all() : [];
    $main = $locations->firstWhere('type', 'store');
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Transfer Stock" icon="ri-arrow-left-right-line" subtitle="Move stock from one store to another, e.g. main store to the bar" :back="route('inventory.transfers.index')" back-label="Transfers" />
    @include('rooms.partials.flash')

    <form method="POST" action="{{ route('inventory.transfers.store') }}" class="rm-form">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $key) }}">
        <div class="row g-3">
            <div class="col-xl-3">
                <x-cb.card title="Stores" icon="ri-store-2-line">
                    <div class="mb-3"><label for="t_from">From</label>
                        <select name="from_location_id" id="t_from" class="form-select" required>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected(old('from_location_id', $main?->id) == $l->id)>{{ $l->name }}</option>@endforeach</select></div>
                    <div class="text-center text-muted"><i class="ri-arrow-down-line fs-4"></i></div>
                    <div class="mb-3"><label for="t_to">To</label>
                        <select name="to_location_id" id="t_to" class="form-select" required>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected(old('to_location_id', $locations->firstWhere('id', '!=', $main?->id)?->id) == $l->id)>{{ $l->name }}</option>@endforeach</select></div>
                    <div><label for="t_notes">Note</label><input name="notes" id="t_notes" class="form-control" maxlength="500" value="{{ old('notes') }}" placeholder="e.g. Friday night top-up"></div>
                </x-cb.card>
            </div>
            <div class="col-xl-9">
                <x-cb.card title="Items" icon="ri-archive-line" :flush="true">
                    @include('inventory.partials.lines', ['items' => $items, 'rows' => $rows, 'withCost' => false, 'levels' => $levels, 'availableFrom' => 't_from'])
                </x-cb.card>
                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ route('inventory.transfers.index') }}" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="ri-arrow-left-right-line"></i> Move stock</button>
                </div>
            </div>
        </div>
    </form>
</div>
</div>
</div>
@endsection
