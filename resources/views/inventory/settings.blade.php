@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Inventory\StockLocation; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Stores & Categories" icon="ri-settings-3-line" subtitle="Where stock is kept, how it is grouped, and which store each bar or restaurant sells from" />
    @include('inventory.partials.nav')
    @include('rooms.partials.flash')

    <div class="row g-3">
        <div class="col-xl-7">
            <x-cb.card title="Stores" icon="ri-store-2-line" :flush="true">
                <x-slot:tools><button type="button" class="action-btn btn-go" data-loc="new"><i class="ri-add-line"></i> Add store</button></x-slot:tools>
                <table class="cb-table stack">
                    <thead><tr><th>Store</th><th>Code</th><th>Kind</th><th class="num">Items held</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($locations as $l)
                            <tr style="{{ $l->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Store" class="fw-bold">{{ $l->name }}@unless ($l->is_active) <span class="status-pill st-muted">Closed</span>@endunless</td>
                                <td data-label="Code"><span class="rs-ref">{{ $l->code }}</span></td>
                                <td data-label="Kind">{{ $l->type_label }}</td>
                                <td data-label="Items held" class="num">{{ $l->items_count }}</td>
                                <td class="text-end"><button type="button" class="action-btn btn-more" data-loc="{{ json_encode($l->only(['id', 'name', 'code', 'type', 'sort_order', 'is_active'])) }}"><i class="ri-pencil-line"></i></button></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">No stores yet — add your main store first.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-cb.card>

            <x-cb.card title="Bar & restaurant outlets" icon="ri-goblet-line">
                @if ($outlets->isEmpty())
                    <div class="small text-muted">No POS outlets yet.</div>
                @else
                    <form method="POST" action="{{ route('inventory.outlets.save') }}">
                        @csrf
                        <p class="small text-muted">Each sale takes the recipe's ingredients out of this store.</p>
                        @foreach ($outlets as $o)
                            <div class="d-flex align-items-center gap-3 py-2 border-bottom">
                                <span class="fw-semibold flex-grow-1"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $o->color }}"></span> {{ $o->name }}</span>
                                <select name="outlet[{{ $o->id }}]" class="form-select form-select-sm" style="max-width:240px" aria-label="Store for {{ $o->name }}">
                                    <option value="">Don't track stock</option>
                                    @foreach ($locations->where('is_active', true) as $l)<option value="{{ $l->id }}" @selected($o->stock_location_id === $l->id)>{{ $l->name }}</option>@endforeach
                                </select>
                            </div>
                        @endforeach
                        <button class="action-btn btn-primary-cb mt-3" type="submit">Save</button>
                    </form>
                @endif
            </x-cb.card>
        </div>
        <div class="col-xl-5">
            <x-cb.card title="Categories" icon="ri-folders-line">
                <form method="POST" action="{{ route('inventory.categories.store') }}" class="d-flex gap-2 mb-3">
                    @csrf
                    <input name="name" class="form-control form-control-sm" maxlength="80" required placeholder="New category, e.g. Spirits" aria-label="New category">
                    <button class="action-btn btn-go" type="submit">Add</button>
                </form>
                @foreach ($categories as $c)
                    <form method="POST" action="{{ route('inventory.categories.update', $c) }}" class="d-flex gap-2 align-items-center py-1">
                        @csrf @method('PUT')
                        <input name="name" class="form-control form-control-sm" maxlength="80" required value="{{ $c->name }}" aria-label="Category name">
                        <input type="number" name="sort_order" class="form-control form-control-sm" style="max-width:70px" min="0" value="{{ $c->sort_order }}" aria-label="Order">
                        <span class="small text-muted text-nowrap">{{ $c->items_count }} items</span>
                        <button class="action-btn btn-more" type="submit" title="Save"><i class="ri-check-line"></i></button>
                    </form>
                @endforeach
            </x-cb.card>
        </div>
    </div>
</div>
</div>
</div>

<div class="modal fade" id="locModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('inventory.locations.store') }}" id="locForm">
            @csrf <input type="hidden" name="_method" value="POST">
            <div class="modal-header"><h5 class="modal-title" data-title>Add store</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-8"><label for="l_name">Name</label><input name="name" id="l_name" class="form-control" maxlength="100" required placeholder="e.g. Pool Bar"></div>
                <div class="col-sm-4"><label for="l_code">Code</label><input name="code" id="l_code" class="form-control text-uppercase" maxlength="12" required placeholder="POOLBAR"></div>
                <div class="col-sm-8"><label for="l_type">Kind</label><select name="type" id="l_type" class="form-select">@foreach (StockLocation::TYPES as $v => $t)<option value="{{ $v }}">{{ $t }}</option>@endforeach</select></div>
                <div class="col-sm-4"><label for="l_sort_order">Order</label><input type="number" name="sort_order" id="l_sort_order" class="form-control" min="0" value="0"></div>
                <div class="col-12 d-none" id="l_active_wrap"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="l_is_active" checked> <span class="form-check-label">Open</span></label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('locForm'), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('locModal'));
    document.querySelectorAll('[data-loc]').forEach(b => b.addEventListener('click', () => {
        const d = b.dataset.loc === 'new' ? null : JSON.parse(b.dataset.loc);
        form.reset();
        form.action = d ? @json(route('inventory.locations.update', '__ID__')).replace('__ID__', d.id) : @json(route('inventory.locations.store'));
        form.querySelector('[name=_method]').value = d ? 'PUT' : 'POST';
        form.querySelector('[data-title]').textContent = d ? 'Edit store' : 'Add store';
        document.getElementById('l_active_wrap').classList.toggle('d-none', !d);
        if (d) Object.entries(d).forEach(([k, v]) => { const el = document.getElementById('l_' + k); if (el) { if (el.type === 'checkbox') el.checked = !!v; else el.value = v ?? ''; } });
        modal.show();
    }));
});
</script>
@endsection
