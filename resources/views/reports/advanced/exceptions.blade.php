@php $t = $data['totals']; @endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Discounts given" :value="$fmt($t['pos_discount'] + $t['bill_discount'])" icon="ri-price-tag-3-line" accent="amber" :hint="'bar ' . $fmt($t['pos_discount']) . ' · bills ' . $fmt($t['bill_discount'])" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Voided" :value="$fmt($t['pos_void'] + $t['charge_void'] + $t['payment_void'])" icon="ri-close-circle-line" accent="rose" :hint="'sales ' . $fmt($t['pos_void']) . ' · charges ' . $fmt($t['charge_void']) . ' · payments ' . $fmt($t['payment_void'])" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Refunds" :value="$fmt($t['refund'])" icon="ri-arrow-go-back-line" accent="teal" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Cash short / over" :value="$fmt($t['cash_short']) . ' / ' . $fmt($t['cash_over'])" icon="ri-safe-2-line" accent="green" :hint="$t['shifts'] . ' shifts closed'" /></div>
</div>

<x-cb.card title="By staff member" icon="ri-team-line" :flush="true">
    <div class="table-responsive">
        <table class="cb-table stack">
            <thead><tr><th>Staff</th><th class="num">Bar discounts</th><th class="num">Bar voids</th><th class="num">Bill discounts</th><th class="num">Charge voids</th><th class="num">Refunds</th><th class="num">Payment voids</th><th class="num">Cash short</th><th class="num">Cash over</th></tr></thead>
            <tbody>
                @forelse ($data['rows'] as $r)
                    @php $n = fn ($k) => $r[$k . '_n'] ?? null; $cell = fn ($k) => $r[$k] > 0 ? $fmt($r[$k]) . ($n($k) ? '<div class="small text-muted">' . $n($k) . '×</div>' : '') : '<span class="text-muted">—</span>'; @endphp
                    <tr>
                        <td data-label="Staff" class="fw-semibold">{{ $r['name'] }}@if ($r['shifts'])<div class="small text-muted fw-normal">{{ $r['shifts'] }} shift{{ $r['shifts'] === 1 ? '' : 's' }}</div>@endif</td>
                        @foreach (['pos_discount', 'pos_void', 'bill_discount', 'charge_void', 'refund', 'payment_void'] as $k)
                            <td data-label="{{ str($k)->headline() }}" class="num">{!! $cell($k) !!}</td>
                        @endforeach
                        <td data-label="Cash short" class="num {{ $r['cash_short'] > $data['tolerance'] ? 'text-danger fw-semibold' : '' }}">{!! $r['cash_short'] > 0 ? e($fmt($r['cash_short'])) : '<span class="text-muted">—</span>' !!}</td>
                        <td data-label="Cash over" class="num">{!! $r['cash_over'] > 0 ? e($fmt($r['cash_over'])) : '<span class="text-muted">—</span>' !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-muted">No discounts, voids, refunds or closed shifts in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-cb.card>

<x-cb.card title="Largest single items" icon="ri-list-ordered" :count="$data['item_count']" :flush="true">
    <div class="table-responsive" style="max-height: 560px; overflow-y: auto;">
        <table class="cb-table stack">
            <thead><tr><th>When</th><th>What</th><th>Ref</th><th>By</th><th>Reason / note</th><th class="num">Amount</th></tr></thead>
            <tbody>
                @forelse ($data['items'] as $i)
                    <tr>
                        <td data-label="When" class="text-nowrap small">{{ $i['at']?->format('j M, H:i') }}</td>
                        <td data-label="What">{{ $i['kind'] }} @if ($i['big'])<span class="status-pill st-danger">≥ {{ $fmt($data['flag']) }}</span>@endif</td>
                        <td data-label="Ref"><span class="rs-ref">{{ $i['ref'] ?: '—' }}</span></td>
                        <td data-label="By">{{ $i['name'] }}</td>
                        <td data-label="Reason" class="small">{{ $i['reason'] ?: '—' }}</td>
                        <td data-label="Amount" class="num fw-semibold">{{ $fmt($i['amount']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted">Nothing to show.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="cb-card-body ar-note">Shows the 50 largest. Cash differences appear here only when beyond the {{ $fmt($data['tolerance']) }} allowed. Discounts, voids and refunds are normal parts of service — this list is a starting point for a conversation, not evidence of wrongdoing. @can('View audit log')<a href="{{ route('audit.index', ['flagged' => 1, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">Flagged audit entries</a>.@endcan</div>
</x-cb.card>
