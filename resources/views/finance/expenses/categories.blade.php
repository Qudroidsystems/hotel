@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Finance\ExpenseCategory; $sym = config('hotel.currency_symbol'); $fmt = fn ($v) => $sym . number_format((float) $v, 2); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Expense Categories & Budgets" icon="ri-folder-settings-line" :back="route('expenses.index')" back-label="Expenses" subtitle="Kinds of running cost, grouped for the profit & loss, each with an optional monthly budget">
        <x-slot:actions><button type="button" class="cb-hero-btn" data-cat="new"><i class="ri-add-line"></i> Add category</button></x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <x-cb.card :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Category</th><th>Group</th><th class="num">Monthly budget</th><th style="min-width:160px">This month</th><th class="num">This year</th><th></th></tr></thead>
                <tbody>
                    @forelse ($categories as $c)
                        @php $p = $c->monthly_budget ? (float) $c->month_spent / (float) $c->monthly_budget * 100 : null; @endphp
                        <tr style="{{ $c->is_active ? '' : 'opacity:.55' }}">
                            <td data-label="Category" class="fw-semibold">{{ $c->name }}@unless ($c->is_active) <span class="status-pill st-muted">Off</span>@endunless<div class="small text-muted fw-normal">{{ $c->expenses_count }} expense(s)</div></td>
                            <td data-label="Group" class="small">{{ $c->group_label }}</td>
                            <td data-label="Monthly budget" class="num">{{ $c->monthly_budget !== null ? $fmt($c->monthly_budget) : '—' }}</td>
                            <td data-label="This month"><div class="d-flex justify-content-between small"><span>{{ $fmt($c->month_spent ?? 0) }}</span>@if ($p !== null)<span class="{{ $p > 100 ? 'text-danger fw-semibold' : 'text-muted' }}">{{ round($p) }}%</span>@endif</div>
                                @if ($p !== null)<div class="progress-track"><div class="progress-fill" style="width: {{ min(100, $p) }}%; {{ $p > 100 ? 'background:#dc2626' : '' }}"></div></div>@endif</td>
                            <td data-label="This year" class="num">{{ $fmt($c->year_spent ?? 0) }}</td>
                            <td class="text-end"><button type="button" class="action-btn btn-more" data-cat="{{ json_encode($c->only(['id', 'name', 'group', 'monthly_budget', 'sort_order', 'is_active'])) }}"><i class="ri-pencil-line"></i></button></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">No categories yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-cb.card>

</div>
</div>
</div>

<div class="modal fade" id="catModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('expenses.categories.store') }}" id="catForm">@csrf
            <input type="hidden" name="_method" value="POST" id="catMethod">
            <div class="modal-header"><h5 class="modal-title" id="catTitle">Add category</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                @if ($errors->any())<div class="col-12"><div class="cb-banner danger mb-0"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div></div>@endif
                <div class="col-12"><label for="c_name">Name <span class="text-danger">*</span></label><input name="name" id="c_name" class="form-control" maxlength="80" required value="{{ old('name') }}" placeholder="e.g. Diesel"></div>
                <div class="col-sm-6"><label for="c_group">Group</label><select name="group" id="c_group" class="form-select">@foreach (ExpenseCategory::GROUPS as $k => $label)<option value="{{ $k }}" @selected(old('group') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label for="c_monthly_budget">Monthly budget</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" name="monthly_budget" id="c_monthly_budget" class="form-control" min="0" step="0.01" value="{{ old('monthly_budget') }}"></div></div>
                <div class="col-sm-6"><label for="c_sort_order">Order</label><input type="number" name="sort_order" id="c_sort_order" class="form-control" min="0" max="999" value="{{ old('sort_order', 0) }}"></div>
                <div class="col-sm-6 d-flex align-items-end" id="c_active_wrap"><label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="c_is_active" checked> <span class="form-check-label">In use</span></label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('catForm'), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('catModal'));
    const storeUrl = @json(route('expenses.categories.store')), updateUrl = @json(route('expenses.categories.update', '__ID__'));
    document.querySelectorAll('[data-cat]').forEach(b => b.addEventListener('click', () => {
        const c = b.dataset.cat === 'new' ? null : JSON.parse(b.dataset.cat);
        form.reset(); form.action = c ? updateUrl.replace('__ID__', c.id) : storeUrl;
        document.getElementById('catMethod').value = c ? 'PUT' : 'POST';
        document.getElementById('catTitle').textContent = c ? 'Edit ' + c.name : 'Add category';
        document.getElementById('c_active_wrap').style.display = c ? '' : 'none';
        if (c) { ['name', 'group', 'monthly_budget', 'sort_order'].forEach(k => document.getElementById('c_' + k).value = c[k] ?? ''); document.getElementById('c_is_active').checked = !!c.is_active; }
        modal.show();
    }));
    @if ($errors->any()) modal.show(); @endif
});
</script>
@endsection
