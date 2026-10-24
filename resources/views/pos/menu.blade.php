@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Pos\PosItem;
    use App\Models\Pos\PosOutlet;
    $tabs = ['items' => ['Items & prices', 'ri-restaurant-2-line', $items->count()], 'categories' => ['Categories', 'ri-folders-line', $categories->count()], 'outlets' => ['Outlets', 'ri-store-2-line', $outlets->count()]];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Menu & Prices" icon="ri-restaurant-2-line" subtitle="What each bar and restaurant sells. Tills pick up changes the next time they are online.">
        <x-slot:actions>
            @can('Use POS')<a class="cb-hero-btn" href="{{ route('pos.terminal') }}"><i class="ri-computer-line"></i> Open till</a>@endcan
            <button type="button" class="cb-hero-btn" data-edit-item="new" @disabled($categories->isEmpty())><i class="ri-add-line"></i> Add item</button>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($outlets->isEmpty() || $categories->isEmpty())
        <div class="cb-banner info"><i class="ri-information-line"></i><div>Start by adding an <strong>outlet</strong> (e.g. Pool Bar) and a <strong>category</strong> (e.g. Beers), then add items.</div></div>
    @endif

    <div class="rs-tabs" role="tablist">
        @foreach ($tabs as $key => [$label, $icon, $n])
            <a href="{{ route('pos.menu', ['tab' => $key]) }}" class="rs-tab {{ $tab === $key ? 'active' : '' }}" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"><i class="{{ $icon }}"></i> {{ $label }} <span class="n">{{ $n }}</span></a>
        @endforeach
    </div>

    @if ($tab === 'items')
        <div class="cb-card">
            <form method="GET" class="cb-toolbar">
                <input type="hidden" name="tab" value="items">
                <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $q }}" placeholder="Item name or code…" aria-label="Search"></div>
                <select name="category" class="cb-select" aria-label="Category" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach
                </select>
                <select name="outlet" class="cb-select" aria-label="Outlet" onchange="this.form.submit()">
                    <option value="">All outlets</option>
                    @foreach ($outlets as $o)<option value="{{ $o->id }}" @selected(request('outlet') == $o->id)>{{ $o->name }}</option>@endforeach
                </select>
                <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Filter</button>
            </form>
            @if ($items->isEmpty())
                <div class="empty-state py-5"><i class="ri-restaurant-2-line"></i><h6>No items</h6></div>
            @else
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Item</th><th>Category</th><th>Sold at</th><th>On bill as</th><th class="num">Price</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($items as $i)
                                <tr style="{{ $i->is_active ? '' : 'opacity:.55' }}">
                                    <td data-label="Item"><div class="fw-bold" style="color: var(--cb-heading)">{{ $i->name }}</div>
                                        <div class="small text-muted">{{ $i->sku ?: '' }}{{ $i->description ? ($i->sku ? ' · ' : '') . $i->description : '' }}</div>
                                        @unless ($i->is_active)<span class="status-pill st-muted">Off the menu</span>@endunless</td>
                                    <td data-label="Category"><span class="d-inline-flex align-items-center gap-1"><span style="width:9px;height:9px;border-radius:50%;background:{{ $i->category->color }}"></span>{{ $i->category->name }}</span></td>
                                    <td data-label="Sold at">@forelse ($i->outlets as $o)<span class="status-pill" style="background: {{ $o->color }}22; color: {{ $o->color }}">{{ $o->code }}</span> @empty <span class="small text-danger">Nowhere</span>@endforelse</td>
                                    <td data-label="On bill as" class="small">{{ $i->charge_type->label() }}</td>
                                    <td data-label="Price" class="num fw-bold">@money($i->price)</td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="action-btn btn-more" data-edit-item="{{ json_encode($i->only(['id', 'name', 'category_id', 'price', 'sku', 'description', 'sort_order', 'is_active']) + ['charge_type' => $i->charge_type->value, 'outlets' => $i->outlets->pluck('id')]) }}"><i class="ri-pencil-line"></i> Edit</button>
                                        <form method="POST" action="{{ route('pos.items.destroy', $i) }}" class="d-inline" onsubmit="return confirm('Remove {{ addslashes($i->name) }}?');">@csrf @method('DELETE')
                                            <button class="action-btn btn-danger-soft" type="submit" title="Remove"><i class="ri-delete-bin-line"></i></button></form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @elseif ($tab === 'categories')
        <div class="cb-card">
            <div class="d-flex justify-content-end p-3 pb-0"><button type="button" class="action-btn btn-go" data-edit-category="new"><i class="ri-add-line"></i> Add category</button></div>
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Category</th><th class="num">Items</th><th class="num">Order</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($categories as $c)
                            <tr style="{{ $c->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Category"><span class="d-inline-flex align-items-center gap-2 fw-bold"><span style="width:14px;height:14px;border-radius:4px;background:{{ $c->color }}"></span>{{ $c->name }}</span>
                                    @unless ($c->is_active)<span class="status-pill st-muted">Hidden</span>@endunless</td>
                                <td data-label="Items" class="num">{{ $c->items_count }}</td>
                                <td data-label="Order" class="num">{{ $c->sort_order }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="action-btn btn-more" data-edit-category="{{ json_encode($c->only(['id', 'name', 'color', 'sort_order', 'is_active'])) }}"><i class="ri-pencil-line"></i> Edit</button>
                                    <form method="POST" action="{{ route('pos.categories.destroy', $c) }}" class="d-inline" onsubmit="return confirm('Delete this category?');">@csrf @method('DELETE')
                                        <button class="action-btn btn-danger-soft" type="submit" title="Delete"><i class="ri-delete-bin-line"></i></button></form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">No categories yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="cb-card">
            <div class="d-flex justify-content-end p-3 pb-0"><button type="button" class="action-btn btn-go" data-edit-outlet="new"><i class="ri-add-line"></i> Add outlet</button></div>
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Outlet</th><th>Code</th><th>Type</th><th>Room charges</th><th class="num">Items</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($outlets as $o)
                            <tr style="{{ $o->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Outlet"><span class="d-inline-flex align-items-center gap-2 fw-bold"><span style="width:14px;height:14px;border-radius:4px;background:{{ $o->color }}"></span>{{ $o->name }}</span>
                                    @unless ($o->is_active)<span class="status-pill st-muted">Closed</span>@endunless
                                    @if ($o->receipt_footer)<div class="small text-muted">{{ $o->receipt_footer }}</div>@endif</td>
                                <td data-label="Code"><span class="rs-ref">{{ $o->code }}</span></td>
                                <td data-label="Type">{{ $o->type_label }}</td>
                                <td data-label="Room charges">{!! $o->allow_room_charge ? '<span class="status-pill st-success">Allowed</span>' : '<span class="status-pill st-muted">No</span>' !!}</td>
                                <td data-label="Items" class="num">{{ $o->items_count }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="action-btn btn-more" data-edit-outlet="{{ json_encode($o->only(['id', 'name', 'code', 'type', 'color', 'allow_room_charge', 'receipt_footer', 'is_active', 'sort_order'])) }}"><i class="ri-pencil-line"></i> Edit</button>
                                    <form method="POST" action="{{ route('pos.outlets.destroy', $o) }}" class="d-inline" onsubmit="return confirm('Remove {{ addslashes($o->name) }}?');">@csrf @method('DELETE')
                                        <button class="action-btn btn-danger-soft" type="submit" title="Remove"><i class="ri-delete-bin-line"></i></button></form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">No outlets yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
</div>
</div>

{{-- Item modal --}}
<div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('pos.items.store') }}" id="itemForm">
            @csrf <input type="hidden" name="_method" value="POST">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-restaurant-2-line me-1"></i> <span data-title>Add item</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label for="i_name">Name <span class="text-danger">*</span></label><input name="name" id="i_name" class="form-control" maxlength="120" required placeholder="e.g. Star Lager (60cl)"></div>
                <div class="col-sm-6"><label for="i_category_id">Category</label>
                    <select name="category_id" id="i_category_id" class="form-select" required>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label for="i_price">Price <span class="text-danger">*</span></label>
                    <div class="input-group"><span class="input-group-text">{{ config('hotel.currency_symbol') }}</span><input type="number" name="price" id="i_price" class="form-control" min="0" step="0.01" required></div></div>
                <div class="col-sm-6"><label for="i_charge_type">Shows on a guest bill as</label>
                    <select name="charge_type" id="i_charge_type" class="form-select">@foreach (PosItem::CHARGE_TYPES as $t)<option value="{{ $t->value }}">{{ $t->label() }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label for="i_sku">Code / barcode</label><input name="sku" id="i_sku" class="form-control" maxlength="40" placeholder="Optional"></div>
                <div class="col-12"><label>Sold at</label>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach ($outlets as $o)
                            <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="outlets[]" value="{{ $o->id }}" data-outlet-box checked> <span class="form-check-label">{{ $o->name }}</span></label>
                        @endforeach
                    </div></div>
                <div class="col-sm-8"><label for="i_description">Description</label><input name="description" id="i_description" class="form-control" maxlength="255"></div>
                <div class="col-sm-4"><label for="i_sort_order">Order</label><input type="number" name="sort_order" id="i_sort_order" class="form-control" min="0" max="9999" value="0"></div>
                <div class="col-12"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="i_is_active" checked> <span class="form-check-label">On the menu</span></label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>

{{-- Category modal --}}
<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form class="modal-content rm-form" method="POST" action="{{ route('pos.categories.store') }}" id="categoryForm">
            @csrf <input type="hidden" name="_method" value="POST">
            <div class="modal-header"><h5 class="modal-title"><span data-title>Add category</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label for="c_name">Name</label><input name="name" id="c_name" class="form-control" maxlength="80" required placeholder="e.g. Beers"></div>
                <div class="col-6"><label for="c_color">Colour</label><input type="color" name="color" id="c_color" class="form-control form-control-color w-100" value="#0d9488"></div>
                <div class="col-6"><label for="c_sort_order">Order</label><input type="number" name="sort_order" id="c_sort_order" class="form-control" min="0" value="0"></div>
                <div class="col-12"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="c_is_active" checked> <span class="form-check-label">Show on tills</span></label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>

{{-- Outlet modal --}}
<div class="modal fade" id="outletModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('pos.outlets.store') }}" id="outletForm">
            @csrf <input type="hidden" name="_method" value="POST">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-store-2-line me-1"></i> <span data-title>Add outlet</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-sm-8"><label for="o_name">Name</label><input name="name" id="o_name" class="form-control" maxlength="100" required placeholder="e.g. Pool Bar"></div>
                <div class="col-sm-4"><label for="o_code">Code</label><input name="code" id="o_code" class="form-control text-uppercase" maxlength="6" minlength="2" required placeholder="POOL"></div>
                <div class="col-sm-6"><label for="o_type">Type</label>
                    <select name="type" id="o_type" class="form-select">@foreach (PosOutlet::TYPES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                <div class="col-sm-3"><label for="o_color">Colour</label><input type="color" name="color" id="o_color" class="form-control form-control-color w-100" value="#ea580c"></div>
                <div class="col-sm-3"><label for="o_sort_order">Order</label><input type="number" name="sort_order" id="o_sort_order" class="form-control" min="0" value="0"></div>
                <div class="col-12"><label for="o_receipt_footer">Receipt footer</label><input name="receipt_footer" id="o_receipt_footer" class="form-control" maxlength="255" placeholder="e.g. Thank you — happy hour 5–7pm daily"></div>
                <div class="col-12 d-flex flex-column gap-1">
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="allow_room_charge" value="1" id="o_allow_room_charge" checked> <span class="form-check-label">Guests can charge to their room</span></label>
                    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="o_is_active" checked> <span class="form-check-label">Open</span></label>
                </div>
                <div class="col-12 small text-muted">The code starts every order number from this outlet, e.g. <span class="rs-ref">POOL-K7Q2-0042</span>.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // One helper for the three add/edit modals.
    function wire(attr, modalId, formId, prefix, storeUrl, updateUrl, label) {
        const form = document.getElementById(formId), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById(modalId));
        document.querySelectorAll('[' + attr + ']').forEach(btn => btn.addEventListener('click', () => {
            const raw = btn.getAttribute(attr), d = raw === 'new' ? null : JSON.parse(raw);
            form.reset();
            form.action = d ? updateUrl.replace('__ID__', d.id) : storeUrl;
            form.querySelector('[name=_method]').value = d ? 'PUT' : 'POST';
            form.querySelector('[data-title]').textContent = (d ? 'Edit ' : 'Add ') + label;
            if (d) {
                Object.entries(d).forEach(([k, v]) => {
                    const el = document.getElementById(prefix + k);
                    if (!el) return;
                    if (el.type === 'checkbox') el.checked = !!v; else el.value = v ?? '';
                });
                if (d.outlets) form.querySelectorAll('[data-outlet-box]').forEach(b => b.checked = d.outlets.includes(parseInt(b.value)));
            }
            modal.show();
        }));
    }
    wire('data-edit-item', 'itemModal', 'itemForm', 'i_', @json(route('pos.items.store')), @json(route('pos.items.update', '__ID__')), 'item');
    wire('data-edit-category', 'categoryModal', 'categoryForm', 'c_', @json(route('pos.categories.store')), @json(route('pos.categories.update', '__ID__')), 'category');
    wire('data-edit-outlet', 'outletModal', 'outletForm', 'o_', @json(route('pos.outlets.store')), @json(route('pos.outlets.update', '__ID__')), 'outlet');
});
</script>
@endsection
