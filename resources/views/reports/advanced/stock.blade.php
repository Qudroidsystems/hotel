@php
    $S = $data['statuses']; $c = $data['counts']; $cfg = $data['settings'];
    $filter = request('status');
    $rows = $filter && isset($S[$filter]) ? $data['rows']->where('status', $filter) : $data['rows'];
    $sq = array_filter(['report' => 'stock', 'location' => $locationId, 'category' => $categoryId]);
@endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Need ordering" :value="$c['out'] + $c['reorder'] + $c['low']" icon="ri-shopping-cart-2-line" accent="rose" :hint="$c['out'] . ' out · ' . $c['reorder'] . ' at reorder level · ' . $c['low'] . ' low'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Suggested order value" :value="$fmt($data['order_value'])" icon="ri-file-list-3-line" accent="amber" :hint="'to cover ' . $cfg['coverDays'] . ' days at last cost'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Tied up in idle stock" :value="$fmt($data['idle_value'])" icon="ri-hourglass-line" accent="teal" :hint="($c['slow'] + $c['dead']) . ' items unused ' . $cfg['slowDays'] . '+ days'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Stock value" :value="$fmt($data['value'])" icon="ri-safe-2-line" accent="green" :hint="$fmt($data['over_value']) . ' in overstocked items'" /></div>
</div>

<div class="rs-tabs">
    <a href="{{ route('reports.advanced', $sq) }}" class="rs-tab {{ ! $filter ? 'active' : '' }}">All <span class="cb-count">{{ $data['rows']->count() }}</span></a>
    @foreach ($S as $key => [$label, $pill])
        @if ($c[$key])<a href="{{ route('reports.advanced', $sq + ['status' => $key]) }}" class="rs-tab {{ $filter === $key ? 'active' : '' }}">{{ $label }} <span class="cb-count">{{ $c[$key] }}</span></a>@endif
    @endforeach
</div>

<x-cb.card :flush="true">
    <div class="table-responsive">
        <table class="cb-table stack">
            <thead><tr><th>Item</th><th class="num">On hand</th><th class="num">Value</th><th class="num">Used ({{ $cfg['usageDays'] }}d)</th><th class="num">Per day</th><th class="num">Days of cover</th><th>Last used</th><th>Status</th><th class="num">Suggested order</th></tr></thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td data-label="Item"><a href="{{ route('inventory.items.show', $r->item) }}" class="fw-semibold">{{ $r->item->name }}</a><div class="small text-muted">{{ $r->item->category?->name ?? 'Uncategorised' }}@if ((float) $r->item->reorder_level > 0) · reorder at {{ $qty($r->item->reorder_level) }}@endif</div></td>
                        <td data-label="On hand" class="num {{ $r->qty <= 0 ? 'text-danger fw-semibold' : '' }}">{{ $qty($r->qty) }} <span class="small text-muted">{{ $r->item->unit }}</span></td>
                        <td data-label="Value" class="num">{{ $fmt($r->value) }}</td>
                        <td data-label="Used" class="num">{{ $qty($r->usage) }}</td>
                        <td data-label="Per day" class="num">{{ $r->daily > 0 ? $qty(round($r->daily, 2)) : '—' }}</td>
                        <td data-label="Days of cover" class="num">@if ($r->cover === null)—@else<span class="{{ $r->cover < $cfg['coverDays'] ? 'text-danger fw-semibold' : '' }}">{{ $r->cover >= 999 ? '999+' : number_format($r->cover, 1) }}</span>@endif</td>
                        <td data-label="Last used" class="small text-nowrap">{{ $r->last_used ? $r->last_used->format('j M Y') . ' (' . $r->days_idle . 'd)' : 'Never' }}</td>
                        <td data-label="Status"><span class="status-pill {{ $S[$r->status][1] ?? 'st-muted' }}">{{ $S[$r->status][0] ?? 'Empty' }}</span></td>
                        <td data-label="Suggested order" class="num">@if ($r->suggest > 0)<strong>{{ $qty($r->suggest) }}</strong> <span class="small text-muted">{{ $r->item->unit }}</span><div class="small text-muted">≈ {{ $fmt($r->suggest_value) }}</div>@else — @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-muted">No items match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="cb-card-body ar-note d-flex justify-content-between flex-wrap gap-2">
        <span>Usage is sales, in-house use and waste{{ $locationId ? ', plus stock sent to other stores' : '' }} over the last {{ $cfg['usageDays'] }} days. Suggested orders cover {{ $cfg['coverDays'] }} days of usage{{ $locationId ? '' : ' plus the reorder level' }}, rounded up (at least the item's usual order quantity). Slow-moving = not used for {{ $cfg['slowDays'] }} days.
            @if ($locationId) Reorder levels apply to the whole hotel, so they are not used when one store is selected.@endif</span>
        @can('Manage hotel settings')<a href="{{ route('settings.hotel.index', ['tab' => 'inventory']) }}" class="no-print"><i class="ri-settings-4-line"></i> Change these</a>@endcan
    </div>
</x-cb.card>
@can('Manage purchase orders')
    @if ($c['out'] + $c['reorder'] + $c['low'] > 0)
        <a href="{{ route('inventory.po.create') }}" class="action-btn btn-go no-print"><i class="ri-add-line"></i> Raise a purchase order</a>
    @endif
@endcan
