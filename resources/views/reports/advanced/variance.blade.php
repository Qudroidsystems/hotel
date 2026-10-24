@php $t = $data['totals']; @endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Stocktakes posted" :value="$t['counts']" icon="ri-list-check-2" accent="teal" :hint="number_format($t['lines']) . ' item counts'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Count accuracy" :value="$pct($t['accuracy'])" icon="ri-focus-3-line" accent="green" hint="items counted exactly as expected" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Found short" :value="$fmt($t['short'])" icon="ri-arrow-down-line" accent="rose" :hint="'over ' . $fmt($t['over'])" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Net difference" :value="$fmt($t['net'])" icon="ri-scales-3-line" accent="amber" hint="at cost when counted" /></div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <x-cb.card title="Stocktakes" icon="ri-file-list-3-line" :flush="true">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Stocktake</th><th>Store</th><th class="num">Accuracy</th><th class="num">Short</th><th class="num">Over</th><th class="num">Net</th></tr></thead>
                    <tbody>
                        @forelse ($data['counts'] as $r)
                            <tr>
                                <td data-label="Stocktake"><a href="{{ route('inventory.counts.show', $r['count']) }}" class="rs-ref">{{ $r['count']->number }}</a><div class="small text-muted">{{ $r['count']->posted_at?->format('j M Y') }}@if ($r['count']->category) · {{ $r['count']->category->name }}@endif</div></td>
                                <td data-label="Store">{{ $r['count']->location?->name }}</td>
                                <td data-label="Accuracy" class="num">{{ $pct($r['accuracy'], 0) }}<div class="small text-muted">{{ $r['off'] }} of {{ $r['lines'] }} off</div></td>
                                <td data-label="Short" class="num text-danger">{{ $fmt($r['short']) }}</td>
                                <td data-label="Over" class="num">{{ $fmt($r['over']) }}</td>
                                <td data-label="Net" class="num fw-semibold {{ $r['net'] < 0 ? 'text-danger' : '' }}">{{ $fmt($r['net']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">No stocktakes posted in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-cb.card>
    </div>
    <div class="col-xl-6">
        <x-cb.card title="Items with differences" icon="ri-error-warning-line" :count="$data['items']->count()" :flush="true">
            <div class="table-responsive" style="max-height: 560px; overflow-y: auto;">
                <table class="cb-table stack">
                    <thead><tr><th>Item</th><th class="num">Times off</th><th class="num">Net quantity</th><th class="num">Net value</th></tr></thead>
                    <tbody>
                        @forelse ($data['items'] as $i)
                            <tr>
                                <td data-label="Item">@if ($i->item)<a href="{{ route('inventory.items.show', $i->item) }}">{{ $i->item->name }}</a>@else — @endif</td>
                                <td data-label="Times off" class="num">{{ $i->times }}@if ($i->times > 1) <span class="status-pill st-warning">repeat</span>@endif</td>
                                <td data-label="Net quantity" class="num">{{ $i->qty > 0 ? '+' : '' }}{{ $qty($i->qty) }} <span class="small text-muted">{{ $i->item?->unit }}</span></td>
                                <td data-label="Net value" class="num fw-semibold {{ $i->value < 0 ? 'text-danger' : '' }}">{{ $fmt($i->value) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted">Every counted item matched.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-cb.card>
    </div>
</div>
<p class="ar-note">A difference is a reason to investigate — miscounts, breakages not recorded, or sales rung up late are common explanations. It does not by itself show theft.</p>
