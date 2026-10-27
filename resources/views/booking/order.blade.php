@extends('layouts.guest', ['title' => 'Order to your room', 'noindex' => true])

@section('content')
@php
    $sym = config('hotel.currency_symbol');
    $roomNos = $r->rooms->pluck('room.number')->filter()->implode(', ');
    $openMenus = collect($menu)->where('open', true)->values();
    $live = collect($orders)->where('live', true)->isNotEmpty();
    $pick = (int) old('outlet_id', request('outlet', $openMenus->first()['id'] ?? 0));
@endphp
<div class="wrap order">
    <div class="head">
        <div>
            <a href="{{ route('stay.show', $r->guest_token) }}" class="small back"><i class="ri-arrow-left-line"></i> Your booking</a>
            <h1 class="serif">Order to your room</h1>
            <p class="muted">{{ $roomNos ? 'Room ' . $roomNos . ' · ' : '' }}{{ $r->guest?->full_name }} · added to your bill</p>
        </div>
    </div>
    @include('rooms.partials.flash-guest')

    @if (count($orders))
        <section class="panel orders" aria-label="Your orders">
            <h2>Your orders</h2>
            @foreach ($orders as $o)
                <div class="orow">
                    <div class="grow">
                        <strong>{{ $o['outlet'] }}</strong> <span class="small muted">{{ $o['number'] }} · {{ $o['time'] }}</span>
                        <div class="small">{{ $o['summary'] }} · {{ $o['total_label'] }}</div>
                        @if ($o['eta'])<div class="small"><i class="ri-time-line"></i> {{ $o['eta'] }}</div>@endif
                        @if ($o['note'])<div class="small">{{ $o['status'] === 'declined' ? 'Kitchen: ' : '' }}{{ $o['note'] }}</div>@endif
                    </div>
                    <div class="oright">
                        <span class="pill {{ ['placed' => 'warn', 'declined' => 'bad', 'cancelled' => 'grey', 'delivered' => 'grey'][$o['status']] ?? '' }}">{{ explode(' — ', $o['status_label'])[0] }}</span>
                        @if ($o['can_cancel'])
                            <form method="POST" action="{{ route('stay.order.cancel', [$r->guest_token, $o['id']]) }}" onsubmit="return confirm('Cancel this order?')">@csrf<button class="link-btn small">Cancel</button></form>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    @if ($blocker)
        <div class="notice warn"><i class="ri-information-line"></i><div>{{ $blocker }}
            @if (collect($menu)->whereNotNull('hours')->isNotEmpty())<div class="small">{{ collect($menu)->map(fn ($m) => $m['name'] . ($m['hours'] ? ': ' . $m['hours'] : ''))->implode(' · ') }}</div>@endif
            @if (config('hotel.booking.contact_phone'))<div class="small">Reception: <a href="tel:{{ config('hotel.booking.contact_phone') }}">{{ config('hotel.booking.contact_phone') }}</a></div>@endif
        </div></div>
    @else
        @if ($openMenus->count() > 1)
            <nav class="tabs" aria-label="Menus">
                @foreach ($openMenus as $m)
                    <a href="{{ route('stay.order', [$r->guest_token, 'outlet' => $m['id']]) }}" class="{{ $m['id'] === $pick ? 'on' : '' }}" @if ($m['id'] === $pick) aria-current="page" @endif>{{ $m['name'] }}</a>
                @endforeach
            </nav>
        @endif
        @foreach ($openMenus->where('id', $pick) as $m)
            <form method="POST" action="{{ route('stay.order.place', $r->guest_token) }}" id="orderForm" data-sym="{{ $sym }}">@csrf
                <input type="hidden" name="outlet_id" value="{{ $m['id'] }}">
                @if ($m['minutes'])<p class="small muted">Usually with you in about {{ $m['minutes'] }} minutes.</p>@endif
                @foreach ($m['categories'] as $c)
                    <section class="cat">
                        <h2>{{ $c['name'] }}</h2>
                        @foreach ($c['items'] as $it)
                            <div class="item" data-price="{{ $it['price'] }}">
                                <div class="grow"><div class="iname">{{ $it['name'] }}</div>
                                    @if ($it['description'])<div class="small muted">{{ $it['description'] }}</div>@endif
                                    <div class="price">{{ $it['price_label'] }}</div></div>
                                <div class="stepper" role="group" aria-label="How many {{ $it['name'] }}">
                                    <button type="button" data-step="-1" aria-label="One less">−</button>
                                    <input type="number" name="qty[{{ $it['id'] }}]" value="{{ old('qty.' . $it['id'], 0) }}" min="0" max="20" inputmode="numeric" aria-label="Quantity">
                                    <button type="button" data-step="1" aria-label="One more">+</button>
                                </div>
                            </div>
                        @endforeach
                    </section>
                @endforeach
                <div class="field"><label for="note">Anything the kitchen should know? (optional)</label>
                    <input id="note" name="note" maxlength="300" value="{{ old('note') }}" placeholder="e.g. no pepper, extra ice, knock softly"></div>
                <div class="bar">
                    <div><div class="small muted"><span id="count">0</span> item(s)</div><div class="total" id="total">{{ $sym }}0.00</div></div>
                    <button class="btn" type="submit" id="send" disabled><i class="ri-restaurant-line"></i> Send order</button>
                </div>
                <p class="small muted">Prices include tax. The order is added to your room bill once the kitchen accepts it — pay when you check out.</p>
            </form>
        @endforeach
    @endif
