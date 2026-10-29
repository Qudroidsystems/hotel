{{-- The daily figures. $r: NightAuditService::report() --}}
@php
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => ((float) $v < 0 ? '−' : '') . $sym . number_format(abs((float) $v), 2);
@endphp
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><x-cb.stat label="Occupancy" :value="$r['rooms']['occupancy'] . '%'" icon="ri-hotel-bed-line" accent="teal" :hint="$r['rooms']['sold'] . ' of ' . $r['rooms']['sellable'] . ' rooms'" /></div>
    <div class="col-6 col-lg-3"><x-cb.stat label="Average room rate" :value="$fmt($r['rooms']['adr'])" icon="ri-price-tag-3-line" accent="sky" /></div>
    <div class="col-6 col-lg-3"><x-cb.stat label="Revenue per available room" :value="$fmt($r['rooms']['revpar'])" icon="ri-line-chart-line" accent="violet" /></div>
    <div class="col-6 col-lg-3"><x-cb.stat label="Revenue" :value="$fmt($r['revenue']['total'])" icon="ri-money-dollar-circle-line" accent="green" /></div>
</div>
<div class="row g-3">
    <div class="col-lg-4">
        <x-cb.card title="Revenue" icon="ri-funds-line">
            <div class="d-flex justify-content-between small py-1"><span>Rooms</span><strong>{{ $fmt($r['revenue']['rooms']) }}</strong></div>
            @foreach ($r['revenue']['other'] as $label => $v)<div class="d-flex justify-content-between small py-1"><span>{{ $label }}</span><span>{{ $fmt($v) }}</span></div>@endforeach
            <div class="d-flex justify-content-between small py-1"><span>Bar &amp; restaurant ({{ $r['revenue']['pos']['orders'] }} sales)</span><span>{{ $fmt($r['revenue']['pos']['sales']) }}</span></div>
            @foreach ($r['revenue']['pos']['by_outlet'] as $outlet => $v)<div class="d-flex justify-content-between small text-muted ps-3"><span>{{ $outlet }}</span><span>{{ $fmt($v) }}</span></div>@endforeach
            @if (($r['revenue']['events']['count'] ?? 0) > 0)<div class="d-flex justify-content-between small py-1"><span>Events ({{ $r['revenue']['events']['count'] }})</span><span>{{ $fmt($r['revenue']['events']['revenue']) }}</span></div>@endif
            @if (abs($r['revenue']['tickets']['revenue'] ?? 0) > 0.004)<div class="d-flex justify-content-between small py-1"><span>Event tickets ({{ $r['revenue']['tickets']['count'] }})</span><span>{{ $fmt($r['revenue']['tickets']['revenue']) }}</span></div>@endif
            <div class="d-flex justify-content-between border-top mt-2 pt-2 fw-bold" style="color:var(--cb-heading)"><span>Total</span><span>{{ $fmt($r['revenue']['total']) }}</span></div>
        </x-cb.card>
    </div>
    <div class="col-lg-4">
        <x-cb.card title="Money in" icon="ri-wallet-3-line">
            @forelse ($r['collections']['by_method'] as $label => $v)<div class="d-flex justify-content-between small py-1"><span>{{ $label }}</span><span>{{ $fmt($v) }}</span></div>
            @empty<div class="small text-muted">No payments.</div>@endforelse
            <div class="d-flex justify-content-between border-top mt-2 pt-2 fw-bold" style="color:var(--cb-heading)"><span>Total</span><span>{{ $fmt($r['collections']['total']) }}</span></div>
            @if ($r['collections']['billed_to_companies'] > 0)<div class="d-flex justify-content-between small text-muted mt-1"><span>Billed to companies (not money in)</span><span>{{ $fmt($r['collections']['billed_to_companies']) }}</span></div>@endif
        </x-cb.card>
        <x-cb.card title="Still owed" icon="ri-hand-coin-line">
            <div class="d-flex justify-content-between small py-1"><span>By guests in house</span><span>{{ $fmt($r['owed']['in_house']) }}</span></div>
            <div class="d-flex justify-content-between small py-1 {{ $r['owed']['after_stay'] > 0 ? 'text-danger' : '' }}"><span>By guests who have left</span><span>{{ $fmt($r['owed']['after_stay']) }}</span></div>
            <div class="d-flex justify-content-between small py-1"><span>By companies</span><span>{{ $fmt($r['owed']['companies']) }}</span></div>
        </x-cb.card>
    </div>
    <div class="col-lg-4">
        <x-cb.card title="Guests" icon="ri-group-line">
            @foreach (['arrivals' => 'Arrived', 'departures' => 'Left', 'no_shows' => 'No-shows', 'cancellations' => 'Cancellations', 'new_bookings' => 'New bookings', 'online' => '…of them online'] as $k => $label)
                <div class="d-flex justify-content-between small py-1"><span>{{ $label }}</span><span>{{ $r['guests'][$k] }}</span></div>
            @endforeach
            <div class="d-flex justify-content-between small py-1"><span>Value of new bookings</span><span>{{ $fmt($r['guests']['new_value']) }}</span></div>
            <div class="d-flex justify-content-between border-top mt-2 pt-2 small fw-semibold"><span>In house tonight</span><span>{{ $r['guests']['in_house_rooms'] }} room(s), {{ $r['guests']['in_house_people'] }} guest(s)</span></div>
            <div class="d-flex justify-content-between small text-muted"><span>Rooms out of order</span><span>{{ $r['rooms']['out_of_order'] }} of {{ $r['rooms']['total'] }}</span></div>
        </x-cb.card>
    </div>
</div>
