@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Suppliers" icon="ri-store-3-line" subtitle="Who the hotel buys from">
        <x-slot:actions>@can('Manage suppliers')<button type="button" class="cb-hero-btn" data-supplier="new"><i class="ri-add-line"></i> Add supplier</button>@endcan</x-slot:actions>
    </x-cb.hero>
    @include('inventory.partials.nav')
    @include('rooms.partials.flash')

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $q }}" placeholder="Name, contact or phone…" aria-label="Search"></div>
        </form>
        @if ($suppliers->isEmpty())
            <div class="empty-state py-5"><i class="ri-store-3-line"></i><h6>No suppliers yet</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Supplier</th><th>Contact</th><th class="num">Orders</th><th>Last delivery</th><th class="num">Bought</th><th class="num">Owed</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($suppliers as $s)
                            <tr style="{{ $s->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Supplier"><span class="fw-bold" style="color: var(--cb-heading)">{{ $s->name }}</span>@unless ($s->is_active) <span class="status-pill st-muted">Inactive</span>@endunless
                                    @if ($s->address)<div class="small text-muted">{{ $s->address }}</div>@endif</td>
                                <td data-label="Contact" class="small">{{ $s->contact_person }}@if ($s->phone)<div><a href="tel:{{ $s->phone }}">{{ $s->phone }}</a></div>@endif @if ($s->email)<div><a href="mailto:{{ $s->email }}">{{ $s->email }}</a></div>@endif</td>
                                <td data-label="Orders" class="num">{{ $s->purchase_orders_count }}</td>
                                <td data-label="Last delivery" class="small">{{ $s->last_delivery ? \Carbon\Carbon::parse($s->last_delivery)->format('j M Y') : '—' }}</td>
                                <td data-label="Bought" class="num fw-semibold">@money($s->bought ?? 0)</td>
                                <td data-label="Owed" class="num">@php $owed = round((float) $s->invoiced - (float) $s->invoice_paid, 2); @endphp
                                    @if ($owed > 0)@canany(['Manage supplier invoices', 'Pay suppliers'])<a href="{{ route('inventory.invoices.index', ['supplier' => $s->id]) }}" class="fw-semibold">@money($owed)</a>@else @money($owed) @endcanany @else<span class="text-muted">—</span>@endif
                                    <div class="small text-muted">{{ $s->payment_terms_days }}-day terms</div></td>
                                <td class="text-end text-nowrap">
                                    @can('Manage purchase orders')<a href="{{ route('inventory.po.create', ['supplier' => $s->id]) }}" class="action-btn btn-open" title="New order"><i class="ri-file-add-line"></i></a>@endcan
                                    @can('Manage suppliers')
                                        <button type="button" class="action-btn btn-more" data-supplier="{{ json_encode($s->only(['id', 'name', 'contact_person', 'phone', 'email', 'address', 'payment_terms_days', 'notes', 'is_active'])) }}"><i class="ri-pencil-line"></i></button>
                                        <form method="POST" action="{{ route('inventory.suppliers.destroy', $s) }}" class="d-inline" onsubmit="return confirm('Remove {{ addslashes($s->name) }}?');">@csrf @method('DELETE')<button class="action-btn btn-danger-soft" type="submit" title="Remove"><i class="ri-delete-bin-line"></i></button></form>
                                    @endcan
                                </td>
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

@can('Manage suppliers')
<div class="modal fade" id="supplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('inventory.suppliers.store') }}" id="supplierForm">
            @csrf <input type="hidden" name="_method" value="POST">
            <div class="modal-header"><h5 class="modal-title" data-title>Add supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label for="sp_name">Name <span class="text-danger">*</span></label><input name="name" id="sp_name" class="form-control" maxlength="150" required></div>
                <div class="col-sm-6"><label for="sp_contact_person">Contact person</label><input name="contact_person" id="sp_contact_person" class="form-control" maxlength="100"></div>
                <div class="col-sm-6"><label for="sp_phone">Phone</label><input name="phone" id="sp_phone" class="form-control" maxlength="30" inputmode="tel"></div>
                <div class="col-12"><label for="sp_email">Email</label><input type="email" name="email" id="sp_email" class="form-control" maxlength="150"></div>
                <div class="col-sm-8"><label for="sp_address">Address</label><input name="address" id="sp_address" class="form-control" maxlength="255"></div>
                <div class="col-sm-4"><label for="sp_payment_terms_days">Pay within (days)</label><input type="number" name="payment_terms_days" id="sp_payment_terms_days" class="form-control" min="0" max="365" value="30"></div>
                <div class="col-12"><label for="sp_notes">Notes</label><input name="notes" id="sp_notes" class="form-control" maxlength="255" placeholder="e.g. Delivers Tue & Fri; 30 days credit"></div>
                <div class="col-12" id="sp_active_wrap"><label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="sp_is_active" checked> <span class="form-check-label">Active</span></label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('supplierForm'), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('supplierModal'));
    document.querySelectorAll('[data-supplier]').forEach(b => b.addEventListener('click', () => {
        const d = b.dataset.supplier === 'new' ? null : JSON.parse(b.dataset.supplier);
        form.reset();
        form.action = d ? @json(route('inventory.suppliers.update', '__ID__')).replace('__ID__', d.id) : @json(route('inventory.suppliers.store'));
        form.querySelector('[name=_method]').value = d ? 'PUT' : 'POST';
        form.querySelector('[data-title]').textContent = d ? 'Edit supplier' : 'Add supplier';
        document.getElementById('sp_active_wrap').classList.toggle('d-none', !d);
        if (d) Object.entries(d).forEach(([k, v]) => { const el = document.getElementById('sp_' + k); if (el) { if (el.type === 'checkbox') el.checked = !!v; else el.value = v ?? ''; } });
        modal.show();
    }));
});
</script>
@endcan
@endsection
