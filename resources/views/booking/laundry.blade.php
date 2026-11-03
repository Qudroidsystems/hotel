@extends('layouts.guest', ['title' => 'Laundry', 'noindex' => true])

@section('content')
@php
    $sym = config('hotel.currency_symbol');
    $roomNos = $r->rooms->pluck('room.number')->filter()->implode(', ');
    $live = collect($l['orders'])->where('live', true)->isNotEmpty();
    $pct = rtrim(rtrim(number_format($l['express']['percent'], 1), '0'), '.');
@endphp
<div class="wrap laundry">
    <div class="head">
        <a href="{{ route('stay.show', $r->guest_token) }}" class="small back"><i class="ri-arrow-left-line"></i> Your booking</a>
        <h1 class="serif">Laundry</h1>
        <p class="muted">{{ $roomNos ? 'Room ' . $roomNos . ' · ' : '' }}{{ $r->guest?->full_name }} · added to your bill</p>
    </div>
    @include('rooms.partials.flash-guest')

    @if (count($l['orders']))
        <section class="panel bags" aria-label="Your laundry">
            <h2>Your laundry</h2>
            @foreach ($l['orders'] as $o)
                <div class="brow">
                    <div class="grow">
                        <strong>{{ $o['number'] }}</strong>@if ($o['express']) <span class="pill warn">Express</span>@endif <span class="small muted">{{ $o['time'] }}</span>
                        <div class="small">{{ $o['summary'] }}{{ $o['total_label'] ? ' · ' . $o['total_label'] : '' }}</div>
                        @if ($o['due'])<div class="small"><i class="ri-time-line"></i> {{ $o['due'] }}</div>@endif
                        @if ($o['note'])<div class="small">{{ $o['status'] === 'declined' ? 'Laundry: ' : '' }}{{ $o['note'] }}</div>@endif
                    </div>
                    <div class="bright">
                        <span class="pill {{ ['requested' => 'warn', 'declined' => 'bad', 'cancelled' => 'grey', 'returned' => 'grey'][$o['status']] ?? '' }}">{{ explode(' — ', $o['status_label'])[0] }}</span>
                        @if ($o['can_cancel'])
                            <form method="POST" action="{{ route('stay.laundry.cancel', [$r->guest_token, $o['id']]) }}" onsubmit="return confirm('Cancel this laundry?')">@csrf<button class="link-btn small">Cancel</button></form>
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    @if (! $l['available'])
        <div class="notice warn"><i class="ri-information-line"></i><div>{{ $l['blocker'] }}</div></div>
    @else
        <form method="POST" action="{{ route('stay.laundry.request', $r->guest_token) }}" id="ldForm" data-sym="{{ $sym }}" data-pct="{{ $l['express']['percent'] }}">@csrf
            <div class="panel">
                <h2>Send laundry</h2>
                <p class="small muted">We collect it from your room{{ $l['hours'] ? ' between ' . $l['hours'] : '' }} and bring it back within {{ $l['standard_hours'] }} hours. You pay for what we count when we collect it.</p>
                @if ($l['note'])<p class="small">{{ $l['note'] }}</p>@endif
                <input type="hidden" name="express" value="0">
                <label class="check"><input type="checkbox" name="express" value="1" @checked(old('express'))> <span><strong>Express</strong> — back within {{ $l['express']['hours'] }} hours, {{ $pct }}% extra</span></label>
                <div class="field"><label for="note">Anything we should know? (optional)</label>
                    <input id="note" name="note" maxlength="300" value="{{ old('note') }}" placeholder="e.g. no starch, hang the shirts"></div>
            </div>

            <details class="panel list" {{ old('qty') ? 'open' : '' }}>
                <summary><h2>List what's in the bag <span class="small muted">(optional)</span></h2></summary>
                <p class="small muted">Listing it shows you the price now. We count the bag either way.</p>
                @foreach ($l['groups'] as $g)
                    <section class="cat">
                        <h3>{{ $g['name'] }}</h3>
                        @foreach ($g['items'] as $it)
                            <div class="item">
                                <div class="iname">{{ $it['name'] }}</div>
                                <div class="svcs">
                                    @foreach ($it['services'] as $s)
                                        <label class="svc"><span>{{ $s['label'] }}<br><b>{{ $s['price_label'] }}</b></span>
                                            <input type="number" min="0" max="50" inputmode="numeric" name="qty[{{ $it['id'] }}][{{ $s['id'] }}]" value="{{ old('qty.' . $it['id'] . '.' . $s['id']) }}" data-price="{{ $s['price'] }}" placeholder="0" aria-label="{{ $it['name'] }}, {{ $s['label'] }}"></label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </section>
                @endforeach
            </details>

            <div class="bar">
                <div><div class="small muted" id="ldCount">Not listed — we'll count it</div><div class="total" id="ldTotal"></div></div>
                <button class="btn" type="submit" id="ldSend"><i class="ri-t-shirt-line"></i> Collect my laundry</button>
            </div>
        </form>
    @endif
