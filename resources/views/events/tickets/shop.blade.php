@extends('layouts.guest', ['title' => 'Tickets · ' . $e->title])

@section('content')
@php
    $sym = config('hotel.currency_symbol');
    $money = fn ($v) => (float) $v > 0 ? $sym . number_format((float) $v, 2) : 'Free';
@endphp
<style>
    .tk-head { margin: 26px 0 18px; }
    .tk-head h1 { font-size: clamp(28px, 5vw, 40px); line-height: 1.1; margin: 8px 0 6px; }
    .tk-grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 20px; align-items: start; }
    @media (max-width: 820px) { .tk-grid { grid-template-columns: 1fr; } }
    .tk-type { display: flex; gap: 14px; align-items: center; padding: 16px 0; border-top: 1px dashed var(--line); }
    .tk-type:first-of-type { border-top: 0; padding-top: 0; }
    .tk-type .grow { flex: 1; min-width: 0; }
    .tk-type h3 { margin: 0; font-size: 18px; }
    .tk-type .price { font-weight: 700; color: var(--ink); }
    .tk-type .was { text-decoration: line-through; color: var(--muted); font-weight: 400; margin-left: 6px; }
    .step { display: inline-flex; align-items: center; border: 1.5px solid var(--line); border-radius: 12px; overflow: hidden; }
    .step button { width: 42px; height: 44px; border: 0; background: #fff; font-size: 20px; cursor: pointer; color: var(--ink); }
    .step button:disabled { color: #c3ccd0; cursor: default; }
    .step output { min-width: 34px; text-align: center; font-weight: 700; font-variant-numeric: tabular-nums; }
    .tk-sum { position: sticky; top: 16px; }
    .tk-sum .row { display: flex; justify-content: space-between; gap: 10px; font-size: 15px; padding: 4px 0; }
    .tk-sum .total { border-top: 1px solid var(--line); margin-top: 8px; padding-top: 10px; font-size: 20px; font-weight: 700; }
    .tk-sum .field { margin-top: 12px; }
    .promo { display: flex; gap: 8px; }
    .promo input { text-transform: uppercase; }
    .tk-err { color: var(--bad); font-size: 14px; min-height: 20px; margin-top: 6px; }
</style>
<div class="wrap">
    <div class="tk-head">
        <span class="pill">{{ $e->type_label }}</span>
        <h1 class="serif">{{ $e->title }}</h1>
        <div class="muted"><i class="ri-calendar-event-line"></i> {{ $e->when }}@if ($e->hall) &nbsp;·&nbsp; <i class="ri-map-pin-line"></i> {{ $e->hall->name }}@endif</div>
    </div>

    @if (session('error'))<div class="notice bad"><i class="ri-error-warning-line"></i><div>{{ session('error') }}</div></div>@endif
    @if ($blocker)
        <div class="notice warn"><i class="ri-information-line"></i><div>{{ $blocker }}</div></div>
    @elseif (! $online)
        <div class="notice warn"><i class="ri-information-line"></i><div>Online payment isn’t set up yet: free tickets can be taken here, and paid tickets are sold at the front desk.</div></div>
    @endif

    <form method="POST" action="{{ route('tickets.buy', $e->id) }}" id="tkForm" class="tk-grid">
        @csrf
        <div class="panel">
            @if ($e->ticket_info)<p style="white-space:pre-line;margin-top:0">{{ $e->ticket_info }}</p>@endif
            @forelse ($e->ticketTypes as $t)
                @php $why = $t->closedReason(); $max = min($t->max_per_order, $t->left() ?? $t->max_per_order); @endphp
                <div class="tk-type">
                    <div class="grow">
                        <h3>{{ $t->name }}</h3>
                        <div><span class="price">{{ $money($t->priceNow()) }}</span>@if ($t->isEarlyBird())<span class="was">{{ $money($t->price) }}</span> <span class="pill warn">Early bird until {{ $t->early_until->format('D j M') }}</span>@endif</div>
                        <div class="small muted">{{ $t->description }}{{ $t->admits > 1 ? ($t->description ? ' · ' : '') . 'Admits ' . $t->admits : '' }}{{ $why ? ' · ' . ucfirst($why) : (($t->left() !== null && $t->left() <= 20) ? ' · ' . $t->left() . ' left' : '') }}</div>
                    </div>
                    <div class="step" data-max="{{ $why ? 0 : max(0, $max) }}">
                        <button type="button" data-d="-1" aria-label="One fewer {{ $t->name }}">−</button>
                        <output>{{ old('lines.' . $t->id, 0) }}</output>
                        <button type="button" data-d="1" aria-label="One more {{ $t->name }}">+</button>
                        <input type="hidden" name="lines[{{ $t->id }}]" value="{{ old('lines.' . $t->id, 0) }}">
                    </div>
                </div>
            @empty
                <p class="muted">No tickets on sale yet.</p>
            @endforelse
        </div>

        <div class="panel tk-sum">
            <h3 class="serif" style="margin:0 0 8px">Your order</h3>
            <div id="tkRows"><div class="small muted">Choose your tickets.</div></div>
            @if ($hasPromos)
                <div class="field"><label for="promo">Promo code</label>
                    <div class="promo"><input id="promo" name="promo" value="{{ old('promo') }}" maxlength="30" autocomplete="off"><button type="button" class="btn quiet" id="tkApply">Apply</button></div>
                </div>
            @endif
            <div class="row total"><span>Total</span><span id="tkTotal">—</span></div>
            <div class="tk-err" id="tkErr" role="alert"></div>
            <div class="field"><label for="name">Your name</label><input id="name" name="name" required maxlength="120" value="{{ old('name') }}" autocomplete="name"></div>
            <div class="field"><label for="email">E-mail (your tickets are sent here)</label><input id="email" type="email" name="email" required maxlength="150" value="{{ old('email') }}" autocomplete="email"></div>
            <div class="field"><label for="phone">Phone (optional)</label><input id="phone" name="phone" maxlength="40" value="{{ old('phone') }}" autocomplete="tel"></div>
            <button class="btn" style="width:100%;margin-top:16px" id="tkPay" disabled>Choose tickets</button>
            <p class="small muted" style="margin-bottom:0">Each ticket has its own QR code, scanned once at the door. Paid securely with Paystack; your tickets are held for {{ \App\Services\Events\TicketService::HOLD_MINUTES }} minutes while you pay.</p>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('tkForm');
    if (!form) return;
    const rows = document.getElementById('tkRows'), total = document.getElementById('tkTotal'), err = document.getElementById('tkErr'), pay = document.getElementById('tkPay');
    const online = @json($online && ! $blocker), closed = @json((bool) $blocker);
    let timer = null, last = null;

    form.querySelectorAll('.step').forEach(s => {
        const out = s.querySelector('output'), input = s.querySelector('input'), max = parseInt(s.dataset.max, 10);
        const set = v => { v = Math.max(0, Math.min(max, v)); out.textContent = v; input.value = v; s.querySelector('[data-d="-1"]').disabled = v <= 0; s.querySelector('[data-d="1"]').disabled = v >= max; };
        s.addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; set(parseInt(input.value, 10) + parseInt(b.dataset.d, 10)); quote(); });
        set(parseInt(input.value, 10) || 0);
    });

    function lines() { const l = {}; form.querySelectorAll('.step input').forEach(i => { if (+i.value > 0) l[i.name.match(/\d+/)[0]] = +i.value; }); return l; }

    function quote() {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const l = lines();
            if (!Object.keys(l).length) { rows.innerHTML = '<div class="small muted">Choose your tickets.</div>'; total.textContent = '—'; err.textContent = ''; pay.disabled = true; pay.textContent = 'Choose tickets'; return; }
            pay.disabled = true;
            try {
                const res = await fetch(@json(route('tickets.quote', $e->id)), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form._token.value },
                    body: JSON.stringify({ lines: l, promo: form.promo ? form.promo.value : null }) });
                const j = await res.json();
                if (!j.success) { err.textContent = j.message || 'Something went wrong.'; total.textContent = '—'; return; }
                const q = j.data; last = q; err.textContent = '';
                rows.innerHTML = q.rows.map(r => `<div class="row"><span>${r.quantity} × ${escapeHtml(r.name)}${r.early_bird ? ' <span class="pill warn">early bird</span>' : ''}</span><span>${r.amount_label}</span></div>`).join('')
                    + (q.discount_label ? `<div class="row" style="color:var(--ok)"><span>${escapeHtml(q.promo.code)} · ${escapeHtml(q.promo.label)}</span><span>${q.discount_label}</span></div>` : '');
                total.textContent = q.total_label;
                const free = q.total <= 0;
                pay.disabled = closed || (!free && !online);
                pay.textContent = free ? 'Get my ' + (q.count > 1 ? q.count + ' tickets' : 'ticket') : (online ? 'Pay ' + q.total_label : 'Buy at the front desk');
            } catch (e) { err.textContent = 'Can’t reach the hotel. Check your connection.'; }
        }, 250);
    }
    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]); }

    document.getElementById('tkApply')?.addEventListener('click', quote);
    form.promo?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); quote(); } });
    form.addEventListener('submit', () => { pay.disabled = true; pay.textContent = 'One moment…'; });
    quote();
})();
</script>
@endsection
