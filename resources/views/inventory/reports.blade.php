@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Inventory\StockItem;
    use App\Enums\StockMovementType;
    $sym = config('hotel.currency_symbol');
    $m = fn ($v) => ($v < 0 ? '−' : '') . $sym . number_format(abs((float) $v), 2);
    $q = fn ($v) => StockItem::qty($v);
    $tabs = ['valuation' => ['Stock value', 'ri-safe-2-line'], 'margins' => ['Bar & restaurant margins', 'ri-percent-line'], 'losses' => ['Waste & differences', 'ri-delete-bin-6-line'], 'movements' => ['All movements', 'ri-exchange-line']];
    $base = array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'location' => $locationId]);
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Stock Reports" icon="ri-bar-chart-2-line" :subtitle="$tab === 'valuation' ? 'Value of stock on hand right now' : $from->format('j M') . ' – ' . $to->format('j M Y')" />
    @include('inventory.partials.nav')

    <div class="rs-tabs">
        @foreach ($tabs as $key => [$label, $icon])
            <a href="{{ route('inventory.reports', ['tab' => $key] + $base) }}" class="rs-tab {{ $tab === $key ? 'active' : '' }}"><i class="{{ $icon }}"></i> {{ $label }}</a>
        @endforeach
    </div>

    @if ($tab !== 'valuation')
        <form method="GET" class="cb-card cb-toolbar mb-3">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <input type="date" name="from" class="cb-select" value="{{ $from->toDateString() }}" aria-label="From">
            <input type="date" name="to" class="cb-select" value="{{ $to->toDateString() }}" aria-label="To">
            @if ($tab === 'margins')
                <select name="outlet" class="cb-select" aria-label="Outlet"><option value="">All outlets</option>@foreach ($data['outlets'] as $o)<option value="{{ $o->id }}" @selected(request('outlet') == $o->id)>{{ $o->name }}</option>@endforeach</select>
            @else
                <select name="location" class="cb-select" aria-label="Store"><option value="">All stores</option>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected($locationId === $l->id)>{{ $l->name }}</option>@endforeach</select>
            @endif
            @if ($tab === 'movements')
                <select name="type" class="cb-select" aria-label="Kind"><option value="">Every kind</option>@foreach (StockMovementType::cases() as $t)<option value="{{ $t->value }}" @selected($data['type'] === $t)>{{ $t->label() }}</option>@endforeach</select>
                <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $data['q'] }}" placeholder="Item…" aria-label="Item"></div>
            @endif
            <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Show</button>
            <a href="{{ route('inventory.reports', ['tab' => $tab, 'from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()]) }}" class="action-btn btn-more">This month</a>
            <button type="button" class="action-btn btn-more ms-auto" onclick="window.print()"><i class="ri-printer-line"></i> Print</button>
        </form>
    @endif

    @if ($tab === 'valuation')
        <div class="row g-3 mb-1">
            <div class="col-6 col-lg-3"><x-cb.stat label="Total stock value" :value="$m($data['total'])" icon="ri-safe-2-line" accent="teal" /></div>
            @foreach ($locations->take(3) as $l)
                <div class="col-6 col-lg-3"><x-cb.stat :label="$l->name" :value="$m($data['byLocation'][$l->id] ?? 0)" icon="ri-store-2-line" accent="sky" /></div>
            @endforeach
        </div>
        <div class="cb-card">
            <div class="table-responsive">
                <table class="cb-table">
                    <thead><tr><th>Category</th>@foreach ($locations as $l)<th class="num">{{ $l->name }}</th>@endforeach<th class="num">Total</th></tr></thead>
                    <tbody>
                        @foreach ($data['grid'] as $catId => $row)
                            <tr>
                                <td class="fw-semibold">{{ $categories->firstWhere('id', $catId)?->name ?? 'Uncategorised' }}</td>
                                @foreach ($locations as $l)<td class="num">{{ isset($row[$l->id]) ? $m($row[$l->id]) : '—' }}</td>@endforeach
                                <td class="num fw-bold">{{ $m($row->sum()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td class="fw-bold">Total</td>@foreach ($locations as $l)<td class="num fw-bold">{{ $m($data['byLocation'][$l->id] ?? 0) }}</td>@endforeach<td class="num fw-bold">{{ $m($data['total']) }}</td></tr></tfoot>
                </table>
            </div>
            <div class="p-3 small text-muted">Valued at weighted average cost. Stores below zero are not counted.</div>
        </div>
    @endif

    @if ($tab === 'margins')
        @php $gp = $data['revenue'] - $data['ledgerCost']; @endphp
        <div class="row g-3 mb-1">
            <div class="col-6 col-lg-3"><x-cb.stat label="Sales" :value="$m($data['revenue'])" icon="ri-goblet-line" accent="teal" /></div>
            <div class="col-6 col-lg-3"><x-cb.stat label="Cost of stock used" :value="$m($data['ledgerCost'])" icon="ri-archive-line" accent="amber" hint="booked at the time of sale" /></div>
            <div class="col-6 col-lg-3"><x-cb.stat label="Gross profit" :value="$m($gp)" icon="ri-line-chart-line" accent="green" /></div>
            <div class="col-6 col-lg-3"><x-cb.stat label="Gross margin" :value="$data['revenue'] > 0 ? number_format($gp / $data['revenue'] * 100, 1) . '%' : '—'" icon="ri-percent-line" accent="violet" /></div>
        </div>
        @if ($data['noRecipe'] > 0)
            <div class="cb-banner warning"><i class="ri-error-warning-line"></i><div>{{ $data['noRecipe'] }} item{{ $data['noRecipe'] === 1 ? '' : 's' }} sold without a recipe — their cost isn't known, so the margin above is too high. @canany(['Manage stock items', 'Manage POS menu'])<a href="{{ route('inventory.recipes.index', ['show' => 'missing']) }}" class="fw-bold">Add recipes</a>@endcanany</div></div>
        @endif
        <div class="cb-card">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Item</th><th class="num">Sold</th><th class="num">Sales</th><th class="num">Cost*</th><th class="num">Profit</th><th class="num">Margin</th></tr></thead>
                    <tbody>
                        @forelse ($data['rows'] as $r)
                            <tr>
                                <td data-label="Item" class="fw-semibold">{{ $r->name }}</td>
                                <td data-label="Sold" class="num">{{ $q($r->qty) }}</td>
                                <td data-label="Sales" class="num">{{ $m($r->revenue) }}</td>
                                <td data-label="Cost" class="num">{{ $r->cost !== null ? $m($r->cost) : '—' }}</td>
                                <td data-label="Profit" class="num fw-semibold">{{ $r->margin !== null ? $m($r->margin) : '—' }}</td>
                                <td data-label="Margin" class="num fw-bold {{ $r->pct !== null && $r->pct < 30 ? 'text-danger' : '' }}">{{ $r->pct !== null ? number_format($r->pct, 0) . '%' : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">No sales in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 small text-muted">* Per item: recipe × today's average cost. The totals above use the cost actually booked when each sale was made.</div>
        </div>
    @endif

    @if ($tab === 'losses')
        <div class="row g-3 mb-1">
            <div class="col-6 col-lg-3"><x-cb.stat label="Waste & breakage" :value="$m($data['byType']['waste'])" icon="ri-delete-bin-6-line" accent="rose" /></div>
            <div class="col-6 col-lg-3"><x-cb.stat label="Used in-house" :value="$m($data['byType']['issue'])" icon="ri-hand-heart-line" accent="sky" /></div>
            <div class="col-6 col-lg-3"><x-cb.stat label="Stocktake differences" :value="$m($data['byType']['count'])" icon="ri-list-check-2" accent="amber" /></div>
            <div class="col-6 col-lg-3"><x-cb.stat label="Corrections" :value="$m($data['byType']['adjustment'])" icon="ri-scales-3-line" accent="violet" /></div>
        </div>
        <div class="cb-card">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Item</th><th class="num">Waste</th><th class="num">Used in-house</th><th class="num">Stocktake</th><th class="num">Corrections</th><th class="num">Value</th></tr></thead>
                    <tbody>
                        @forelse ($data['rows'] as $r)
                            <tr>
                                <td data-label="Item"><a href="{{ route('inventory.items.show', $r->item) }}" class="fw-semibold text-reset">{{ $r->item->name }}</a></td>
                                @foreach (['waste', 'issue', 'count', 'adjustment'] as $t)
                                    <td data-label="{{ StockMovementType::from($t)->label() }}" class="num {{ ($r->types[$t]['qty'] ?? 0) < 0 ? 'text-danger' : '' }}">{{ isset($r->types[$t]) ? ($r->types[$t]['qty'] > 0 ? '+' : '') . $q($r->types[$t]['qty']) . ' ' . $r->item->unit : '—' }}</td>
                                @endforeach
                                <td data-label="Value" class="num fw-bold {{ $r->value < 0 ? 'text-danger' : 'text-success' }}">{{ $m($r->value) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">Nothing lost or corrected in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($tab === 'movements')
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach ($data['summary'] as $s)
                <span class="status-pill {{ $s->type->pill() }}"><i class="{{ $s->type->icon() }}"></i> {{ $s->type->label() }}: {{ $s->n }} · {{ $m($s->value) }}</span>
            @endforeach
        </div>
        <div class="cb-card">
            @if ($data['movements']->isEmpty())
                <div class="empty-state py-5"><i class="ri-exchange-line"></i><h6>No movements</h6></div>
            @else
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>When</th><th>Item</th><th>What</th><th>Store</th><th class="num">Qty</th><th class="num">Value</th></tr></thead>
                        <tbody>
                            @foreach ($data['movements'] as $mv)
                                @php $link = $mv->sourceLink(); @endphp
                                <tr>
                                    <td data-label="When" class="small text-nowrap">{{ $mv->occurred_at->format('j M, g:i A') }}</td>
                                    <td data-label="Item"><a href="{{ route('inventory.items.show', $mv->item) }}" class="fw-semibold text-reset">{{ $mv->item->name }}</a></td>
                                    <td data-label="What"><span class="status-pill {{ $mv->type->pill() }}">{{ $mv->type->label() }}</span>@if ($link) <a href="{{ $link[1] }}" class="small">{{ $link[0] }}</a>@endif
                                        @if ($mv->note)<div class="small text-muted">{{ $mv->note }}</div>@endif<div class="small text-muted">{{ $mv->user?->name ?? 'System' }}</div></td>
                                    <td data-label="Store" class="small">{{ $mv->location->name }}</td>
                                    <td data-label="Qty" class="num fw-bold {{ $mv->quantity < 0 ? 'text-danger' : 'text-success' }}">{{ $mv->quantity > 0 ? '+' : '' }}{{ $q($mv->quantity) }}</td>
                                    <td data-label="Value" class="num">{{ $m($mv->value) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="p-3">{{ $data['movements']->links() }}</div>
            @endif
        </div>
    @endif

</div>
</div>
</div>
@endsection
