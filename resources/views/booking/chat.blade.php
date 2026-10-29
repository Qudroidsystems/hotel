@extends('layouts.guest', ['title' => 'Chat with the hotel', 'noindex' => true])

@section('content')
@php $base = url('/stay/' . $r->guest_token . '/live'); @endphp
<div class="wrap chat-page">
    <a href="{{ route('stay.show', $r->guest_token) }}" class="small back"><i class="ri-arrow-left-line"></i> Your booking</a>
    <h1 class="serif">Chat with us</h1>
    <p class="muted">Message or call any part of the hotel. Replies appear here straight away.</p>
    @if (! $home['available'])
        <div class="notice warn"><i class="ri-information-line"></i><div>{{ $home['note'] }}</div></div>
    @endif

    <div class="cp" id="cp">
        <nav class="cp-sections" id="cp-sections" aria-label="Who to contact">
            @foreach ($home['sections'] as $s)
                <button type="button" class="cp-sec" data-id="{{ $s['id'] }}">
                    <i class="{{ $s['icon'] }}" aria-hidden="true"></i>
                    <span class="grow"><strong>{{ $s['name'] }}</strong><span class="small muted">{{ $s['open'] ? ($s['last'] ?: $s['description']) : 'Closed now' . ($s['hours'] ? ' · ' . $s['hours'] : '') }}</span></span>
                    @if ($s['unread'])<span class="cp-n">{{ $s['unread'] }}</span>@endif
                    <span class="cp-dot {{ $s['open'] ? 'on' : '' }}" title="{{ $s['open'] ? 'Open now' : 'Closed now' }}"></span>
                </button>
            @endforeach
        </nav>
        <section class="cp-thread panel" id="cp-panel" hidden>
            <header class="cp-head">
                <button type="button" class="cp-ib" id="cp-back" aria-label="Back"><i class="ri-arrow-left-line"></i></button>
                <div class="grow"><strong id="cp-name"></strong><div class="small muted" id="cp-sub"></div></div>
                <a class="cp-ib" id="cp-tel" hidden aria-label="Phone call"><i class="ri-phone-line"></i></a>
                <button type="button" class="cp-ib cp-call" id="cp-call" hidden aria-label="Call in the app"><i class="ri-phone-fill"></i></button>
            </header>
            <div class="cp-callbar" id="cp-callbar" hidden><span class="pulse" aria-hidden="true"></span><span class="grow" id="cp-callst"></span><span id="cp-calltime"></span>
                <button type="button" id="cp-mute">Mute</button><button type="button" id="cp-hang" class="end">End</button></div>
            <div class="cp-msgs" id="cp-msgs" aria-live="polite"></div>
            <div class="small muted cp-typing" id="cp-typing"></div>
            <form class="cp-form" id="cp-form" autocomplete="off">
                <label class="cp-ib" title="Send a photo"><i class="ri-image-add-line"></i><input type="file" accept="image/*" id="cp-photo" hidden></label>
                <textarea id="cp-text" rows="1" maxlength="2000" placeholder="Write a message…" aria-label="Message"></textarea>
                <button class="cp-ib cp-send" aria-label="Send"><i class="ri-send-plane-2-fill"></i></button>
            </form>
        </section>
    </div>
    <div class="cp-incoming" id="cp-in" hidden role="alertdialog" aria-labelledby="cp-in-t">
        <div class="pulse big"><i class="ri-phone-fill"></i></div>
        <div><div class="small" style="opacity:.75">The hotel is calling</div><strong id="cp-in-t"></strong></div>
        <div class="acts"><button type="button" id="cp-in-no">Decline</button><button type="button" id="cp-in-yes" class="yes">Answer</button></div>
    </div>
    <audio id="cp-audio" autoplay playsinline></audio>
</div>
@endsection