</div>
@endsection

@push('head')
<style>
    .laundry { max-width: 760px; }
    .laundry .head { margin: 24px 0 8px; }
    .laundry h1 { font-size: 32px; margin: 4px 0; }
    .laundry h2 { font-size: 18px; margin: 0 0 8px; display: inline; }
    .laundry h3 { font-size: 15px; margin: 14px 0 4px; color: var(--ink-2); }
    .back { color: var(--lagoon); text-decoration: none; font-weight: 600; }
    .bags { margin: 12px 0 18px; }
    .brow { display: flex; gap: 12px; padding: 10px 0; border-top: 1px solid var(--line); align-items: flex-start; }
    .brow:first-of-type { border-top: 0; }
    .grow { flex: 1; min-width: 0; }
    .bright { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
    .link-btn { background: none; border: 0; color: var(--bad); text-decoration: underline; cursor: pointer; padding: 0; }
    .check { display: flex; gap: 10px; align-items: flex-start; margin: 10px 0; cursor: pointer; }
    .check input { width: 20px; height: 20px; margin-top: 2px; }
    .list summary { cursor: pointer; list-style: none; }
    .list summary::-webkit-details-marker { display: none; }
    .list summary::after { content: '＋'; float: right; color: var(--lagoon); font-weight: 700; }
    .list[open] summary::after { content: '－'; }
    .item { display: flex; gap: 12px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
    .iname { font-weight: 600; flex: 1 1 140px; }
    .svcs { display: flex; gap: 8px; flex-wrap: wrap; }
    .svc { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--ink-2); line-height: 1.25; }
    .svc input { width: 54px; height: 40px; border: 1.5px solid var(--line); border-radius: 10px; text-align: center; font-size: 16px; font-weight: 700; }
    .svc input.on { border-color: var(--lagoon); background: var(--lagoon-l); }
    .bar { position: sticky; bottom: 0; display: flex; justify-content: space-between; align-items: center; gap: 12px; background: var(--card); border: 1px solid var(--line);
        border-radius: 16px; padding: 12px 14px; margin: 16px 0 10px; box-shadow: 0 -6px 20px rgba(0,0,0,.06); }
    .bar .total { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
    @media (max-width: 480px) { .laundry h1 { font-size: 26px; } .bar .btn { padding: 0 16px; } }
</style>
@endpush

@push('scripts')
<script>
(() => {
    const f = document.getElementById('ldForm');
    if (f) {
        const sym = f.dataset.sym, pct = parseFloat(f.dataset.pct) || 0;
        const update = () => {
            let n = 0, t = 0;
            f.querySelectorAll('input[data-price]').forEach(i => { const q = Math.max(0, parseInt(i.value || '0', 10) || 0); i.classList.toggle('on', q > 0); n += q; t += q * parseFloat(i.dataset.price); });
            if (f.querySelector('input[name=express][type=checkbox]').checked) t += t * pct / 100;
            document.getElementById('ldCount').textContent = n ? n + ' piece' + (n === 1 ? '' : 's') + ' listed' : 'Not listed — we\'ll count it';
            document.getElementById('ldTotal').textContent = n ? sym + t.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
        };
        f.addEventListener('input', update); f.addEventListener('change', update);
        f.addEventListener('submit', () => { document.getElementById('ldSend').disabled = true; });
        update();
    }
    @if ($live)
        setInterval(() => {
            const busy = f && [...f.querySelectorAll('input[data-price]')].some(i => parseInt(i.value || '0', 10) > 0);
            if (!busy && document.visibilityState === 'visible') location.reload();
        }, 45000);
    @endif
})();
</script>
@endpush
