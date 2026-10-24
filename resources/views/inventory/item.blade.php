@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Inventory\StockItem;
    use App\Enums\StockMovementType;
    $q = fn ($v) => StockItem::qty($v);
    $onHand = $item->onHand();
    $low = $item->isLow($onHand);
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$item->name" icon="ri-archive-line" :subtitle="($item->category?->name ?? 'Uncategorised') . ' · counted in ' . $item->unit . ($item->sku ? ' · ' . $item->sku : '')" :back="route('inventory.index')" back-label="Stock">
        <x-slot:pills>
            <span class="cb-meta-pill"><i class="ri-stack-line"></i> {{ $q($onHand) }} {{ $item->unit }} on hand</span>
            <span class="cb-meta-pill"><i class="ri-price-tag-3-line"></i> Avg cost @money($item->avg_cost)</span>
            @if ($low)<span class="cb-meta-pill" style="background:#d97706;border-color:#d97706"><i class="ri-alarm-warning-line"></i> At or below reorder level</span>@endif
            @unless ($item->is_active)<span class="cb-meta-pill"><i class="ri-eye-off-line"></i> Inactive</span>@endunless
        </x-slot:pills>
        <x-slot:actions>
            @can('Adjust stock')<button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#adjustModal"><i class="ri-scales-3-line"></i> Use / waste / correct</button>@endcan
            @can('Receive stock')<a class="cb-hero-btn" href="{{ route('inventory.receipts.create') }}"><i class="ri-truck-line"></i> Receive</a>@endcan
            @can('Manage stock items')<button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#itemModal"><i class="ri-pencil-line"></i> Edit</button>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3">
        <div class="col-xl-4">
            <x-cb.card title="By store" icon="ri-store-2-line">
                @forelse ($item->levels->sortBy(fn ($l) => $l->location->sort_order) as $l)
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span>{{ $l->location->name }}</span>
                        <strong class="{{ $l->quantity < 0 ? 'text-danger' : '' }}">{{ $q($l->quantity) }} <span class="small text-muted fw-normal">{{ $item->unit }}</span></strong>
                    </div>
                @empty
                    <div class="text-muted small">Not stocked anywhere yet.</div>
                @endforelse
                <div class="d-flex justify-content-between pt-2"><span class="fw-bold">Total</span><span class="fw-bold">{{ $q($onHand) }} {{ $item->unit }} · @money(max(0, $onHand) * (float) $item->avg_cost)</span></div>
            </x-cb.card>

            <x-cb.card title="Details" icon="ri-information-line">
                <dl class="rm-meta mb-0" style="grid-template-columns: 130px 1fr">
                    <dt>Reorder at</dt><dd>{{ $item->reorder_level > 0 ? $q($item->reorder_level) . ' ' . $item->unit : 'Not set' }}</dd>
                    <dt>Usual order</dt><dd>{{ $item->reorder_qty ? $q($item->reorder_qty) . ' ' . $item->unit : '—' }}</dd>
                    <dt>Supplier</dt><dd>{{ $item->supplier?->name ?? '—' }}</dd>
                    <dt>Last cost</dt><dd>{{ $item->last_cost !== null ? config('hotel.currency_symbol') . number_format((float) $item->last_cost, 2) : '—' }}</dd>
                    @if ($item->notes)<dt>Notes</dt><dd>{{ $item->notes }}</dd>@endif
                </dl>
            </x-cb.card>

            <x-cb.card title="Last 30 days" icon="ri-calendar-line">
                @forelse ($month as $type => $row)
                    @php $t = StockMovementType::from($type); @endphp
                    <div class="d-flex justify-content-between py-1 small"><span><i class="{{ $t->icon() }} text-muted"></i> {{ $t->label() }}</span><span>{{ $row['qty'] > 0 ? '+' : '' }}{{ $q($row['qty']) }} · @money(abs($row['val']))</span></div>
                @empty
                    <div class="small text-muted">No movement.</div>
                @endforelse
            </x-cb.card>

            @if ($prices->isNotEmpty())
                @php $bySupplier = $prices->groupBy('supplier')->map(fn ($g) => ['last' => (float) $g->first()->unit_cost, 'low' => (float) $g->min('unit_cost'), 'n' => $g->count()]); @endphp
                <x-cb.card title="Prices paid" icon="ri-price-tag-3-line">
                    @if ($bySupplier->count() > 1)
                        <div class="small text-muted mb-1">By supplier (latest · lowest)</div>
                        @foreach ($bySupplier->sortBy('last') as $name => $row)
                            <div class="d-flex justify-content-between py-1 small {{ $loop->first ? 'fw-semibold text-success' : '' }}"><span>{{ $name ?: 'Unknown' }}</span><span>@money($row['last']) · @money($row['low'])</span></div>
                        @endforeach
                        <hr class="my-2">
                    @endif
                    @foreach ($prices->take(6) as $p)
                        @php $prev = $prices[$loop->index + 1] ?? null; $chg = $prev && (float) $prev->unit_cost > 0 ? round(((float) $p->unit_cost - (float) $prev->unit_cost) / (float) $prev->unit_cost * 100, 1) : null; @endphp
                        <div class="d-flex justify-content-between py-1 small"><span>{{ \Carbon\Carbon::parse($p->received_on)->format('j M Y') }} · {{ $p->supplier ?? '—' }}</span>
                            <span>@money($p->unit_cost)@if ($chg)<span class="{{ $chg > (float) config('hotel.procurement.price_jump_percent') ? 'text-danger fw-semibold' : 'text-muted' }}"> {{ $chg > 0 ? '+' : '' }}{{ $chg }}%</span>@endif</span></div>
                    @endforeach
                </x-cb.card>
            @endif

            @if ($item->usedIn->isNotEmpty())
                <x-cb.card title="Sold in" icon="ri-goblet-line">
                    @foreach ($item->usedIn as $c)
                        <div class="d-flex justify-content-between py-1 small"><span>{{ $c->posItem?->name }}</span><span class="text-muted">{{ $q($c->quantity) }} {{ $item->unit }} each</span></div>
                    @endforeach
                </x-cb.card>
            @endif
        </div>

        <div class="col-xl-8">
            <div class="cb-card">
                <form method="GET" class="cb-toolbar">
                    <span class="fw-bold" style="color: var(--cb-heading)"><i class="ri-history-line"></i> Stock card</span>
                    <select name="location" class="cb-select ms-auto" aria-label="Store" onchange="this.form.submit()">
                        <option value="">All stores</option>
                        @foreach ($locations as $l)<option value="{{ $l->id }}" @selected(request('location') == $l->id)>{{ $l->name }}</option>@endforeach
                    </select>
                    <select name="type" class="cb-select" aria-label="Kind" onchange="this.form.submit()">
                        <option value="">Every kind</option>
                        @foreach (StockMovementType::cases() as $t)<option value="{{ $t->value }}" @selected($type === $t)>{{ $t->label() }}</option>@endforeach
                    </select>
                </form>
                @if ($movements->isEmpty())
                    <div class="empty-state py-5"><i class="ri-history-line"></i><h6>No movements</h6></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>When</th><th>What</th><th>Store</th><th class="num">In / out</th><th class="num">Balance</th><th class="num">Value</th></tr></thead>
                            <tbody>
                                @foreach ($movements as $m)
                                    @php $link = $m->sourceLink(); @endphp
                                    <tr>
                                        <td data-label="When" class="small text-nowrap">{{ $m->occurred_at->format('j M Y') }}<div class="text-muted">{{ $m->occurred_at->format('g:i A') }}</div></td>
                                        <td data-label="What"><span class="status-pill {{ $m->type->pill() }}"><i class="{{ $m->type->icon() }}"></i> {{ $m->type->label() }}</span>
                                            @if ($link)<a href="{{ $link[1] }}" class="small ms-1">{{ $link[0] }}</a>@endif
                                            @if ($m->note)<div class="small text-muted">{{ $m->note }}</div>@endif
                                            <div class="small text-muted">{{ $m->user?->name ?? 'System' }}</div></td>
                                        <td data-label="Store" class="small">{{ $m->location->name }}</td>
                                        <td data-label="In / out" class="num fw-bold {{ $m->quantity < 0 ? 'text-danger' : 'text-success' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ $q($m->quantity) }}</td>
                                        <td data-label="Balance" class="num {{ $m->balance_after < 0 ? 'text-danger fw-bold' : '' }}">{{ $q($m->balance_after) }}</td>
                                        <td data-label="Value" class="num small">@money(abs($m->value))<div class="text-muted">@ {{ number_format((float) $m->unit_cost, 2) }}</div></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $movements->links() }}</div>
                @endif
            </div>
        </div>
    </div>

