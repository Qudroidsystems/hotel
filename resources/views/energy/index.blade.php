@extends('layouts.master')

@section('content')
<style>
    .en-power { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); gap: 16px; align-items: start; }
    @media (max-width: 991px) { .en-power { grid-template-columns: 1fr; } }
    .en-state { border-radius: 14px; padding: 18px 20px; color: #fff; display: flex; gap: 16px; align-items: flex-start; }
    .en-state.grid { background: linear-gradient(135deg, #0f766e, #115e59); }
    .en-state.generator { background: linear-gradient(135deg, #b45309, #92400e); }
    .en-state.dark { background: linear-gradient(135deg, #b91c1c, #7f1d1d); }
    .en-state .ico { font-size: 34px; line-height: 1; opacity: .95; }
    .en-state h3 { color: #fff; font-size: 19px; margin: 0 0 4px; }
    .en-state p { margin: 0; opacity: .92; }
    .en-state .btns { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }
    .en-state .btn-light { background: rgba(255,255,255,.95); }
    .en-src { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-top: 1px solid var(--cb-border); }
    .en-src:first-child { border-top: 0; }
    .en-src .dot { width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; flex: 0 0 auto; }
    .en-src .dot.on { background: #16a34a; box-shadow: 0 0 0 4px rgba(22,163,74,.15); }
    .en-src .grow { flex: 1; min-width: 0; }
    .en-fuel { height: 6px; border-radius: 3px; background: var(--cb-border); overflow: hidden; margin-top: 4px; max-width: 160px; }
    .en-fuel span { display: block; height: 100%; background: #f59e0b; }
    .en-plan { display: flex; gap: 4px; margin-top: 10px; }
    .en-plan div { flex: 1; border-radius: 6px; padding: 6px 8px; font-size: 12px; background: var(--cb-surface-2, #f1f5f9); min-width: 0; }
    .en-plan strong { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .en-act { display: flex; gap: 12px; align-items: flex-start; padding: 10px 0; border-top: 1px solid var(--cb-border); }
    .en-act:first-child { border-top: 0; }
    .en-act .badge-kw { font-variant-numeric: tabular-nums; white-space: nowrap; }
    .en-act .grow { flex: 1; min-width: 0; }
    .en-act .what { font-weight: 600; color: var(--cb-heading); }
    .en-act .why { font-size: 12.5px; color: var(--cb-muted, #64748b); }
    .en-act .go { display: flex; gap: 6px; flex: 0 0 auto; }
    .en-group { font-size: 12px; font-weight: 700; color: var(--cb-muted, #64748b); margin: 14px 0 2px; display: flex; justify-content: space-between; align-items: center; }
    .en-group:first-child { margin-top: 0; }
    .en-floor { margin-bottom: 12px; }
    .en-floor .head { display: flex; justify-content: space-between; font-size: 12.5px; margin-bottom: 6px; }
    .en-rooms { display: grid; grid-template-columns: repeat(auto-fill, minmax(54px, 1fr)); gap: 5px; }
    .en-room { border-radius: 7px; padding: 6px 4px; text-align: center; font-size: 12px; font-weight: 700; line-height: 1.15; border: 1px solid transparent; }
    .en-room small { display: block; font-weight: 500; font-size: 10px; opacity: .85; }
    .en-room.on { background: #0f766e; color: #fff; }
    .en-room.precool { background: #f59e0b; color: #fff; }
    .en-room.eco { background: #bbf7d0; color: #14532d; }
    .en-room.low { background: #e0e7ff; color: #3730a3; }
    .en-room.off { background: transparent; color: var(--cb-muted, #64748b); border-color: var(--cb-border); }
    .en-room.waste { border: 2px solid #dc2626; color: #b91c1c; }
    .en-key { display: flex; gap: 12px; flex-wrap: wrap; font-size: 12px; color: var(--cb-muted, #64748b); }
    .en-key i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 4px; vertical-align: -1px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Energy Saver" icon="ri-flashlight-line" subtitle="Rooms and places that can be switched off now, rooms to switch on before guests arrive, and the generator that carries the hotel. Updated every 15 minutes from bookings, arrivals, cleaning, halls and opening hours.">
        <x-slot:actions>
            <form method="POST" action="{{ route('energy.sync') }}">@csrf<button class="cb-hero-btn" type="submit"><i class="ri-refresh-line"></i> Check now</button></form>
            @can('Manage energy saver')<a class="cb-hero-btn" href="{{ route('energy.setup') }}"><i class="ri-settings-3-line"></i> Places &amp; generators</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    @unless ($setUp)
        <div class="cb-banner info"><i class="ri-lightbulb-flash-line"></i><div>Guest rooms are worked out from bookings straight away.
            @can('Manage energy saver')<a href="{{ route('energy.setup') }}">Add your generators and the other places</a> (lobby, corridors, halls, kitchen, pool …) @else Ask a manager to add the generators and other places @endcan
            so Energy Saver can say which generator to run and what else can be switched off.</div></div>
    @endunless

    <div id="en-board">
        @include('energy.partials.board')
    </div>
</div>
</div>
</div>

<script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
<script>
(function () {
    const board = document.getElementById('en-board');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    let chart = null;

    function drawChart() {
        const el = document.getElementById('en-chart');
        if (!el || !window.Chart) return;
        const data = JSON.parse(el.dataset.points);
        const cap = parseFloat(el.dataset.cap || '0');
        chart?.destroy();
        const ink = getComputedStyle(document.body).getPropertyValue('--cb-muted') || '#64748b';
        chart = new Chart(el, {
            type: 'bar',
            data: {
                labels: data.map(p => p.label),
                datasets: [
                    { label: 'Power needed (kW)', data: data.map(p => p.kw), backgroundColor: data.map((p, i) => i === 0 ? '#0f766e' : 'rgba(15,118,110,.45)'), borderRadius: 4 },
                    ...(cap > 0 ? [{ type: 'line', label: 'Running set can carry', data: data.map(() => cap), borderColor: '#f59e0b', borderDash: [5, 4], pointRadius: 0, borderWidth: 2 }] : []),
                ],
            },
            options: { maintainAspectRatio: false, animation: false, plugins: { legend: { display: cap > 0, labels: { color: ink, boxWidth: 12 } } },
                scales: { y: { beginAtZero: true, ticks: { color: ink }, title: { display: true, text: 'kW', color: ink } }, x: { ticks: { color: ink, maxRotation: 0, autoSkip: true, maxTicksLimit: 9 } } } },
        });
    }

    async function reload() {
        try {
            const res = await fetch(location.pathname + '?partial=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (res.ok) { board.innerHTML = await res.text(); drawChart(); }
        } catch (e) {}
    }

    async function post(url, body) {
        const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify(body || {}) });
        if (!res.ok) { alertBox((await res.json().catch(() => ({}))).message || 'That didn\'t work. Please try again.'); return; }
        await reload();
    }

    function alertBox(msg) { window.Swal ? Swal.fire({ icon: 'error', text: msg }) : console.warn(msg); }

    board.addEventListener('click', e => {
        const b = e.target.closest('[data-post]');
        if (!b) return;
        e.preventDefault();
        b.disabled = true;
        const body = b.dataset.body ? JSON.parse(b.dataset.body) : {};
        if (b.dataset.ask) {
            const v = prompt(b.dataset.ask, b.dataset.value || '');
            if (v === null) { b.disabled = false; return; }
            body[b.dataset.field] = v;
        }
        post(b.dataset.post, body).finally(() => { b.disabled = false; });
    });

    drawChart();
    // Keep it current while the page is open (the control room's screen).
    setInterval(() => { if (!document.hidden) reload(); }, 60000);
})();
</script>
@endsection
