<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Total tax charged" :value="$fmt($data['total'])" icon="ri-percent-line" accent="teal" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Sales in the period" :value="$fmt($data['sales'])" icon="ri-shopping-bag-3-line" accent="green" hint="guest bills and bar & restaurant" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Sales with no tax" :value="$fmt($data['untaxed'])" icon="ri-forbid-line" accent="amber" hint="e.g. adjustments, or tax switched off" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Rates now" :value="collect(config('hotel.taxes'))->map(fn ($x) => $x['name'] . ' ' . rtrim(rtrim(number_format($x['rate'], 2), '0'), '.') . '%')->implode(', ') ?: 'None'" icon="ri-settings-4-line" accent="rose" :hint="config('hotel.prices_include_tax') ? 'included in prices' : 'added on top'" /></div>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <x-cb.card title="By tax" icon="ri-receipt-line" :flush="true">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Tax</th><th class="num">Rate</th><th class="num">Guest bills</th><th class="num">Bar &amp; rest.</th><th class="num">Total</th></tr></thead>
                    <tbody>
                        @forelse ($data['by_tax'] as $x)
                            <tr>
                                <td data-label="Tax" class="fw-semibold">{{ $x['name'] }}<div class="small text-muted fw-normal">on {{ $fmt($x['base']) }} of sales</div></td>
                                <td data-label="Rate" class="num">{{ rtrim(rtrim(number_format($x['rate'], 2), '0'), '.') }}%</td>
                                <td data-label="Guest bills" class="num">{{ $fmt($x['bills']) }}</td>
                                <td data-label="Bar & rest." class="num">{{ $fmt($x['pos']) }}</td>
                                <td data-label="Total" class="num fw-semibold">{{ $fmt($x['amount']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">No tax charged in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-cb.card>
    </div>
    <div class="col-xl-6">
        <x-cb.card title="By month" icon="ri-calendar-2-line" :flush="true">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Month</th>@foreach ($data['names'] as $n)<th class="num">{{ $n }}</th>@endforeach<th class="num">Total tax</th><th class="num">Sales</th></tr></thead>
                    <tbody>
                        @forelse ($data['by_month'] as $m)
                            <tr>
                                <td data-label="Month" class="fw-semibold">{{ $m['month']->format('F Y') }}</td>
                                @foreach ($data['names'] as $n)<td data-label="{{ $n }}" class="num">{{ $fmt($m['taxes'][$n] ?? 0) }}</td>@endforeach
                                <td data-label="Total tax" class="num fw-semibold">{{ $fmt($m['tax']) }}</td>
                                <td data-label="Sales" class="num">{{ $fmt($m['sales']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ 3 + $data['names']->count() }}" class="text-muted">No sales in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-cb.card>
    </div>
</div>
<p class="ar-note">Tax is taken from what was recorded on each charge and sale when it was made, so a rate change only affects later sales. Bar sales charged to a room are counted once, under bar &amp; restaurant. Check filing figures with your accountant.</p>
