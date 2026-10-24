{{-- What a shift took and what the drawer should hold. Needs: $shift, $totals --}}
<div class="d-flex justify-content-between py-2 border-bottom"><span>Opening float</span><strong>@money($shift->opening_float)</strong></div>
<div class="d-flex justify-content-between py-2 border-bottom"><span>Cash taken <span class="small text-muted">(bills less refunds, plus bar)</span></span><strong>@money($totals['cash_sales'])</strong></div>
@if ($totals['paid_in'] > 0)<div class="d-flex justify-content-between py-2 border-bottom"><span>Paid in</span><strong>+@money($totals['paid_in'])</strong></div>@endif
@if ($totals['paid_out'] > 0)<div class="d-flex justify-content-between py-2 border-bottom"><span>Paid out</span><strong>−@money($totals['paid_out'])</strong></div>@endif
@if ($totals['drops'] > 0)<div class="d-flex justify-content-between py-2 border-bottom"><span>Dropped to safe</span><strong>−@money($totals['drops'])</strong></div>@endif
<div class="d-flex justify-content-between py-2 border-bottom fs-6"><span class="fw-semibold">Cash that should be in the drawer</span><strong>@money($totals['expected_cash'])</strong></div>
@if ($shift->counted_cash !== null)
    <div class="d-flex justify-content-between py-2 border-bottom"><span>Counted</span><strong>@money($shift->counted_cash)</strong></div>
    <div class="d-flex justify-content-between py-2 border-bottom"><span>Difference</span>
        <strong class="{{ (float) $shift->variance < 0 ? 'text-danger' : ((float) $shift->variance > 0 ? 'text-warning' : 'text-success') }}">{{ $shift->varianceLabel() }}{{ abs((float) $shift->variance) >= 0.01 ? ' ' . config('hotel.currency_symbol') . number_format(abs((float) $shift->variance), 2) : '' }}</strong></div>
@endif

<div class="mt-3 small text-uppercase text-muted fw-semibold">Everything taken, by method</div>
@forelse ($totals['methods'] as $row)
    <div class="d-flex justify-content-between py-1 border-bottom small"><span>{{ $row['label'] }} <span class="text-muted">({{ $row['count'] }})</span></span>
        <span>@money($row['net'])@if ($row['out'] > 0) <span class="text-muted">after @money($row['out']) refunds</span>@endif</span></div>
@empty
    <div class="small text-muted py-1">Nothing taken yet.</div>
@endforelse
@if ($totals['methods'])
    <div class="d-flex justify-content-between py-1 small fw-semibold"><span>Total taken</span><span>@money($totals['total_taken'])</span></div>
@endif
@if ($totals['room_count'] > 0)
    <div class="small text-muted mt-1">Also {{ $totals['room_count'] }} bar sale(s) charged to rooms (@money($totals['room_charges'])) — not money in the drawer.</div>
@endif
@if ($totals['pos_voids'] > 0)
    <div class="small text-muted">{{ $totals['pos_voids'] }} sale(s) voided during the shift.</div>
@endif
