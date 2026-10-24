@php $t = $data['totals']; $rows = $data['rows']; @endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Revenue, 12 months" :value="$fmt($t['revenue'])" icon="ri-money-dollar-circle-line" accent="teal" :hint="'rooms ' . $fmt($t['room']) . ' · bar ' . $fmt($t['pos'])" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Net operating profit" :value="$fmt($t['net'])" icon="ri-funds-line" :accent="$t['net'] < 0 ? 'rose' : 'green'" :hint="'gross ' . $fmt($t['gross']) . ' − running costs ' . $fmt($t['opex'])" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Money collected" :value="$fmt($t['collected'])" icon="ri-hand-coin-line" accent="amber" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Best month" :value="$data['best'] ? $data['best']['month']->format('F Y') : '—'" icon="ri-trophy-line" accent="rose" :hint="$data['best'] ? $fmt($data['best']['revenue']) : 'no revenue yet'" /></div>
</div>

<x-cb.card title="Revenue by month" icon="ri-bar-chart-grouped-line">
    <div class="d-flex gap-3 small mb-2 flex-wrap"><span><span class="d-inline-block ar-bar" style="width:12px"></span> Rooms</span><span><span class="d-inline-block ar-bar alt" style="width:12px"></span> Bar &amp; restaurant</span><span><span class="d-inline-block ar-bar alt2" style="width:12px"></span> Other services</span></div>
    @foreach ($rows as $m)
        <div class="d-flex align-items-center gap-2 py-1">
            <span class="small text-nowrap" style="width:70px">{{ $m['month']->format('M Y') }}</span>
            <div class="flex-grow-1"><div class="d-flex" style="height:14px">
                <div class="ar-bar" style="height:14px;border-radius:3px 0 0 3px;width: {{ $m['room'] / $data['max'] * 100 }}%"></div>
                <div class="ar-bar alt" style="height:14px;border-radius:0;width: {{ $m['pos'] / $data['max'] * 100 }}%"></div>
                <div class="ar-bar alt2" style="height:14px;border-radius:0 3px 3px 0;width: {{ max(0, $m['other']) / $data['max'] * 100 }}%"></div>
            </div></div>
            <span class="small text-end text-nowrap" style="width:120px">{{ $fmt($m['revenue']) }}</span>
        </div>
    @endforeach
</x-cb.card>

<x-cb.card title="Month by month" icon="ri-table-line" :flush="true">
    <div class="table-responsive">
        <table class="cb-table stack">
            <thead><tr><th>Month</th><th class="num">Occupancy</th><th class="num">ADR</th><th class="num">RevPAR</th><th class="num">Rooms</th><th class="num">Bar &amp; rest.</th><th class="num">Other</th><th class="num">Total revenue</th><th class="num">Cost of sales</th><th class="num">Gross profit</th><th class="num">Running costs</th><th class="num">Net profit</th><th class="num">Collected</th></tr></thead>
            <tbody>
                @foreach ($rows as $m)
                    <tr>
                        <td data-label="Month" class="text-nowrap fw-semibold">{{ $m['month']->format('M Y') }}</td>
                        <td data-label="Occupancy" class="num">{{ $m['occupancy'] }}%<div class="small text-muted">{{ $m['sold'] }} nights</div></td>
                        <td data-label="ADR" class="num">{{ $fmt($m['adr']) }}</td>
                        <td data-label="RevPAR" class="num">{{ $fmt($m['revpar']) }}</td>
                        <td data-label="Rooms" class="num">{{ $fmt($m['room']) }}</td>
                        <td data-label="Bar & rest." class="num">{{ $fmt($m['pos']) }}</td>
                        <td data-label="Other" class="num">{{ $fmt($m['other']) }}</td>
                        <td data-label="Total revenue" class="num fw-semibold">{{ $fmt($m['revenue']) }}</td>
                        <td data-label="Cost of sales" class="num">{{ $fmt($m['cogs']) }}</td>
                        <td data-label="Gross profit" class="num">{{ $fmt($m['gross']) }}</td>
                        <td data-label="Running costs" class="num">{{ $fmt($m['opex']) }}</td>
                        <td data-label="Net profit" class="num fw-semibold {{ $m['net'] < 0 ? 'text-danger' : '' }}">{{ $fmt($m['net']) }}</td>
                        <td data-label="Collected" class="num">{{ $fmt($m['collected']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold"><td>12 months</td><td class="num">{{ number_format($t['sold']) }} nights</td><td></td><td></td><td class="num">{{ $fmt($t['room']) }}</td><td class="num">{{ $fmt($t['pos']) }}</td><td class="num">{{ $fmt($t['other']) }}</td><td class="num">{{ $fmt($t['revenue']) }}</td><td class="num">{{ $fmt($t['cogs']) }}</td><td class="num">{{ $fmt($t['gross']) }}</td><td class="num">{{ $fmt($t['opex']) }}</td><td class="num">{{ $fmt($t['net']) }}</td><td class="num">{{ $fmt($t['collected']) }}</td></tr></tfoot>
        </table>
    </div>
    <div class="cb-card-body ar-note">Occupancy uses today's {{ $data['rooms'] }} active rooms for every month; the current month counts nights up to today. Revenue includes tax; gross profit is after tax and the stock cost of bar &amp; restaurant sales; net profit also takes off running costs (approved expenses).</div>
</x-cb.card>
