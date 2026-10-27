@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<style>
    .ro-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
    .ro-ticket { background: var(--cb-surface, #fff); border: 1px solid var(--cb-border); border-top: 5px solid var(--ro, #94a3b8); border-radius: 14px; padding: 14px 16px; display: flex; flex-direction: column; }
    .ro-ticket.st-placed { --ro: #d97706; box-shadow: 0 0 0 3px rgba(217,119,6,.12); }
    .ro-ticket.st-accepted { --ro: #0891b2; }
    .ro-ticket.st-on_the_way { --ro: #4f46e5; }
    .ro-ticket.late { box-shadow: 0 0 0 3px rgba(220,38,38,.35); }
    .ro-ticket header { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 10px; }
    .ro-room { min-width: 58px; padding: 6px 8px; border-radius: 10px; background: #b7832f; color: #fff; font-weight: 800; font-size: 22px; text-align: center; font-variant-numeric: tabular-nums; }
    .ro-who { flex: 1; min-width: 0; }
    .ro-lines { list-style: none; padding: 0; margin: 0 0 8px; font-size: 15px; }
    .ro-lines li { padding: 5px 0; border-bottom: 1px dashed var(--cb-border); }
    .ro-lines b { display: inline-block; min-width: 30px; }
    .ro-note { font-size: 13px; color: #b45309; font-weight: 600; }
    .ro-foot { display: flex; justify-content: space-between; align-items: center; margin: auto 0 10px; padding-top: 6px; }
    .ro-actions { display: grid; gap: 8px; }
    .ro-decline { display: flex; gap: 6px; }
    .ro-decline input { flex: 1; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-restaurant-line" subtitle="Food and drinks guests order to their room from the guest app or the QR card in the room. Accepting an order rings it up on the guest's bill.">
        <x-slot:actions>
            <button type="button" class="cb-hero-btn" id="ro-sound" aria-pressed="false"><i class="ri-volume-mute-line"></i> <span>Sound off</span></button>
            @can('Manage POS menu')<a class="cb-hero-btn" href="{{ route('pos.menu', ['tab' => 'outlets']) }}"><i class="ri-store-2-line"></i> Outlets &amp; hours</a>@endcan
            @can('Update rooms')<a class="cb-hero-btn" href="{{ route('rooms.qr-cards') }}"><i class="ri-qr-code-line"></i> Room QR cards</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($outlets->isEmpty())
        <div class="cb-banner info"><i class="ri-information-line"></i><div>No outlet takes room orders yet. In <strong>Menu &amp; Prices → Outlets</strong>, edit the kitchen or bar and tick <em>Guests can order to their room</em>.</div></div>
    @else
        <p class="small text-muted mb-2">Taking orders: {{ $outlets->map(fn ($o) => $o->name . ' (' . ($o->openForGuests() ? 'open' : 'closed') . ($o->guest_hours_label ? ', ' . $o->guest_hours_label : '') . ')')->implode(' · ') }}</p>
    @endif

    <div id="ro-list">@include('pos.partials.room-orders-list')</div>

</div>
</div>
</div>
<script>
(() => {
    const list = document.getElementById('ro-list');
    const url = @json(route('pos.room-orders', ['partial' => 1]));
    const btn = document.getElementById('ro-sound');
    let sound = false, ctx = null;
    try { sound = localStorage.getItem('ro-sound') === '1'; } catch (e) {}
    const paint = () => { btn.setAttribute('aria-pressed', sound); btn.querySelector('i').className = sound ? 'ri-volume-up-line' : 'ri-volume-mute-line'; btn.querySelector('span').textContent = sound ? 'Sound on' : 'Sound off'; };
    paint();
    btn.addEventListener('click', () => { sound = !sound; try { localStorage.setItem('ro-sound', sound ? '1' : '0'); } catch (e) {} paint(); if (sound) chime(); });
    function chime() {
        try {
            ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.18].forEach((t, i) => { const o = ctx.createOscillator(), g = ctx.createGain(); o.frequency.value = i ? 1046 : 784; g.gain.setValueAtTime(0.2, ctx.currentTime + t); g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.35); o.connect(g).connect(ctx.destination); o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.4); });
        } catch (e) {}
    }
    const ids = () => (document.getElementById('ro-live')?.dataset.new || '').split(',').filter(Boolean);
    let known = new Set(ids());
    async function refresh() {
        if (document.activeElement && list.contains(document.activeElement) && document.activeElement.tagName === 'INPUT') return;   // someone is typing a reason
        try {
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!res.ok || res.redirected) return;
            list.innerHTML = await res.text();
            const now = ids();
            if (now.some(id => !known.has(id)) && sound) chime();
            known = new Set(now);
        } catch (e) {}
    }
    setInterval(refresh, 20000);
})();
</script>
@endsection
