{{-- Add / edit a stock item. Needs $categories, $suppliers, $locations; $item for editing. --}}
@php use App\Models\Inventory\StockItem; $e = $item; @endphp
<div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content rm-form" method="POST" action="{{ $e ? route('inventory.items.update', $e) : route('inventory.items.store') }}">
            @csrf @if ($e) @method('PUT') @endif
            <div class="modal-header"><h5 class="modal-title"><i class="ri-archive-line me-1"></i> {{ $e ? 'Edit ' . $e->name : 'New stock item' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-md-8"><label for="s_name">Name <span class="text-danger">*</span></label><input name="name" id="s_name" class="form-control" maxlength="120" required value="{{ old('name', $e?->name) }}" placeholder="e.g. Star Lager 60cl"></div>
                <div class="col-md-4"><label for="s_sku">Code / barcode</label><input name="sku" id="s_sku" class="form-control" maxlength="40" value="{{ old('sku', $e?->sku) }}"></div>
                <div class="col-md-4"><label for="s_cat">Category</label>
                    <select name="category_id" id="s_cat" class="form-select"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $e?->category_id) == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><label for="s_unit">Counted in</label>
                    <input name="unit" id="s_unit" class="form-control" maxlength="20" required list="stockUnits" value="{{ old('unit', $e?->unit ?? 'pcs') }}">
                    <datalist id="stockUnits">@foreach (StockItem::UNITS as $u)<option value="{{ $u }}">@endforeach</datalist></div>
                <div class="col-md-4"><label for="s_sup">Usual supplier</label>
                    <select name="preferred_supplier_id" id="s_sup" class="form-select"><option value="">—</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(old('preferred_supplier_id', $e?->preferred_supplier_id) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><label for="s_rl">Reorder when total falls to</label><input type="number" name="reorder_level" id="s_rl" class="form-control" min="0" step="0.001" value="{{ old('reorder_level', $e ? StockItem::qty($e->reorder_level) : '') }}" placeholder="0 = never warn"></div>
                <div class="col-md-4"><label for="s_rq">Usual order quantity</label><input type="number" name="reorder_qty" id="s_rq" class="form-control" min="0" step="0.001" value="{{ old('reorder_qty', $e?->reorder_qty !== null ? StockItem::qty($e->reorder_qty) : '') }}"></div>
                <div class="col-md-4"><label for="s_notes">Notes</label><input name="notes" id="s_notes" class="form-control" maxlength="255" value="{{ old('notes', $e?->notes) }}"></div>
                @if (! $e)
                    <div class="col-12"><div class="p-3 rounded-3" style="background: var(--cb-surface-2)">
                        <div class="fw-semibold mb-2"><i class="ri-flag-line"></i> Opening stock <span class="small text-muted fw-normal">(optional — what you already have)</span></div>
                        <div class="row g-2">
                            <div class="col-md-5"><select name="opening_location" class="form-select" aria-label="Store"><option value="">Store…</option>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected(old('opening_location') == $l->id)>{{ $l->name }}</option>@endforeach</select></div>
                            <div class="col-md-3"><input type="number" name="opening_qty" class="form-control" min="0" step="0.001" placeholder="Quantity" value="{{ old('opening_qty') }}" aria-label="Opening quantity"></div>
                            <div class="col-md-4"><div class="input-group"><span class="input-group-text">{{ config('hotel.currency_symbol') }}</span><input type="number" name="opening_cost" class="form-control" min="0" step="0.01" placeholder="Cost per unit" value="{{ old('opening_cost') }}" aria-label="Cost per unit"></div></div>
                        </div>
                    </div></div>
                @else
                    <div class="col-12"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" @checked(old('is_active', $e->is_active))> <span class="form-check-label">Active (shown in lists and stocktakes)</span></label></div>
                @endif
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
@if ($errors->hasAny(['name', 'sku', 'unit', 'category_id', 'reorder_level', 'opening_qty']))
    <script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('itemModal')).show());</script>
@endif
