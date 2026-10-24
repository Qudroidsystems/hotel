@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Inventory\StockItem; $q = fn ($v) => StockItem::qty($v); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Recipes" icon="ri-restaurant-line" subtitle="What each bar and restaurant item takes out of stock when it is sold — and what it costs to make" />
    @include('inventory.partials.nav')
    @include('rooms.partials.flash')

    @if ($missing > 0)
        <div class="cb-banner warning"><i class="ri-error-warning-line"></i><div><strong>{{ $missing }} menu item{{ $missing === 1 ? '' : 's' }}</strong> {{ $missing === 1 ? 'has' : 'have' }} no recipe, so selling {{ $missing === 1 ? 'it' : 'them' }} doesn't touch stock. <a href="{{ route('inventory.recipes.index', ['show' => 'missing']) }}" class="fw-bold">Show them</a></div></div>
    @endif

    <div class="rs-tabs">
        <a href="{{ route('inventory.recipes.index') }}" class="rs-tab {{ $filter === 'all' ? 'active' : '' }}">All menu items</a>
        <a href="{{ route('inventory.recipes.index', ['show' => 'missing']) }}" class="rs-tab {{ $filter === 'missing' ? 'active' : '' }}">No recipe <span class="n">{{ $missing }}</span></a>
    </div>

    <div class="cb-card">
        @if ($posItems->isEmpty())
            <div class="empty-state py-5"><i class="ri-restaurant-line"></i><h6>{{ $filter === 'missing' ? 'Every menu item has a recipe' : 'No menu items yet' }}</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Menu item</th><th>Uses from stock (each)</th><th class="num">Price</th><th class="num">Cost</th><th class="num">Margin</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($posItems as $p)
                            @php
                                $cost = $p->components->sum(fn ($c) => (float) $c->quantity * (float) $c->stockItem->avg_cost);
                                $margin = (float) $p->price > 0 && $p->components->isNotEmpty() ? ((float) $p->price - $cost) / (float) $p->price * 100 : null;
                            @endphp
                            <tr>
                                <td data-label="Menu item"><span class="fw-bold" style="color: var(--cb-heading)">{{ $p->name }}</span><div class="small text-muted">{{ $p->category?->name }} · @foreach ($p->outlets as $o)<span style="color:{{ $o->color }}">{{ $o->code }}</span> @endforeach</div></td>
                                <td data-label="Uses" class="small">
                                    @forelse ($p->components as $c)
                                        <div>{{ $q($c->quantity) }} {{ $c->stockItem->unit }} · {{ $c->stockItem->name }}</div>
                                    @empty
                                        <span class="text-warning-emphasis"><i class="ri-error-warning-line"></i> No recipe</span>
                                    @endforelse
                                </td>
                                <td data-label="Price" class="num">@money($p->price)</td>
                                <td data-label="Cost" class="num">{{ $p->components->isNotEmpty() ? config('hotel.currency_symbol') . number_format($cost, 2) : '—' }}</td>
                                <td data-label="Margin" class="num fw-bold {{ $margin !== null && $margin < 30 ? 'text-danger' : '' }}">{{ $margin !== null ? number_format($margin, 0) . '%' : '—' }}</td>
                                <td class="text-end"><button type="button" class="action-btn btn-more" data-recipe="{{ json_encode(['id' => $p->id, 'name' => $p->name, 'url' => route('inventory.recipes.update', $p), 'rows' => $p->components->map(fn ($c) => ['stock_item_id' => $c->stock_item_id, 'quantity' => $q($c->quantity)])]) }}"><i class="ri-pencil-line"></i> Recipe</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
</div>
</div>

<div class="modal fade" id="recipeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content rm-form" method="POST" action="#" id="recipeForm">
            @csrf @method('PUT')
            <div class="modal-header"><h5 class="modal-title"><i class="ri-restaurant-line me-1"></i> Recipe — <span data-name></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p class="small text-muted">For <strong>one</strong> sold: e.g. a bottle of beer uses 1 bottle; a shot of spirit uses 0.05 of a 70cl bottle; a plate of jollof uses 0.2 kg rice and 0.3 kg chicken.</p>
                <div id="recipeRows"></div>
                <button type="button" class="action-btn btn-more mt-2" id="recipeAdd"><i class="ri-add-line"></i> Add ingredient</button>
                <div class="d-flex justify-content-between mt-3 p-2 px-3 rounded-3" style="background: var(--cb-surface-2)"><span class="text-muted">Cost to make one</span><strong id="recipeCost">—</strong></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save recipe</button></div>
        </form>
    </div>
</div>
<template id="recipeRowTpl">
    <div class="d-flex gap-2 mb-2 align-items-center">
        <select class="form-select form-select-sm" data-f="item" aria-label="Stock item"><option value="">Choose a stock item…</option>@foreach ($stockItems as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->unit }})</option>@endforeach</select>
        <input type="number" class="form-control form-control-sm" data-f="qty" style="max-width:120px" min="0.001" step="0.001" placeholder="Qty" aria-label="Quantity">
        <span class="small text-muted text-nowrap" data-f="unit" style="min-width:50px"></span>
        <button type="button" class="action-btn btn-danger-soft" data-f="rm" aria-label="Remove"><i class="ri-close-line"></i></button>
    </div>
</template>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const STOCK = @json($stockItems->keyBy('id')->map(fn ($s) => ['unit' => $s->unit, 'cost' => (float) $s->avg_cost]));
    const sym = @json(config('hotel.currency_symbol'));
    const form = document.getElementById('recipeForm'), rowsEl = document.getElementById('recipeRows'), tpl = document.getElementById('recipeRowTpl');
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('recipeModal'));
    function renumber() {
        let cost = 0;
        [...rowsEl.children].forEach((r, i) => {
            const sel = r.querySelector('[data-f=item]'), qty = r.querySelector('[data-f=qty]');
            sel.name = `components[${i}][stock_item_id]`; qty.name = `components[${i}][quantity]`;
            const s = STOCK[sel.value];
            r.querySelector('[data-f=unit]').textContent = s ? s.unit : '';
            if (s) cost += (parseFloat(qty.value) || 0) * s.cost;
        });
        document.getElementById('recipeCost').textContent = sym + cost.toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function add(row) {
        const r = tpl.content.firstElementChild.cloneNode(true);
        if (row) { r.querySelector('[data-f=item]').value = row.stock_item_id; r.querySelector('[data-f=qty]').value = row.quantity; }
        r.querySelectorAll('select,input').forEach(el => el.addEventListener('input', renumber));
        r.querySelector('[data-f=rm]').addEventListener('click', () => { r.remove(); renumber(); });
        rowsEl.appendChild(r); renumber();
    }
    document.getElementById('recipeAdd').addEventListener('click', () => add());
    document.querySelectorAll('[data-recipe]').forEach(b => b.addEventListener('click', () => {
        const d = JSON.parse(b.dataset.recipe);
        form.action = d.url; form.querySelector('[data-name]').textContent = d.name;
        rowsEl.innerHTML = '';
        (d.rows.length ? d.rows : [null]).forEach(add);
        modal.show();
    }));
});
</script>
@endsection
