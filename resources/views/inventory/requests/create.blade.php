@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $rows = old('lines') ? collect(old('lines'))->map(fn ($l) => ['item_id' => (int) ($l['item_id'] ?? 0), 'quantity' => $l['quantity'] ?? '', 'unit_cost' => $l['unit_cost'] ?? ''])->values()->all() : [];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-shopping-cart-2-line" subtitle="Ask for stock to be bought. A manager approves it before anything is ordered."
               :back="route('inventory.requests.index')" back-label="Purchase requests" />
    @include('rooms.partials.flash')

    <form method="POST" action="{{ route('inventory.requests.store') }}" class="rm-form" data-once>
        @csrf
        <div class="row g-3">
            <div class="col-xl-3">
                <x-cb.card title="Request" icon="ri-file-text-line">
                    <div class="mb-3"><label for="pr_dept">Department</label>
                        <input name="department" id="pr_dept" class="form-control" list="prDepts" maxlength="80" required value="{{ old('department') }}" placeholder="e.g. Kitchen">
                        <datalist id="prDepts">@foreach (\App\Models\Inventory\PurchaseRequest::DEPARTMENTS as $d)<option value="{{ $d }}">@endforeach</datalist></div>
                    <div class="mb-3"><label for="pr_need">Needed by</label><input type="date" name="needed_by" id="pr_need" class="form-control" min="{{ today()->toDateString() }}" value="{{ old('needed_by') }}"></div>
                    <div><label for="pr_reason">Why</label><textarea name="reason" id="pr_reason" class="form-control" rows="3" maxlength="500" placeholder="e.g. Wedding on Saturday, 200 guests">{{ old('reason') }}</textarea></div>
                </x-cb.card>
            </div>
            <div class="col-xl-9">
                <x-cb.card title="What you need" icon="ri-archive-line" :flush="true">
                    <div class="small text-muted px-3 pt-3">Prices are estimates from the last purchase. Change them if you have a quote.</div>
                    @include('inventory.partials.lines', ['items' => $items, 'rows' => $rows, 'withCost' => true])
                </x-cb.card>
                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ route('inventory.requests.index') }}" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="ri-send-plane-line"></i> Send for approval</button>
                </div>
            </div>
        </div>
    </form>

</div>
</div>
</div>
@endsection
