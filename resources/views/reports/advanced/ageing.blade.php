@php $B = $data['buckets']; $gt = $data['guest_totals']; $st = $data['supplier_totals']; @endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Guests owe the hotel" :value="$fmt($data['guest_total'])" icon="ri-user-received-2-line" accent="teal" :hint="$data['guests']->count() . ' open bills'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Guest debt over 60 days" :value="$fmt($gt['d90'] + $gt['over'])" icon="ri-alarm-warning-line" accent="rose" hint="departed more than 60 days ago" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Hotel owes suppliers" :value="$fmt($data['supplier_total'])" icon="ri-truck-line" accent="amber" :hint="$data['suppliers']->sum('invoices') . ' unpaid invoices'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Supplier invoices overdue" :value="$fmt($data['supplier_total'] - $st['current'])" icon="ri-time-line" accent="green" hint="past their due date" /></div>
</div>

@foreach ([['Guests (receivables)', $gt, $data['guest_total'], 'ri-user-line'], ['Suppliers (payables)', $st, $data['supplier_total'], 'ri-truck-line']] as [$label, $tot, $all, $ico])
    @if ($loop->first)<div class="row g-3">@endif
    <div class="col-xl-6">
        <x-cb.card :title="$label . ' by age'" :icon="$ico">
            <div class="ar-stack mb-3">
                @foreach (['current' => '#0d9488', 'd30' => '#84cc16', 'd60' => '#f59e0b', 'd90' => '#f97316', 'over' => '#dc2626'] as $b => $color)
                    @if ($all > 0 && $tot[$b] > 0)<div style="width: {{ $tot[$b] / $all * 100 }}%; background: {{ $color }}" title="{{ $B[$b] }}"></div>@endif
                @endforeach
            </div>
            @foreach ($B as $b => $name)
                <div class="ar-line"><span>{{ $loop->first ? ($loop->parent->first ? 'In house' : 'Not yet due') : $name . ($loop->parent->first ? ' since departure' : ' overdue') }}</span><strong>{{ $fmt($tot[$b]) }}</strong></div>
            @endforeach
            <div class="ar-line total"><span>Total</span><span>{{ $fmt($all) }}</span></div>
        </x-cb.card>
    </div>
    @if ($loop->last)</div>@endif
@endforeach

<x-cb.card title="Guests who owe" icon="ri-user-received-2-line" :count="$data['guests']->count()" :flush="true">
    <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
        <table class="cb-table stack">
            <thead><tr><th>Bill</th><th>Guest</th><th>Status</th><th class="num">Days since departure</th><th>Age</th><th class="num">Owes</th></tr></thead>
            <tbody>
                @forelse ($data['guests'] as $g)
                    <tr>
                        <td data-label="Bill">@can('View folios')<a href="{{ route('folios.show', $g['folio']) }}" class="rs-ref">{{ $g['folio']->number }}</a>@else<span class="rs-ref">{{ $g['folio']->number }}</span>@endcan
                            @if ($g['folio']->reservation)<div class="small text-muted">{{ $g['folio']->reservation->reference }}</div>@endif</td>
                        <td data-label="Guest">{{ $g['folio']->guest?->full_name }}</td>
                        <td data-label="Status">@if ($g['in_house'])<span class="status-pill st-info">In house</span>@else<span class="status-pill st-warning">Departed</span>@endif</td>
                        <td data-label="Days" class="num">{{ $g['in_house'] ? '—' : $g['days'] }}</td>
                        <td data-label="Age"><span class="status-pill {{ in_array($g['bucket'], ['d90', 'over']) ? 'st-danger' : ($g['bucket'] === 'd60' ? 'st-warning' : 'st-muted') }}">{{ $g['in_house'] ? 'Current' : $B[$g['bucket']] }}</span></td>
                        <td data-label="Owes" class="num fw-semibold text-danger">{{ $fmt($g['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted">No guest owes money right now.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-cb.card>

<x-cb.card title="Suppliers the hotel owes" icon="ri-truck-line" :count="$data['suppliers']->count()" :flush="true">
    <div class="table-responsive">
        <table class="cb-table stack">
            <thead><tr><th>Supplier</th><th class="num">Invoices</th><th class="num">Not yet due</th><th class="num">1–30 late</th><th class="num">31–60</th><th class="num">61–90</th><th class="num">90+</th><th class="num">Total</th></tr></thead>
            <tbody>
                @forelse ($data['suppliers'] as $s)
                    <tr>
                        <td data-label="Supplier" class="fw-semibold">@canany(['Manage supplier invoices', 'Pay suppliers'])<a href="{{ route('inventory.invoices.index', ['supplier' => $s['supplier']?->id, 'show' => 'open']) }}">{{ $s['supplier']?->name }}</a>@else{{ $s['supplier']?->name }}@endcanany</td>
                        <td data-label="Invoices" class="num">{{ $s['invoices'] }}</td>
                        <td data-label="Not yet due" class="num">{{ $fmt($s['current']) }}</td>
                        <td data-label="1–30 late" class="num">{{ $fmt($s['d30']) }}</td>
                        <td data-label="31–60" class="num">{{ $fmt($s['d60']) }}</td>
                        <td data-label="61–90" class="num {{ $s['d90'] > 0 ? 'text-warning' : '' }}">{{ $fmt($s['d90']) }}</td>
                        <td data-label="90+" class="num {{ $s['over'] > 0 ? 'text-danger' : '' }}">{{ $fmt($s['over']) }}</td>
                        <td data-label="Total" class="num fw-semibold">{{ $fmt($s['total']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-muted">No unpaid supplier invoices.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-cb.card>
<p class="ar-note">Guest balances are aged from the departure date (or the date the bill was opened if it has no booking). Supplier invoices are aged from their due date.</p>
