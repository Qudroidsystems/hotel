<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Housekeeping board · {{ config('hotel.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#14201c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <link href="{{ asset('vendor/fonts/fonts.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <style>
        :root {
            --paper: #f3f4f1; --card: #ffffff; --ink: #14201c; --muted: #5d6b66; --line: #dde2de;
            --clean: #b45309; --cleaning: #0e7490; --inspect: #4338ca; --ready: #15803d; --occupied: #64748b; --blocked: #b91c1c;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: var(--paper); color: var(--ink); }
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; font-size: 16px; -webkit-tap-highlight-color: transparent;
            padding: env(safe-area-inset-top) 0 env(safe-area-inset-bottom); }
        button { font: inherit; color: inherit; }
        :focus-visible { outline: 3px solid #0ea5e9; outline-offset: 2px; }

        .hb-top { position: sticky; top: 0; z-index: 10; background: var(--ink); color: #fff; display: flex; align-items: center; gap: 14px; padding: 12px 20px; }
        .hb-top h1 { margin: 0; font-size: 20px; font-weight: 700; line-height: 1.15; flex: 1; min-width: 0; }
        .hb-top h1 small { display: block; font-size: 12.5px; font-weight: 500; opacity: .7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .hb-clock { font-size: 26px; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
        .hb-btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid rgba(255,255,255,.25); background: transparent; color: #fff; border-radius: 10px;
            padding: 9px 13px; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; white-space: nowrap; }
        .hb-btn.on { background: #fff; color: var(--ink); }
        .hb-btn i { font-size: 18px; }

        main { padding: 16px 20px 40px; max-width: 1500px; margin: 0 auto; }
        .hb-counts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 8px; }
        .hb-count { background: var(--card); border: 1px solid var(--line); border-left: 6px solid var(--c); border-radius: 12px; padding: 10px 14px; display: flex; align-items: baseline; gap: 10px; }
        .hb-count b { font-size: 30px; font-variant-numeric: tabular-nums; color: var(--c); line-height: 1; }
        .hb-count span { color: var(--muted); font-weight: 600; font-size: 14px; }

        .hb-floor h2 { font-size: 15px; font-weight: 700; color: var(--muted); margin: 22px 2px 10px; }
        .hb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
        .hb-tile { position: relative; text-align: left; border-radius: 16px; padding: 12px 14px 12px; min-height: 128px; display: flex; flex-direction: column; gap: 4px; cursor: pointer;
            border: 2px solid var(--c); background: var(--card); color: var(--ink); transition: transform .08s; }
        .hb-tile:active { transform: scale(.97); }
        .hb-no { font-size: 38px; font-weight: 800; letter-spacing: -.02em; line-height: 1; font-variant-numeric: tabular-nums; }
        .hb-state { font-size: 14px; font-weight: 700; color: var(--c); }
        .hb-flags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: auto; }
        .hb-flag { font-size: 12px; font-weight: 600; border-radius: 6px; padding: 2px 7px; background: rgba(20,32,28,.07); }
        .hb-who { font-size: 12.5px; opacity: .85; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Rooms that need someone: solid colour, readable from across the room. */
        .hb-tile.s-clean, .hb-tile.s-cleaning, .hb-tile.s-inspect { background: var(--c); color: #fff; }
        .hb-tile.s-clean .hb-state, .hb-tile.s-cleaning .hb-state, .hb-tile.s-inspect .hb-state { color: #fff; opacity: .92; }
        .hb-tile.s-clean .hb-flag, .hb-tile.s-cleaning .hb-flag, .hb-tile.s-inspect .hb-flag { background: rgba(255,255,255,.2); }
        .hb-tile.urgent::after { content: ''; position: absolute; top: 10px; right: 10px; width: 12px; height: 12px; border-radius: 50%; background: #fde047; box-shadow: 0 0 0 3px rgba(253,224,71,.35); }
        .hb-tile.s-ready, .hb-tile.s-occupied, .hb-tile.s-blocked { border-width: 1px; border-color: var(--line); min-height: 104px; }
        .hb-tile.s-ready .hb-no, .hb-tile.s-occupied .hb-no { font-size: 30px; }
        .s-clean { --c: var(--clean); } .s-cleaning { --c: var(--cleaning); } .s-inspect { --c: var(--inspect); }
        .s-ready { --c: var(--ready); } .s-occupied { --c: var(--occupied); } .s-blocked { --c: var(--blocked); }

        .hb-empty { text-align: center; color: var(--muted); padding: 80px 0; }
        .hb-empty i { font-size: 54px; color: var(--ready); }

        dialog { border: 0; border-radius: 20px; padding: 0; width: min(460px, calc(100vw - 32px)); color: var(--ink); box-shadow: 0 20px 60px rgba(0,0,0,.3); }
        dialog::backdrop { background: rgba(20,32,28,.55); }
        .sh-head { padding: 18px 20px 12px; border-bottom: 1px solid var(--line); display: flex; align-items: flex-start; gap: 12px; }
        .sh-head .hb-no { font-size: 44px; }
        .sh-head .meta { flex: 1; font-size: 14px; color: var(--muted); }
        .sh-head .meta b { display: block; color: var(--c); font-size: 16px; }
        .sh-close { border: 0; background: var(--paper); width: 40px; height: 40px; border-radius: 50%; font-size: 22px; cursor: pointer; }
        .sh-body { padding: 14px 20px 20px; display: grid; gap: 10px; }
        .sh-note { font-size: 14px; background: var(--paper); border-radius: 10px; padding: 8px 12px; }
        .sh-act { border: 0; border-radius: 14px; padding: 16px; font-size: 18px; font-weight: 700; color: #fff; background: var(--c, var(--ink)); cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .sh-act.alt { background: var(--paper); color: var(--ink); }
        .sh-act[disabled] { opacity: .5; }
        .sh-body select, .sh-body textarea { width: 100%; font: inherit; font-size: 16px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--ink); }
        .sh-body label { font-size: 13px; font-weight: 600; color: var(--muted); }
        .hb-toast { position: fixed; left: 50%; bottom: calc(24px + env(safe-area-inset-bottom)); transform: translateX(-50%); background: var(--ink); color: #fff; padding: 12px 18px; border-radius: 12px;
            font-weight: 600; z-index: 50; max-width: calc(100vw - 32px); }
        .hb-toast.err { background: var(--blocked); }

        @media (max-width: 700px) {
            .hb-top { padding: 10px 16px; gap: 8px; flex-wrap: wrap; }
            .hb-clock { display: none; }
            .hb-btn span { display: none; }
            main { padding: 12px 16px 32px; }
            .hb-counts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .hb-grid { grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 10px; }
            .hb-no { font-size: 32px; }
        }
        @media (prefers-reduced-motion: reduce) { .hb-tile { transition: none; } }
    </style>
</head>
<body>
<header class="hb-top">
    <h1>Housekeeping<small>{{ config('hotel.name') }} · updated <span id="hb-updated">{{ now()->format('H:i') }}</span></small></h1>
    <div class="hb-clock" id="hb-clock">{{ now()->format('H:i') }}</div>
    @if ($manager || auth()->user()->can('View housekeeping'))
        <a class="hb-btn {{ $mine ? 'on' : '' }}" href="{{ route('housekeeping.board', $mine ? [] : ['mine' => 1]) }}"><i class="ri-user-line"></i><span>My rooms</span></a>
    @endif
    <button type="button" class="hb-btn" id="hb-full" title="Full screen"><i class="ri-fullscreen-line"></i><span>Full screen</span></button>
    <a class="hb-btn" href="{{ route('housekeeping.index') }}" title="Back to the system"><i class="ri-close-line"></i><span>Close</span></a>
</header>

<main id="hb-main" aria-live="polite">
    @include('housekeeping.partials.board-grid')
</main>

<dialog id="hb-sheet" aria-labelledby="sh-room">
    <div class="sh-head">
        <span class="hb-no" id="sh-room"></span>
        <div class="meta"><b id="sh-state"></b><span id="sh-type"></span></div>
        <button type="button" class="sh-close" data-close aria-label="Close"><i class="ri-close-line"></i></button>
    </div>
    <div class="sh-body" id="sh-body"></div>
</dialog>

<script>
(() => {
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const main = document.getElementById('hb-main');
    const sheet = document.getElementById('hb-sheet');
    const body = document.getElementById('sh-body');
    const manager = @json($manager);
    const inspection = @json($inspection);
    const staff = @json($staff->map->only(['id', 'name'])->values());
    const url = (name, id) => @json(url('housekeeping/tasks')) + '/' + id + '/' + name;
    const partial = @json(route('housekeeping.board', array_filter(['mine' => $mine ? 1 : null, 'partial' => 1])));

    // Clock, and keep the tablet awake while the board is open.
    const clock = document.getElementById('hb-clock');
    setInterval(() => { clock.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }); }, 10000);
    let lock = null;
    const awake = async () => { try { lock = await navigator.wakeLock?.request('screen'); } catch (e) {} };
    awake();
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { awake(); refresh(); } });
    document.getElementById('hb-full').addEventListener('click', () => {
        if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen?.().catch(() => {});
    });

    function toast(text, error) {
        const t = document.createElement('div');
        t.className = 'hb-toast' + (error ? ' err' : '');
        t.setAttribute('role', 'status');
        t.textContent = text;
        document.body.appendChild(t);
        setTimeout(() => t.remove(), error ? 5000 : 2600);
    }

    async function refresh() {
        if (sheet.open) return;
        try {
            const res = await fetch(partial, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (res.status === 401 || res.redirected) { location.reload(); return; }
            if (!res.ok) return;
            main.innerHTML = await res.text();
            document.getElementById('hb-updated').textContent = document.getElementById('hb-stamp')?.dataset.at || '';
        } catch (e) { /* offline for a moment: try again next time */ }
    }
    setInterval(refresh, 30000);

    async function act(name, id, fields, done) {
        const data = new FormData();
        Object.entries(fields || {}).forEach(([k, v]) => data.append(k, v ?? ''));
        body.querySelectorAll('button').forEach(b => b.disabled = true);
        try {
            const res = await fetch(url(name, id), { method: 'POST', body: data, credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const json = await res.json().catch(() => ({}));
            if (!res.ok || json.success === false) {
                toast(json.message || (res.status === 419 ? 'The page was open too long. It will reload.' : 'That did not work. Try again.'), true);
                if (res.status === 419) setTimeout(() => location.reload(), 1500);
                body.querySelectorAll('button').forEach(b => b.disabled = false);
                return;
            }
            toast(done || json.message);
            sheet.close();
            refresh();
        } catch (e) {
            toast('No connection. Try again.', true);
            body.querySelectorAll('button').forEach(b => b.disabled = false);
        }
    }

    const button = (text, icon, onClick, alt) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'sh-act' + (alt ? ' alt' : '');
        b.innerHTML = '<i class="' + icon + '"></i> ';
        b.append(text);
        b.addEventListener('click', onClick);
        return b;
    };
    const note = (text) => { const d = document.createElement('div'); d.className = 'sh-note'; d.textContent = text; return d; };
    const field = (label, el) => { const l = document.createElement('label'); l.textContent = label; const w = document.createElement('div'); w.append(l, el); return w; };

    main.addEventListener('click', (e) => {
        const tile = e.target.closest('.hb-tile');
        if (!tile) return;
        const d = tile.dataset;
        sheet.className = 's-' + d.state;
        document.getElementById('sh-room').textContent = d.room;
        document.getElementById('sh-state').textContent = tile.querySelector('.hb-state').textContent;
        document.getElementById('sh-type').textContent = [d.type, d.taskType].filter(Boolean).join(' · ');
        body.innerHTML = '';
        if (d.arriving) body.append(note('Guest arriving today: ' + d.arriving));
        if (d.notes) body.append(note(d.notes));
        if (d.assigneeName) body.append(note('With ' + d.assigneeName));
        const work = d.canWork === '1';

        if (d.state === 'clean' && work) {
            body.append(button('Start cleaning', 'ri-play-circle-line', () => act('start', d.task)));
        }
        if (d.state === 'cleaning' && work) {
            const n = document.createElement('textarea'); n.rows = 2; n.maxLength = 255; n.placeholder = 'Anything to report (optional)';
            body.append(field('Note', n));
            body.append(button(inspection ? 'Finished: ready for inspection' : 'Finished', 'ri-check-double-line', () => act('finish', d.task, { notes: n.value })));
        }
        if (d.state === 'inspect' && manager) {
            const n = document.createElement('textarea'); n.rows = 2; n.maxLength = 500; n.placeholder = 'What needs doing again (for sending back)';
            body.append(button('Passed: put back on sale', 'ri-shield-check-line', () => act('inspect', d.task, { result: 'pass' })));
            body.append(field('Note', n));
            body.append(button('Send back for cleaning', 'ri-arrow-go-back-line', () => {
                if (!n.value.trim()) { n.focus(); toast('Say what needs doing again.', true); return; }
                act('inspect', d.task, { result: 'fail', note: n.value });
            }, true));
        }
        if (d.task && manager && d.state !== 'inspect') {
            const s = document.createElement('select');
            s.innerHTML = '<option value="">Anyone</option>';
            staff.forEach(p => { const o = new Option(p.name, p.id, false, String(p.id) === d.assignee); s.add(o); });
            body.append(field('Who cleans it', s));
            body.append(button('Assign', 'ri-user-shared-line', () => act('assign', d.task, { assigned_to: s.value }), true));
        }
        if (!body.querySelector('.sh-act')) {
            body.append(note({ ready: 'Clean and ready for a guest.', occupied: 'A guest is in this room.', blocked: 'Out of order: maintenance has it.' }[d.state]
                || (d.state === 'inspect' ? 'Waiting for a supervisor to inspect.' : 'Someone else is cleaning this room.')));
        }
        sheet.showModal();
    });
    sheet.addEventListener('click', (e) => { if (e.target === sheet || e.target.closest('[data-close]')) sheet.close(); });
})();
</script>
</body>
</html>
