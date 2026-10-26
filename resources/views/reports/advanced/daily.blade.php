@php $T = $data['totals']; $max = max(1, (float) $data['days']->max(fn ($d) => data_get($d->report, 'revenue.total', 0))); @endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Occupancy" :value="$pct($T['occupancy'])" icon="ri-hotel-bed-line" accent="teal" :hint="$T['days'] . ' day(s) closed'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Average room rate" :value="$fmt($T['adr'])" icon="ri-price-tag-3-line" accent="sky" :hint="'RevPAR ' . $fmt($T['revpar'])" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Revenue" :value="$fmt($T['revenue'])" icon="ri-funds-line" accent="green" :hint="'Rooms ' . $fmt($T['room_revenue'])" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Money in" :value="$fmt($T['collected'])" icon="ri-wallet-3-line" accent="violet" :hint="$T['no_shows'] . ' no-show(s)'" /></div>
</div>
@if ($data['missing'] > 0)
    <div class="cb-banner warning"><i class="ri-moon-line"></i><div>{{ $data['missing'] }} day(s) in this period haven't been closed by the night audit, so they aren't in these figures. @can('Run night audit')<a href="{{ route('night-audit.index') }}">Close them</a>.@endcan</div></div>
@endif
<x-cb.card title="Day by day" icon="ri-calendar-line" :flush="true">
    <div class="table-responsive">
        <table class="cb-table stack">
            <thead><tr><th>Day</th><th class="num">Occupancy</th><th class="num">ADR</th><th class="num">RevPAR</th><th style="min-width:180px">Revenue</th><th class="num">Money in</th><th class="num">In / out / no-show</th></tr></thead>
            <tbody>
                @forelse ($data['days'] as $d)
                    @php $rep = $d->report; $rev = (float) data_get($rep, 'revenue.total', 0); @endphp
                    <tr>
                        <td data-label="Day"><a href="{{ route('night-audit.show', $d) }}" class="fw-semibold">{{ $d->business_date->format('D j M') }}</a></td>
                        <td data-label="Occupancy" class="num">{{ $pct(data_get($rep, 'rooms.occupancy')) }}<div class="small text-muted">{{ data_get($rep, 'rooms.sold') }}/{{ data_get($rep, 'rooms.sellable') }}</div></td>
                        <td data-label="ADR" class="num">{{ $fmt(data_get($rep, 'rooms.adr')) }}</td>
                        <td data-label="RevPAR" class="num">{{ $fmt(data_get($rep, 'rooms.revpar')) }}</td>
                        <td data-label="Revenue"><div class="d-flex justify-content-between small"><span>{{ $fmt($rev) }}</span></div><div class="ar-bar" style="width: {{ $rev / $max * 100 }}%"></div></td>
                        <td data-label="Money in" class="num">{{ $fmt(data_get($rep, 'collections.total')) }}</td>
                        <td data-label="In / out / no-show" class="num">{{ data_get($rep, 'guests.arrivals') }} / {{ data_get($rep, 'guests.departures') }} / {{ data_get($rep, 'guests.no_shows') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">No days closed in this period. The daily figures come from the night audit (Front Desk → Night Audit).</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-cb.card>
