@php $t = $data['totals']; $L = $data['losses']; @endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Net revenue (after tax)" :value="$fmt($t['net_revenue'])" icon="ri-money-dollar-circle-line" accent="teal" :hint="$fmt($t['gross_revenue']) . ' charged, ' . $fmt($t['tax']) . ' tax'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Cost of stock sold" :value="$fmt($t['cogs'])" icon="ri-shopping-basket-2-line" accent="amber" :hint="'bar & restaurant cost ' . $pct($t['fb_cost_pct']) . ' of its sales'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Gross profit" :value="$fmt($t['gross_profit'])" icon="ri-funds-line" accent="green" :hint="$pct($t['gp_pct']) . ' of net revenue'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="After stock losses & use" :value="$fmt($t['after_stock'])" icon="ri-scales-3-line" accent="rose" :hint="$fmt($t['stock_losses']) . ' waste, in-house use & differences'" /></div>
</div>

<div class="row g-3">
    <div class="col-xl-5">
        <x-cb.card title="Gross profit statement" icon="ri-file-list-3-line">
            @foreach ($data['lines'] as $l)
                <div class="ar-line"><span>{{ $l['label'] }} <span class="sub d-block">{{ $fmt($l['gross']) }} charged − {{ $fmt($l['tax']) }} tax</span></span><strong>{{ $fmt($l['net']) }}</strong></div>
            @endforeach
            <div class="ar-line total"><span>Net revenue</span><span>{{ $fmt($t['net_revenue']) }}</span></div>
            <div class="ar-line"><span>Less cost of stock sold <span class="sub d-block">from recipes, at average cost when sold</span></span><span class="text-danger">−{{ $fmt($t['cogs']) }}</span></div>
            <div class="ar-line total"><span>Gross profit</span><span>{{ $fmt($t['gross_profit']) }}</span></div>
            <div class="ar-line"><span>Stock used in-house <span class="sub d-block">housekeeping, staff meals…</span></span><span>{{ $L['issue'] ? '−' . $fmt($L['issue']) : $fmt(0) }}</span></div>
            <div class="ar-line"><span>Waste &amp; breakage</span><span>{{ $L['waste'] ? '−' . $fmt($L['waste']) : $fmt(0) }}</span></div>
            <div class="ar-line"><span>Stocktake differences</span><span>{{ $L['count'] > 0 ? '−' . $fmt($L['count']) : '+' . $fmt(-$L['count']) }}</span></div>
            <div class="ar-line"><span>Manual adjustments</span><span>{{ $L['adjustment'] > 0 ? '−' . $fmt($L['adjustment']) : '+' . $fmt(-$L['adjustment']) }}</span></div>
            <div class="ar-line total"><span>Gross profit after stock</span><span>{{ $fmt($t['after_stock']) }}</span></div>
            <p class="ar-note mt-3 mb-0">This is not a full profit &amp; loss: salaries, power, diesel and other running costs are not recorded in the system yet. Revenue is what was charged in the period (room nights by night, bar sales by sale time), not money collected.</p>
        </x-cb.card>
    </div>
    <div class="col-xl-7">
        <x-cb.card title="Bar & restaurant margin by outlet" icon="ri-goblet-line" :flush="true">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Outlet</th><th class="num">Sales</th><th class="num">Net of tax</th><th class="num">Stock cost</th><th class="num">Margin</th><th style="min-width:120px">Margin %</th></tr></thead>
                    <tbody>
                        @forelse ($data['outlets'] as $o)
                            <tr>
                                <td data-label="Outlet" class="fw-semibold">{{ $o['label'] }}@if ($o['discounts'] > 0)<div class="small text-muted fw-normal">{{ $fmt($o['discounts']) }} discounts given</div>@endif</td>
                                <td data-label="Sales" class="num">{{ $o['sales'] }}</td>
                                <td data-label="Net of tax" class="num">{{ $fmt($o['net']) }}</td>
                                <td data-label="Stock cost" class="num">{{ $fmt($o['cost']) }}</td>
                                <td data-label="Margin" class="num fw-semibold">{{ $fmt($o['margin']) }}</td>
                                <td data-label="Margin %">@if ($o['pct'] !== null)<div class="d-flex align-items-center gap-2"><div class="progress-track flex-grow-1"><div class="progress-fill" style="width: {{ max(0, min(100, $o['pct'])) }}%"></div></div><span class="small">{{ $pct($o['pct']) }}</span></div>@else — @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">No bar or restaurant sales in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="cb-card-body ar-note">Stock cost is only known for menu items with a recipe. Items without one show full margin — set recipes under Inventory → Recipes for accurate figures.</div>
        </x-cb.card>
        <x-cb.card title="Money out for stock in the period" icon="ri-truck-line">
            <div class="ar-line"><span>Goods received from suppliers</span><strong>{{ $fmt($t['purchases']) }}</strong></div>
            <div class="ar-line"><span>Paid to suppliers</span><strong>{{ $fmt($t['supplier_paid']) }}</strong></div>
            <p class="ar-note mt-2 mb-0">Purchases go into stock; they become a cost only when the stock is sold, used or lost.</p>
        </x-cb.card>
    </div>
</div>
