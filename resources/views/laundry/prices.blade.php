@extends('layouts.master')

@section('content')
@php $sym = config('hotel.currency_symbol'); @endphp
<style>
    .lp-table input.form-control { min-width: 0; }
    .lp-table .num-in { width: 110px; }
    .lp-table tr.off td { opacity: .55; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-price-tag-3-line" subtitle="What guests pay for each piece. Leave a price empty where an item can't have that service. Guests see this list in the app; a bag is charged at the prices of the day it is counted.">
        <x-slot:actions><a class="cb-hero-btn" href="{{ route('laundry.index') }}"><i class="ri-arrow-left-line"></i> Laundry</a></x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div>@endif

    @if ($items->isEmpty() && $canManage)
        <div class="cb-banner info"><i class="ri-information-line"></i>
            <div class="d-flex flex-wrap gap-2 align-items-center">There are no items yet. Start from a typical list (shirts, suits, native wear …) and change the prices to yours.
                <form method="POST" action="{{ route('laundry.prices.defaults') }}">@csrf<button class="btn btn-sm btn-primary">Use a typical price list</button></form></div></div>
    @endif

    <form method="POST" action="{{ route('laundry.prices.save') }}">@csrf
        <x-cb.card title="Price list" icon="ri-list-unordered" :flush="true">
            <div class="table-responsive">
                <table class="cb-table lp-table">
                    <thead><tr><th>Item</th><th>Group</th>@foreach (\App\Models\Laundry\LaundryItem::SERVICES as $sv)<th>{{ $sv[0] }} ({{ $sym }})</th>@endforeach<th>On the list</th></tr></thead>
                    <tbody id="lpRows">
                        @foreach ($items as $i => $it)
                            <tr class="{{ $it->is_active ? '' : 'off' }}">
                                <td><input type="hidden" name="items[{{ $i }}][id]" value="{{ $it->id }}"><input class="form-control form-control-sm" name="items[{{ $i }}][name]" value="{{ $it->name }}" maxlength="80" @disabled(! $canManage)></td>
                                <td><input class="form-control form-control-sm" name="items[{{ $i }}][group]" value="{{ $it->group }}" maxlength="40" list="lpGroups" @disabled(! $canManage)></td>
                                @foreach (['wash_price', 'dry_price', 'press_price'] as $col)
                                    <td><input type="number" step="0.01" min="0" class="form-control form-control-sm num-in" name="items[{{ $i }}][{{ $col }}]" value="{{ $it->{$col} !== null ? (float) $it->{$col} : '' }}" @disabled(! $canManage)></td>
                                @endforeach
                                <td><input type="checkbox" class="form-check-input" name="items[{{ $i }}][is_active]" value="1" @checked($it->is_active) @disabled(! $canManage) aria-label="{{ $it->name }} on the list"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <datalist id="lpGroups">@foreach ($items->pluck('group')->unique() as $g)<option value="{{ $g }}">@endforeach</datalist>
            @if ($canManage)
                <div class="d-flex gap-2 p-3">
                    <button type="button" class="btn btn-outline-secondary" id="lpAdd"><i class="ri-add-line"></i> Add an item</button>
                    <button class="btn btn-primary ms-auto">Save price list</button>
                </div>
            @endif
        </x-cb.card>
    </form>
    <p class="small text-muted mt-2">Untick “On the list” to stop offering an item; bags already counted keep their prices. Express laundry, the collection hours and turnaround times are in Hotel Settings → Laundry.</p>
</div>
</div>
</div>
@if ($canManage)
<script>
(function () {
    const body = document.getElementById('lpRows'); let n = {{ $items->count() }};
    document.getElementById('lpAdd').addEventListener('click', () => {
        const i = n++, tr = document.createElement('tr');
        tr.innerHTML = `<td><input class="form-control form-control-sm" name="items[${i}][name]" maxlength="80" placeholder="e.g. Kaftan"></td>
            <td><input class="form-control form-control-sm" name="items[${i}][group]" maxlength="40" list="lpGroups" placeholder="Group"></td>
            ${['wash_price', 'dry_price', 'press_price'].map(c => `<td><input type="number" step="0.01" min="0" class="form-control form-control-sm num-in" name="items[${i}][${c}]"></td>`).join('')}
            <td><input type="checkbox" class="form-check-input" name="items[${i}][is_active]" value="1" checked></td>`;
        body.appendChild(tr); tr.querySelector('input').focus();
    });
})();
</script>
@endif
@endsection
