@extends('layouts.master')

@section('content')
@php $sym = config('hotel.currency_symbol'); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Diesel deliveries" icon="ri-gas-station-line" subtitle="Every delivery into a generator's tank: logged once, added to the tank, and (if you choose) recorded under Expenses. Read the tank after filling so the diesel check stays exact.">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('energy.index') }}"><i class="ri-arrow-left-line"></i> Energy Saver</a>
            <a class="cb-hero-btn" href="{{ route('energy.report') }}"><i class="ri-bar-chart-box-line"></i> Report</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div>@endif

    <div class="row g-3">
        @can('Manage energy saver')
        <div class="col-xl-4">
            <x-cb.card title="Record a delivery" icon="ri-add-circle-line">
                @if ($tanks->isEmpty())
                    <p class="small text-muted mb-0">Add your generators first on <a href="{{ route('energy.setup') }}">Places &amp; generators</a>.</p>
                @else
                <form method="POST" action="{{ route('energy.fuel.store') }}" class="row g-2" id="fuelForm">@csrf
                    <div class="col-12"><label class="form-label small">Into the tank of</label>
                        <select name="source_id" class="form-select">@foreach ($tanks as $t)<option value="{{ $t->id }}" @selected(old('source_id') == $t->id)>{{ $t->name }}{{ $t->fuel_litres !== null ? ' (' . number_format($t->fuel_litres) . ' L' . ($t->tank_litres ? ' of ' . number_format($t->tank_litres) : '') . ')' : '' }}</option>@endforeach</select></div>
                    <div class="col-6"><label class="form-label small">Litres</label><input type="number" step="0.1" min="1" name="litres" class="form-control" required value="{{ old('litres') }}"></div>
                    <div class="col-6"><label class="form-label small">Price per litre</label><div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="price_per_litre" class="form-control" value="{{ old('price_per_litre', $price) }}"></div></div>
                    <div class="col-12 small text-muted">Total <strong id="fuelTotal">—</strong></div>
                    @if ($suppliers->isNotEmpty())
                        <div class="col-12"><label class="form-label small">Supplier</label><select name="supplier_id" class="form-select"><option value="">— type a name below —</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected(old('supplier_id') == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                    @endif
                    <div class="col-12"><input name="supplier_name" class="form-control" maxlength="120" placeholder="{{ $suppliers->isNotEmpty() ? 'or another supplier' : 'Supplier' }}" value="{{ old('supplier_name') }}"></div>
                    <div class="col-6"><label class="form-label small">Invoice / waybill</label><input name="invoice_ref" class="form-control" maxlength="60" value="{{ old('invoice_ref') }}"></div>
                    <div class="col-6"><label class="form-label small">Tank reads after</label><div class="input-group"><input type="number" step="0.1" min="0" name="level_after" class="form-control" value="{{ old('level_after') }}"><span class="input-group-text">L</span></div></div>
                    <div class="col-12"><label class="form-label small">When</label><input type="datetime-local" name="delivered_at" class="form-control" value="{{ old('delivered_at', now()->format('Y-m-d\TH:i')) }}"></div>
                    <div class="col-12"><input name="notes" class="form-control" maxlength="255" placeholder="Notes (driver, truck …)" value="{{ old('notes') }}"></div>
                    @if ($canExpense)
                        <div class="col-12"><input type="hidden" name="expense" value="0"><label class="form-check"><input type="checkbox" class="form-check-input" name="expense" value="1" @checked(old('expense', true))> Add to Expenses (Diesel / generator fuel)</label></div>
                    @endif
                    <div class="col-12"><button class="btn btn-primary w-100">Record delivery</button></div>
                </form>
                @endif
            </x-cb.card>
        </div>
        @endcan

        <div class="{{ auth()->user()->can('Manage energy saver') ? 'col-xl-8' : 'col-12' }}">
            <x-cb.card title="Deliveries" icon="ri-list-check" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>When</th><th>Tank</th><th class="num">Litres</th><th class="num">Cost</th><th>Supplier</th><th>Expense</th></tr></thead>
                        <tbody>
                            @forelse ($deliveries as $d)
                                <tr>
                                    <td data-label="When" class="small">{{ $d->delivered_at->format('D j M Y, g:i A') }}<div class="text-muted">{{ collect([$d->receiver?->name, $d->invoice_ref])->filter()->join(' · ') }}</div></td>
                                    <td data-label="Tank">{{ $d->source?->name ?? '—' }}@if ($d->level_after !== null)<div class="small text-muted">read {{ number_format($d->level_after) }} L after</div>@endif</td>
                                    <td data-label="Litres" class="num">{{ number_format($d->litres) }}</td>
                                    <td data-label="Cost" class="num">{{ $d->total > 0 ? $sym . number_format($d->total, 2) : '—' }}@if ($d->price_per_litre > 0)<div class="small text-muted">{{ $sym }}{{ number_format($d->price_per_litre, 2) }}/L</div>@endif</td>
                                    <td data-label="Supplier" class="small">{{ $d->supplier_name ?? '—' }}</td>
                                    <td data-label="Expense" class="small">@if ($d->expense)@can('View expenses')<a href="{{ route('expenses.show', $d->expense) }}">{{ $d->expense->number }}</a>@else{{ $d->expense->number }}@endcan · {{ $d->expense->status }}@else — @endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted small">No deliveries recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2">{{ $deliveries->links() }}</div>
            </x-cb.card>
        </div>
    </div>
</div>
</div>
</div>
<script>
(function () {
    const f = document.getElementById('fuelForm'); if (!f) return;
    const out = document.getElementById('fuelTotal'), sym = @json($sym);
    const sum = () => { const t = (parseFloat(f.litres.value) || 0) * (parseFloat(f.price_per_litre.value) || 0); out.textContent = t > 0 ? sym + t.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—'; };
    f.litres.addEventListener('input', sum); f.price_per_litre.addEventListener('input', sum); sum();
    f.addEventListener('submit', e => { if (e.submitter) e.submitter.disabled = true; });
})();
</script>
@endsection
