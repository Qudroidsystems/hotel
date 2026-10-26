@php $T = $data['totals']; $maxRev = max(1, (float) $data['by_plan']->max('revenue')); @endphp
<div class="row g-3 mb-1">
    <div class="col-6 col-xl-3"><x-cb.stat label="Stays arriving" :value="$T['bookings']" icon="ri-calendar-check-line" accent="teal" :hint="$T['nights'] . ' room nights'" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Room revenue booked" :value="$fmt($T['revenue'])" icon="ri-funds-line" accent="green" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Average a night" :value="$fmt($T['nights'] ? $T['revenue'] / $T['nights'] : 0)" icon="ri-price-tag-3-line" accent="sky" /></div>
    <div class="col-6 col-xl-3"><x-cb.stat label="Cancelled / no-show" :value="$T['lost']" icon="ri-close-circle-line" accent="rose" :hint="'Discounts given ' . $fmt($T['discount'])" /></div>
</div>
<p class="ar-note">Stays arriving in the period, at the price booked (after discounts). Cancelled and no-show bookings are counted apart.</p>
<div class="row g-3">
    <div class="col-xl-7">
        <x-cb.card title="By rate plan" icon="ri-price-tag-3-line" :flush="true">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Rate</th><th class="num">Stays</th><th class="num">Room nights</th><th style="min-width:170px">Revenue</th><th class="num">Avg a night</th><th class="num">Lost</th></tr></thead>
                    <tbody>
                        @forelse ($data['by_plan'] as $x)
                            <tr><td data-label="Rate" class="fw-semibold">{{ $x['label'] }}</td><td data-label="Stays" class="num">{{ $x['bookings'] }}</td><td data-label="Room nights" class="num">{{ $x['nights'] }}</td>
                                <td data-label="Revenue"><div class="small">{{ $fmt($x['revenue']) }}</div><div class="ar-bar" style="width: {{ $x['revenue'] / $maxRev * 100 }}%"></div></td>
                                <td data-label="Avg a night" class="num">{{ $fmt($x['avg']) }}</td><td data-label="Lost" class="num">{{ $x['lost'] ?: '—' }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="text-muted">No stays arriving in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-cb.card>
    </div>
    <div class="col-xl-5">
        <x-cb.card title="Where bookings came from" icon="ri-route-line">
            @forelse ($data['by_source'] as $x)
                <div class="ar-line"><span>{{ $x['label'] }} <span class="sub">· {{ $x['bookings'] }} stay(s), {{ $x['nights'] }} nights</span></span><strong>{{ $fmt($x['revenue']) }}</strong></div>
            @empty
                <div class="small text-muted">Nothing yet.</div>
            @endforelse
        </x-cb.card>
        @if (count($data['by_channel'] ?? []))
            <x-cb.card title="Booking sites" icon="ri-global-line">
                @foreach ($data['by_channel'] as $x)
                    <div class="ar-line"><span>{{ $x['label'] }} <span class="sub">· {{ $x['bookings'] }} stay(s), {{ $x['nights'] }} nights{{ $x['lost'] ? ', ' . $x['lost'] . ' cancelled' : '' }}</span></span><strong>{{ $fmt($x['revenue']) }}</strong></div>
                @endforeach
            </x-cb.card>
        @endif
        <x-cb.card title="Promo codes" icon="ri-coupon-3-line">
            @forelse ($data['by_promo'] as $x)
                <div class="ar-line"><span><strong>{{ $x['label'] }}</strong> <span class="sub">· {{ $x['bookings'] }} stay(s), {{ $x['nights'] }} nights</span></span><strong>{{ $fmt($x['revenue']) }}</strong></div>
            @empty
                <div class="small text-muted">No stays booked with a promo code in this period.</div>
            @endforelse
        </x-cb.card>
    </div>
</div>