</div>
</div>
</div>

@can('Manage stock items')
    @include('inventory.partials.item-modal', ['item' => $item])
@endcan

@can('Adjust stock')
<div class="modal fade" id="adjustModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('inventory.items.adjust', $item) }}" id="adjForm">
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-scales-3-line me-1"></i> {{ $item->name }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label>What happened?</label>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach (StockMovementType::manual() as $t)
                            <input type="radio" class="btn-check" name="type" id="adj{{ $t->value }}" value="{{ $t->value }}" @checked(old('type', 'waste') === $t->value)>
                            <label class="btn btn-sm btn-outline-secondary" for="adj{{ $t->value }}"><i class="{{ $t->icon() }}"></i> {{ $t->label() }}</label>
                        @endforeach
                    </div>
                    <div class="small text-muted mt-1" id="adjHelp"></div></div>
                <div class="col-12 d-none" id="adjDir"><label>Direction</label>
                    <div class="d-flex gap-3">
                        <label class="form-check mb-0"><input type="radio" class="form-check-input" name="direction" value="out" checked> <span class="form-check-label">Less than the system shows</span></label>
                        <label class="form-check mb-0"><input type="radio" class="form-check-input" name="direction" value="in"> <span class="form-check-label">More</span></label>
                    </div></div>
                <div class="col-7"><label for="adjLoc">Store</label>
                    <select name="location_id" id="adjLoc" class="form-select" required>
                        @foreach ($locations as $l)<option value="{{ $l->id }}" @selected(old('location_id') == $l->id)>{{ $l->name }} ({{ $q($item->levels->firstWhere('location_id', $l->id)?->quantity ?? 0) }})</option>@endforeach
                    </select></div>
                <div class="col-5"><label for="adjQty">Quantity</label><div class="input-group"><input type="number" name="quantity" id="adjQty" class="form-control" min="0.001" step="0.001" required value="{{ old('quantity') }}"><span class="input-group-text">{{ $item->unit }}</span></div></div>
                <div class="col-12"><label for="adjReason">Reason <span class="text-danger">*</span></label><input name="reason" id="adjReason" class="form-control" maxlength="255" required value="{{ old('reason') }}" placeholder="e.g. Bottles broken during delivery"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const f = document.getElementById('adjForm'), help = {
        issue: 'Used inside the hotel — e.g. amenities put in rooms, staff meals, cleaning.',
        waste: 'Broken, spoiled or expired — taken out of stock.',
        adjustment: 'Correct a mistake when you know the right figure. For a full recount use a stocktake.',
    };
    const sync = () => { const t = f.querySelector('[name=type]:checked').value; document.getElementById('adjHelp').textContent = help[t] || ''; document.getElementById('adjDir').classList.toggle('d-none', t !== 'adjustment'); };
    f.querySelectorAll('[name=type]').forEach(r => r.addEventListener('change', sync)); sync();
    @if ($errors->hasAny(['quantity', 'reason', 'location_id']) || (session('error') && old('reason')))
        bootstrap.Modal.getOrCreateInstance(document.getElementById('adjustModal')).show();
    @endif
});
</script>
@endcan
@endsection