@push('head')
<style>
    .chat-page { max-width: 980px; }
    .chat-page [hidden] { display: none !important; }
    .chat-page h1 { font-size: 32px; margin: 6px 0 2px; }
    .back { color: var(--lagoon); text-decoration: none; font-weight: 600; display: inline-block; margin-top: 22px; }
    .cp { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 16px; margin: 18px 0 30px; align-items: start; }
    .cp-sections { display: grid; gap: 8px; }
    .cp-sec { display: flex; gap: 12px; align-items: center; text-align: left; border: 1.5px solid var(--line); background: var(--card); border-radius: 14px; padding: 12px 14px; cursor: pointer; font: inherit; color: var(--ink); transition: border-color .15s, transform .1s; }
    .cp-sec:hover { border-color: var(--lagoon); } .cp-sec:active { transform: scale(.99); }
    .cp-sec.on { border-color: var(--lagoon); background: var(--lagoon-l); }
    .cp-sec > i { font-size: 22px; color: var(--lagoon); } .grow { flex: 1; min-width: 0; }
    .cp-sec { width: 100%; min-width: 0; } .cp-sec .small { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cp-n { background: var(--lagoon); color: #fff; border-radius: 99px; font-size: 12px; font-weight: 700; padding: 1px 8px; }
    .cp-dot { width: 9px; height: 9px; border-radius: 50%; background: #cbd5e1; flex: 0 0 auto; } .cp-dot.on { background: #16a34a; }
    .cp-thread { padding: 0; display: flex; flex-direction: column; height: min(72vh, 680px); overflow: hidden; }
    .cp-head { display: flex; gap: 10px; align-items: center; padding: 12px 14px; border-bottom: 1px solid var(--line); }
    .cp-ib { width: 42px; height: 42px; border-radius: 12px; border: 1.5px solid var(--line); background: var(--card); display: grid; place-items: center; font-size: 19px; cursor: pointer; color: var(--ink); text-decoration: none; flex: 0 0 auto; }
    .cp-call { background: #16a34a; color: #fff; border-color: transparent; }
    #cp-back { display: none; }
    .cp-msgs { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 6px; background: var(--paper); }
    .cp-m { max-width: 80%; padding: 8px 12px 5px; border-radius: 16px; background: var(--card); align-self: flex-start; border-bottom-left-radius: 6px; animation: cpIn .2s ease-out; }
    .cp-m.mine { align-self: flex-end; background: var(--lagoon); color: #fff; border-bottom-left-radius: 16px; border-bottom-right-radius: 6px; }
    .cp-m.sys { align-self: center; background: transparent; color: var(--muted); font-size: 13.5px; text-align: center; }
    .cp-m .by { font-size: 12px; font-weight: 700; opacity: .75; } .cp-m .t { white-space: pre-wrap; word-wrap: break-word; }
    .cp-m img { max-width: 240px; border-radius: 10px; display: block; margin: 4px 0; }
    .cp-m .tm { font-size: 11px; opacity: .7; text-align: right; }
    .cp-typing { padding: 0 14px; min-height: 20px; }
    .cp-form { display: flex; gap: 8px; padding: 10px 12px 12px; align-items: flex-end; }
    .cp-form textarea { flex: 1; resize: none; border: 1.5px solid var(--line); border-radius: 14px; padding: 10px 12px; font: inherit; max-height: 130px; }
    .cp-send { background: var(--lagoon); color: #fff; border-color: transparent; }
    .cp-callbar { display: flex; gap: 10px; align-items: center; background: var(--ink); color: #fff; padding: 10px 14px; font-weight: 600; }
    .cp-callbar button { border: 0; border-radius: 99px; padding: 6px 13px; font-weight: 700; background: #334155; color: #fff; cursor: pointer; } .cp-callbar .end { background: #dc2626; }
    .pulse { width: 12px; height: 12px; border-radius: 50%; background: #22c55e; animation: cpPulse 1.4s infinite; flex: 0 0 auto; }
    .pulse.big { width: 54px; height: 54px; display: grid; place-items: center; font-size: 24px; color: #fff; }
    .cp-incoming { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); width: min(360px, calc(100vw - 32px)); background: var(--ink); color: #fff; border-radius: 22px; padding: 16px; display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: center; box-shadow: 0 20px 50px rgba(0,0,0,.35); z-index: 50; }
    .cp-incoming .acts { grid-column: 1 / -1; display: flex; gap: 10px; } .cp-incoming .acts button { flex: 1; border: 0; border-radius: 12px; padding: 11px; font-weight: 700; background: #334155; color: #fff; font: inherit; font-weight: 700; } .cp-incoming .yes { background: #16a34a !important; }
    @keyframes cpIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    @keyframes cpPulse { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,.6); } 70% { box-shadow: 0 0 0 14px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
    @media (prefers-reduced-motion: reduce) { .cp-m { animation: none; } .pulse { animation: none; } }
    @media (max-width: 760px) {
        .cp { grid-template-columns: minmax(0, 1fr); }
        .cp.open .cp-sections { display: none; }
        #cp-back { display: grid; }
        .cp-thread { height: calc(100vh - 190px); }
    }
</style>
@endpush

@push('scripts')
<script src="{{ asset('js/hotel-live.js') }}?v={{ @filemtime(public_path('js/hotel-live.js')) }}"></script>
<script>
(() => {
    const BASE = @json($base), TOKEN = @json($r->guest_token), CSRF = @json(csrf_token());
    const HOME = @json($home);
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
    const req = (path, opts = {}) => fetch(BASE + path, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF, ...(opts.json ? { 'Content-Type': 'application/json' } : {}) } }, opts))
        .then(async r => { const j = await r.json().catch(() => ({})); if (!r.ok) throw new Error(j.message || 'Something went wrong. Try again.'); return j.data; });
    const st = { sec: null, lastId: 0, call: null, socket: false, day: '' };

    new HotelLive({ config: HOME.live, channels: HOME.live.enabled ? [HOME.live.channel] : [], authUrl: @json(route('stay.realtime.auth', $r->guest_token)), authExtra: { token: TOKEN }, csrf: CSRF,
        onState: up => st.socket = up,
        onEvent: (ch, ev, d) => {
            if (ev === 'chat.message' && d.section_id === st.sec) { add([d.message]); refresh(); }
            if (ev === 'chat.typing' && d.section_id === st.sec) typing();
            if (ev === 'call.ring') incoming(d);
            if (ev === 'call.state' && st.call && d.uuid === st.call.call.uuid) st.call.update(d);
            if (ev === 'call.signal' && st.call && d.call === st.call.call.uuid) st.call.handle(d);
        } });

    function bubble(m) {
        if (m.sender === 'system') return `<div class="cp-m sys" data-id="${m.id}">${esc(m.body)}</div>`;
        return `<div class="cp-m ${m.mine ? 'mine' : ''}" data-id="${m.id || ''}" data-client="${esc(m.client_id || '')}">${!m.mine ? `<div class="by">${esc(m.name)}</div>` : ''}
            ${m.photo ? `<a href="${esc(m.photo)}" target="_blank" rel="noopener"><img src="${esc(m.photo)}" alt="Photo"></a>` : ''}${m.body ? `<div class="t">${esc(m.body)}</div>` : ''}
            <div class="tm">${esc(m.time || '')}${m.mine ? (m.read ? ' ✓✓' : ' ✓') : ''}</div></div>`;
    }
    function add(list) {
        const box = $('cp-msgs');
        list.forEach(m => {
            if (m.id && box.querySelector(`.cp-m[data-id="${m.id}"]`)) return;
            const p = m.client_id && box.querySelector(`.cp-m[data-client="${CSS.escape(m.client_id)}"]`);
            if (m.day && m.day !== st.day) { st.day = m.day; box.insertAdjacentHTML('beforeend', `<div class="cp-m sys">${esc(m.day)}</div>`); }
            if (p) p.outerHTML = bubble(m); else box.insertAdjacentHTML('beforeend', bubble(m));
            if (m.id) st.lastId = Math.max(st.lastId, m.id);
        });
        box.scrollTop = box.scrollHeight;
    }
    async function open(id) {
        st.sec = id; st.lastId = 0; st.day = '';
        document.querySelectorAll('.cp-sec').forEach(b => b.classList.toggle('on', +b.dataset.id === id));
        $('cp').classList.add('open'); $('cp-panel').hidden = false; $('cp-msgs').innerHTML = '';
        try {
            const d = await req('/chat/' + id);
            $('cp-name').textContent = d.section.name;
            $('cp-sub').textContent = d.section.open ? 'Open now' + (d.section.hours ? ' · ' + d.section.hours : '') : 'Closed now' + (d.section.hours ? ' · open ' + d.section.hours : '') + ' — leave a message';
            $('cp-call').hidden = !d.section.can_call;
            $('cp-tel').hidden = !d.section.phone; if (d.section.phone) $('cp-tel').href = 'tel:' + d.section.phone;
            add(d.messages);
            document.querySelector(`.cp-sec[data-id="${id}"] .cp-n`)?.remove();
            $('cp-text').focus();
        } catch (e) { $('cp-msgs').innerHTML = `<div class="cp-m sys">${esc(e.message)}</div>`; }
    }
    async function refresh() {
        if (!st.sec) return;
        try { const d = await req(`/chat/${st.sec}?after=${st.lastId}`); add(d.messages); if (d.typing) typing();
            if (d.read_up_to) document.querySelectorAll('.cp-m.mine').forEach(el => { if (+el.dataset.id <= d.read_up_to) { const t = el.querySelector('.tm'); if (t && !t.textContent.includes('✓✓')) t.textContent = t.textContent.replace('✓', '✓✓'); } });
        } catch (e) {}
    }
    let tT; function typing() { $('cp-typing').textContent = 'Typing…'; clearTimeout(tT); tT = setTimeout(() => $('cp-typing').textContent = '', 6000); }
    document.querySelectorAll('.cp-sec').forEach(b => b.addEventListener('click', () => open(+b.dataset.id)));
    $('cp-back').addEventListener('click', () => { $('cp').classList.remove('open'); $('cp-panel').hidden = true; st.sec = null; });

    async function send(body, photo) {
        const client_id = uuid();
        if (body) add([{ client_id, sender: 'guest', mine: true, body, time: 'Sending…' }]);
        const fd = new FormData(); fd.append('client_id', client_id); if (body) fd.append('body', body); if (photo) fd.append('photo', photo);
        for (let i = 0; i < 3; i++) {
            try { const d = await req('/chat/' + st.sec, { method: 'POST', body: fd }); add([d.message]); setTimeout(refresh, 600); return; }
            catch (e) { if (i === 2) { const el = document.querySelector(`.cp-m[data-client="${client_id}"] .tm`); if (el) el.textContent = 'Not sent — ' + e.message; } else await new Promise(r => setTimeout(r, 1500 * (i + 1))); }
        }
    }
    $('cp-form').addEventListener('submit', e => { e.preventDefault(); const t = $('cp-text').value.trim(); if (t) { $('cp-text').value = ''; send(t); } });
    $('cp-text').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && window.matchMedia('(pointer: fine)').matches) { e.preventDefault(); $('cp-form').requestSubmit(); } });
    let typAt = 0; $('cp-text').addEventListener('input', () => { if (st.sec && Date.now() - typAt > 3000) { typAt = Date.now(); req(`/chat/${st.sec}/typing`, { method: 'POST' }).catch(() => {}); } });
    $('cp-photo').addEventListener('change', e => { const f = e.target.files[0]; if (f && st.sec) send(null, f); e.target.value = ''; });

    // ── Calls ──
    function startCall(c, text) {
        $('cp-callbar').hidden = false; $('cp-callst').textContent = text;
        const call = new HotelCall({ call: c, base: `${BASE}/calls/${c.uuid}`, csrf: CSRF, audio: $('cp-audio'),
            onState: (t) => { $('cp-callst').textContent = t; }, onEnd: () => { st.call = null; setTimeout(() => $('cp-callbar').hidden = true, 2500); refresh(); } });
        st.call = call;
        call.start().catch(e => { $('cp-callst').textContent = e.message; call.hangUp(); });
    }
    $('cp-call').addEventListener('click', async () => {
        if (st.call || !st.sec) return;
        try { const c = await req('/calls', { method: 'POST', json: true, body: JSON.stringify({ section_id: st.sec }) }); startCall(c, 'Calling…'); }
        catch (e) { $('cp-callbar').hidden = false; $('cp-callst').textContent = e.message; setTimeout(() => $('cp-callbar').hidden = true, 5000); }
    });
    $('cp-hang').addEventListener('click', () => st.call?.hangUp());
    $('cp-mute').addEventListener('click', e => { if (!st.call) return; const m = e.target.textContent === 'Mute'; st.call.mute(m); e.target.textContent = m ? 'Unmute' : 'Mute'; });
    setInterval(() => { if (st.call?.seconds()) { const s = st.call.seconds(); $('cp-calltime').textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); } }, 1000);

    let ringing = null, stopTone = null;
    function incoming(c) {
        if (!c || st.call || ringing === c.uuid || c.status !== 'ringing' || c.direction !== 'out') return;
        ringing = c.uuid; $('cp-in-t').textContent = c.section || 'The hotel'; $('cp-in').hidden = false; stopTone = hotelRingTone();
        $('cp-in-yes').onclick = async () => { $('cp-in').hidden = true; stopTone?.(); try { const a = await req(`/calls/${c.uuid}/answer`, { method: 'POST' }); if (a.section_id) open(a.section_id); startCall(a, 'Connecting…'); } catch (e) { alert(e.message); } };
        $('cp-in-no').onclick = () => { $('cp-in').hidden = true; stopTone?.(); req(`/calls/${c.uuid}/end`, { method: 'POST', json: true, body: JSON.stringify({ reason: 'declined' }) }).catch(() => {}); };
    }
    const tick = (fn, fast, slow) => { const run = () => { fn(); setTimeout(run, st.socket ? slow : fast); }; setTimeout(run, fast); };
    tick(refresh, 3000, 20000);
    tick(async () => { if (!st.call) { try { const c = await req('/calls/ringing'); if (c) incoming(c); else if (ringing) { $('cp-in').hidden = true; stopTone?.(); ringing = null; } } catch (e) {} } }, 4000, 25000);
    const first = new URLSearchParams(location.search).get('s');
    if (first) open(+first); else if (window.matchMedia('(min-width: 761px)').matches && HOME.sections.length) open(HOME.sections[0].id);
})();
</script>
@endpush
