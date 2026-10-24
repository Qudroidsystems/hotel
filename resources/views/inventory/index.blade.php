@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Inventory\StockItem;
    $sym = config('hotel.currency_symbol');
    $q = fn ($v) => StockItem::qty($v);
    $shownLocations = $f['location'] ? $locations->where('id', $f['location']) : $locations;
@endphp
<style>
    .inv-neg { color: #b91c1c; font-weight: 800; }
    .inv-zero { color: var(--cb-muted); }
    .inv-low { background: #fff7ed; }
    [data-bs-theme=dark] .inv-low { background: rgba(234, 88, 12, .08); }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Stock" icon="ri-archive-line" subtitle="What every store holds, valued at average cost">
        <x-slot:actions>
            @can('Receive stock')<a class="cb-hero-btn" href="{{ route('inventory.receipts.create') }}" style="background:#16a34a;border-color:#16a34a"><i class="ri-truck-line"></i> Receive</a>@endcan
            @can('Transfer stock')<a class="cb-hero-btn" href="{{ route('inventory.transfers.create') }}"><i class="ri-arrow-left-right-line"></i> Transfer</a>@endcan
            @can('Manage stock items')<button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#itemModal"><i class="ri-add-line"></i> New item</button>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('inventory.partials.nav')
    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat :label="$f['location'] ? 'Stock value (this store)' : 'Stock value'" :value="$sym . number_format($stats['value'], 2)" icon="ri-safe-2-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Items" :value="$stats['items']" icon="ri-archive-line" accent="sky" /></div>
        <div class="col-6 col-lg-3"><a href="{{ route('inventory.index', ['tab' => 'low']) }}" class="text-decoration-none"><x-cb.stat label="At or below reorder level" :value="$stats['low']" icon="ri-alarm-warning-line" accent="amber" /></a></div>
        <div class="col-6 col-lg-3"><a href="{{ route('inventory.index', ['tab' => 'negative']) }}" class="text-decoration-none"><x-cb.stat label="Below zero somewhere" :value="$stats['negative']" icon="ri-error-warning-line" accent="rose" hint="sales without stock booked" /></a></div>
    </div>

    <div class="rs-tabs" role="tablist">
        @foreach (['all' => ['All items', 'ri-list-check-2'], 'low' => ['Low stock', 'ri-alarm-warning-line'], 'negative' => ['Below zero', 'ri-error-warning-line']] as $key => [$label, $icon])
            <a href="{{ route('inventory.index', array_filter(['tab' => $key] + $f)) }}" class="rs-tab {{ $tab === $key ? 'active' : '' }}"><i class="{{ $icon }}"></i> {{ $label }} <span class="n">{{ $counts[$key] }}</span></a>
        @endforeach
    </div>

    @if ($tab === 'low' && $counts['low'] > 0)
        @can('Manage purchase orders')
            <form method="POST" action="{{ route('inventory.po.reorder') }}" class="cb-banner info d-flex flex-wrap align-items-center gap-2">
                @csrf
                <i class="ri-shopping-cart-2-line"></i>
                <span>Make draft purchase orders for these items, one per preferred supplier, delivered to</span>
                <select name="location_id" class="form-select form-select-sm" style="width:auto">@foreach ($locations as $l)<option value="{{ $l->id }}" @selected($l->type === 'store')>{{ $l->name }}</option>@endforeach</select>
                <button class="action-btn btn-go" type="submit">Create drafts</button>
            </form>
        @endcan
    @endif
    @if ($tab === 'negative' && $counts['negative'] > 0)
        <div class="cb-banner warning"><i class="ri-error-warning-line"></i><div>These stores sold more than the system thought they had — usually stock moved without a transfer, or a delivery not yet received. Book the missing transfer/receipt, or do a stocktake.</div></div>
    @endif

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $f['q'] }}" placeholder="Item name or code…" aria-label="Search"></div>
            <select name="category" class="cb-select" aria-label="Category" onchange="this.form.submit()">
                <option value="">All categories</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($f['category'] === $c->id)>{{ $c->name }}</option>@endforeach
            </select>
            <select name="location" class="cb-select" aria-label="Store" onchange="this.form.submit()">
                <option value="">All stores</option>
                @foreach ($locations as $l)<option value="{{ $l->id }}" @selected($f['location'] === $l->id)>{{ $l->name }}</option>@endforeach
            </select>
            <label class="form-check mb-0 small"><input type="checkbox" class="form-check-input" name="inactive" value="1" @checked($f['inactive']) onchange="this.form.submit()"> Show inactive</label>
            <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Filter</button>
        </form>

        @if ($list->isEmpty())
            <div class="empty-state py-5"><i class="ri-archive-line"></i><h6>{{ $tab === 'all' ? 'No stock items yet' : 'Nothing here' }}</h6>
                @if ($tab === 'all')<p class="small text-muted">Add the things you buy and sell — drinks, food ingredients, guest amenities, cleaning supplies.</p>@endif</div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead>
                        <tr>
                            <th>Item</th>
                            @foreach ($shownLocations as $l)<th class="num">{{ $l->name }}</th>@endforeach
                            @if (! $f['location'])<th class="num">Total</th>@endif
                            <th class="num">Reorder at</th>
                            <th class="num">Avg cost</th>
                            <th class="num">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($list as $i)
                            @php $low = $i->isLow($i->on_hand); $valueQty = $f['location'] ? ($i->by_location[$f['location']] ?? 0) : $i->on_hand; @endphp
                            <tr class="{{ $low ? 'inv-low' : '' }}" style="{{ $i->is_active ? '' : 'opacity:.55' }}">
                                <td data-label="Item">
                                    <a href="{{ route('inventory.items.show', $i) }}" class="fw-bold text-reset">{{ $i->name }}</a>
                                    <div class="small text-muted">{{ $i->category?->name ?? 'Uncategorised' }}{{ $i->sku ? ' · ' . $i->sku : '' }}{{ $i->supplier ? ' · ' . $i->supplier->name : '' }}</div>
                                    @if ($low)<span class="status-pill st-warning"><i class="ri-alarm-warning-line"></i> Low</span>@endif
                                </td>
                                @foreach ($shownLocations as $l)
                                    @php $v = $i->by_location[$l->id] ?? null; @endphp
                                    <td data-label="{{ $l->name }}" class="num {{ $v === null || $v == 0 ? 'inv-zero' : ($v < 0 ? 'inv-neg' : '') }}">{{ $v === null ? '—' : $q($v) }}</td>
                                @endforeach
                                @if (! $f['location'])<td data-label="Total" class="num fw-bold {{ $i->on_hand < 0 ? 'inv-neg' : '' }}">{{ $q($i->on_hand) }} <span class="small text-muted fw-normal">{{ $i->unit }}</span></td>@endif
                                <td data-label="Reorder at" class="num small">{{ $i->reorder_level > 0 ? $q($i->reorder_level) : '—' }}</td>
                                <td data-label="Avg cost" class="num small">@money($i->avg_cost)</td>
                                <td data-label="Value" class="num">@money(max(0, $valueQty) * (float) $i->avg_cost)</td>
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

@can('Manage stock items')
    @include('inventory.partials.item-modal', ['item' => null])
@endcan
@endsection