</div>
@endsection

@push('head')
<style>
    .order { max-width: 760px; }
    .order .head { margin: 24px 0 8px; }
    .order h1 { font-size: 32px; margin: 4px 0; }
    .order h2 { font-size: 18px; margin: 0 0 8px; }
    .back { color: var(--lagoon); text-decoration: none; font-weight: 600; }
    .orders { margin: 12px 0 18px; }
    .orow { display: flex; gap: 12px; padding: 10px 0; border-top: 1px solid var(--line); align-items: flex-start; }
    .orow:first-of-type { border-top: 0; }
    .grow { flex: 1; min-width: 0; }
    .oright { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
    .link-btn { background: none; border: 0; color: var(--bad); text-decoration: underline; cursor: pointer; padding: 0; }
    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin: 6px 0 14px; }
    .tabs a { padding: 9px 16px; border-radius: 99px; border: 1.5px solid var(--line); text-decoration: none; color: var(--ink); font-weight: 600; }
    .tabs a.on { border-color: var(--lagoon); background: var(--lagoon-l); color: var(--lagoon-d); }
    .cat { margin: 18px 0; }
    .item { display: flex; gap: 14px; align-items: center; padding: 12px 0; border-bottom: 1px solid var(--line); }
    .iname { font-weight: 600; }
    .price { font-size: 15px; color: var(--ink-2); margin-top: 2px; }
    .stepper { display: flex; align-items: center; border: 1.5px solid var(--line); border-radius: 12px; overflow: hidden; flex: 0 0 auto; }
    .stepper button { width: 44px; height: 44px; border: 0; background: var(--paper); font-size: 22px; cursor: pointer; color: var(--ink); }
    .stepper input { width: 44px; height: 44px; border: 0; text-align: center; font-size: 17px; font-weight: 700; -moz-appearance: textfield; background: #fff; }
    .stepper input::-webkit-outer-spin-button, .stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .item.has .stepper { border-color: var(--lagoon); }
    .bar { position: sticky; bottom: 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; background: var(--card); border: 1px solid var(--line);
        border-radius: 16px; padding: 12px 14px; margin: 16px 0 10px; box-shadow: 0 -6px 20px rgba(0,0,0,.06); }
    .bar .total { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
    @media (max-width: 480px) { .order h1 { font-size: 26px; } .bar .btn { padding: 0 16px; } }
</style>
@endpush

@push('scripts')
<script>
(() => {
    const form = document.getElementById('orderForm');
    if (form) {
        const sym = form.dataset.sym, send = document.getElementById('send');
        const update = () => {
            let n = 0, t = 0;
            form.querySelectorAll('.item').forEach(row => {
                const input = row.querySelector('input');
                const q = Math.max(0, Math.min(20, parseInt(input.value || '0', 10) || 0));
                row.classList.toggle('has', q > 0);
                n += q; t += q * parseFloat(row.dataset.price);
            });
            document.getElementById('count').textContent = n;
            document.getElementById('total').textContent = sym + t.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            send.disabled = n === 0;
        };
        form.addEventListener('click', e => {
            const b = e.target.closest('[data-step]');
            if (!b) return;
            const input = b.parentElement.querySelector('input');
            input.value = Math.max(0, Math.min(20, (parseInt(input.value || '0', 10) || 0) + parseInt(b.dataset.step, 10)));
            update();
        });
        form.addEventListener('input', update);
        form.addEventListener('submit', () => { send.disabled = true; });
        update();
    }
    // While an order is on its way, check for news every 30 seconds — unless the guest is choosing more.
    @if ($live)
        setInterval(() => {
            const busy = form && [...form.querySelectorAll('.item input')].some(i => parseInt(i.value || '0', 10) > 0);
            if (!busy && document.visibilityState === 'visible') location.reload();
        }, 30000);
    @endif
})();
</script>
@endpush
