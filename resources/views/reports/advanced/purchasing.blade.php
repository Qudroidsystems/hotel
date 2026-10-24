@php $t = $data['totals']; @endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Spent on stock" :value="$fmt($t['spend'])" icon="ri-shopping-bag-3-line" accent="teal" :hint="$t['receipts'] . ' deliveries received'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Order fill rate" :value="$pct($t['fill'])" icon="ri-checkbox-circle-line" accent="green" hint="value received of value ordered" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Price rises" :value="$t['rises']" icon="ri-arrow-up-line" accent="rose" :hint="$t['flagged'] . ' above ' . rtrim(rtrim(number_format($data['jump'], 2), '0'), '.') . '% · ' . $fmt($t['extra']) . ' extra paid'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Goods rejected" :value="$fmt($t['rejected'])" icon="ri-close-circle-line" accent="amber" hint="at delivery, at invoice cost" /></div>
</div>

<x-cb.card title="By supplier" icon="ri-truck-line" :flush="true">
    <div class="table-responsive">
        <table class="cb-table stack">
            <thead><tr><th>Supplier</th><th class="num">Deliveries</th><th class="num">Spend</th><th style="min-width:140px">Share</th><th class="num">Fill rate</th><th class="num">On time</th><th class="num">Rejected</th></tr></thead>
            <tbody>
                @forelse ($data['suppliers'] as $s)
                    <tr>
                        <td data-label="Supplier" class="fw-semibold">{{ $s['supplier']?->name ?? 'No supplier recorded' }}</td>
                        <td data-label="Deliveries" class="num">{{ $s['receipts'] }}</td>
                        <td data-label="Spend" class="num fw-semibold">{{ $fmt($s['spend']) }}</td>
                        <td data-label="Share"><div class="d-flex align-items-center gap-2"><div class="progress-track flex-grow-1"><div class="progress-fill" style="width: {{ $t['spend'] > 0 ? $s['spend'] / $t['spend'] * 100 : 0 }}%"></div></div><span class="small">{{ $t['spend'] > 0 ? round($s['spend'] / $t['spend'] * 100) : 0 }}%</span></div></td>
                        <td data-label="Fill rate" class="num {{ $s['fill'] !== null && $s['fill'] < 90 ? 'text-warning fw-semibold' : '' }}">{{ $pct($s['fill'], 0) }}@if ($s['ordered'] > 0)<div class="small text-muted">{{ $fmt($s['received']) }} of {{ $fmt($s['ordered']) }}</div>@endif</td>
                        <td data-label="On time" class="num">{{ $pct($s['on_time'], 0) }}</td>
                        <td data-label="Rejected" class="num">{{ $s['rejected'] > 0 ? $fmt($s['rejected']) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">Nothing received or ordered in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="cb-card-body ar-note">Fill rate covers purchase orders placed in the period. On time compares the delivery date with the order's expected date, where one was given.</div>
</x-cb.card>

<x-cb.card title="Price changes" icon="ri-line-chart-line" :count="$data['changes']->count()" :flush="true">
    <div class="table-responsive" style="max-height: 560px; overflow-y: auto;">
        <table class="cb-table stack">
            <thead><tr><th>Date</th><th>Item</th><th>Supplier</th><th class="num">Was</th><th class="num">Now</th><th class="num">Change</th><th class="num">Extra paid</th></tr></thead>
            <tbody>
                @forelse ($data['changes'] as $ch)
                    <tr>
                        <td data-label="Date" class="text-nowrap small">{{ $ch->line->receipt->received_on->format('j M Y') }}<div><a href="{{ route('inventory.receipts.show', $ch->line->receipt) }}" class="rs-ref">{{ $ch->line->receipt->number }}</a></div></td>
                        <td data-label="Item">{{ $ch->line->item?->name }}<div class="small text-muted">{{ $qty($ch->line->quantity) }} {{ $ch->line->item?->unit }}</div></td>
                        <td data-label="Supplier">{{ $ch->line->receipt->supplier?->name ?? '—' }}</td>
                        <td data-label="Was" class="num">{{ $fmt($ch->prev) }}</td>
                        <td data-label="Now" class="num">{{ $fmt($ch->cost) }}</td>
                        <td data-label="Change" class="num fw-semibold {{ $ch->flag ? 'text-danger' : ($ch->pct < 0 ? 'text-success' : '') }}">{{ $ch->pct > 0 ? '▲' : '▼' }} {{ number_format(abs($ch->pct), 1) }}%@if ($ch->flag) <span class="status-pill st-danger">check</span>@endif</td>
                        <td data-label="Extra paid" class="num">{{ $ch->extra > 0 ? $fmt($ch->extra) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">No price changes against the previous price paid.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="cb-card-body ar-note">Each delivery line is compared with the last price paid for the same item before it. Rises above {{ rtrim(rtrim(number_format($data['jump'], 2), '0'), '.') }}% are marked — set this under Hotel Settings → Procurement.</div>
</x-cb.card>
