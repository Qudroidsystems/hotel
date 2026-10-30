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
            <div class="cp-msgs" id="cp-msgs" aria-live="polite"></div>
            <button type="button" class="cp-latest" id="cp-latest" hidden><i class="ri-arrow-down-line"></i> New messages</button>
            <div class="cp-typing" id="cp-typing" hidden><span></span><span></span><span></span></div>
            <div class="cp-sugg" id="cp-sugg" aria-label="Quick messages"></div>
            <form class="cp-form" id="cp-form" autocomplete="off">
                <label class="cp-ib" title="Send a photo"><i class="ri-image-add-line"></i><input type="file" accept="image/*" id="cp-photo" hidden></label>
                <textarea id="cp-text" rows="1" maxlength="2000" placeholder="Write a message…" aria-label="Message"></textarea>
                <button type="button" class="cp-ib" id="cp-mic" title="Record a voice message" aria-label="Record a voice message" hidden><i class="ri-mic-line"></i></button>
                <button class="cp-ib cp-send" aria-label="Send"><i class="ri-send-plane-2-fill"></i></button>
            </form>
            <div class="cp-rec" id="cp-rec" hidden role="status">
                <span class="dot"></span><span id="cp-rec-t">0:00</span><span class="bars" id="cp-rec-b"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
                <span class="grow small muted" id="cp-rec-why">Recording…</span>
                <button type="button" class="cp-ib" id="cp-rec-x" aria-label="Cancel"><i class="ri-delete-bin-line"></i></button>
                <button type="button" class="cp-ib cp-send" id="cp-rec-ok" aria-label="Send the voice message"><i class="ri-send-plane-2-fill"></i></button>
            </div>
            <div class="cp-vm" id="cp-vm" hidden><i class="ri-voiceprint-line"></i><span class="grow">Nobody could answer just now. Leave a voice message and they’ll get back to you.</span><button type="button" id="cp-vm-go">Record</button><button type="button" id="cp-vm-x" aria-label="No thanks"><i class="ri-close-line"></i></button></div>
        </section>
    </div>
    <div class="cp-incoming" id="cp-in" hidden role="alertdialog" aria-labelledby="cp-in-t">
        <div class="pulse big"><i class="ri-phone-fill"></i></div>
        <div><div class="small" style="opacity:.75">The hotel is calling</div><strong id="cp-in-t"></strong></div>
        <div class="acts"><button type="button" id="cp-in-no">Decline</button><button type="button" id="cp-in-yes" class="yes">Answer</button></div>
    </div>
    <audio id="cp-audio" autoplay playsinline></audio>
    <div class="cp-lightbox" id="cp-lb" hidden role="dialog" aria-label="Photo"><img alt="Photo"><button type="button" aria-label="Close"><i class="ri-close-line"></i></button></div>
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
    .cp-thread { position: relative; }
    .cp-typing { align-self: flex-start; margin: 0 14px 6px; background: var(--card); border-radius: 16px; padding: 10px 12px; display: inline-flex; gap: 4px; width: max-content; }
    .cp-typing span { width: 7px; height: 7px; border-radius: 50%; background: var(--muted); animation: cpDot 1.2s infinite; }
    .cp-typing span:nth-child(2) { animation-delay: .15s; } .cp-typing span:nth-child(3) { animation-delay: .3s; }
    .cp-sugg { display: flex; gap: 6px; overflow-x: auto; padding: 8px 12px 0; scrollbar-width: none; }
    .cp-sugg button { border: 1.5px solid var(--line); background: var(--card); color: var(--ink); border-radius: 99px; padding: 6px 12px; font: inherit; font-size: 13.5px; white-space: nowrap; cursor: pointer; transition: border-color .15s, transform .1s; }
    .cp-sugg button:hover { border-color: var(--lagoon); } .cp-sugg button:active { transform: scale(.97); }
    .cp-latest { position: absolute; left: 50%; transform: translateX(-50%); bottom: 130px; border: 0; border-radius: 99px; background: var(--ink); color: #fff; padding: 7px 14px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; box-shadow: 0 8px 20px rgba(0,0,0,.2); }
    .cp-m.mine { background: linear-gradient(135deg, var(--lagoon), color-mix(in srgb, var(--lagoon) 78%, #000)); box-shadow: 0 4px 14px color-mix(in srgb, var(--lagoon) 30%, transparent); }
    .cp-m:not(.mine):not(.sys) { box-shadow: 0 2px 8px rgba(0,0,0,.05); }
    .cp-m.sending { opacity: .65; } .cp-m .retry { background: none; border: 0; color: #fecaca; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; padding: 0; }
    .cp-m img { cursor: zoom-in; } .cp-m .tm .rd { color: #7dd3fc; }
    .cp-day { align-self: center; font-size: 12px; color: var(--muted); background: var(--card); border-radius: 99px; padding: 3px 12px; margin: 8px 0; }
    .cp-lightbox { position: fixed; inset: 0; background: rgba(0,0,0,.88); z-index: 3000; display: grid; place-items: center; padding: 20px; animation: cpIn .2s ease-out; }
    .cp-lightbox img { max-width: 100%; max-height: 90vh; border-radius: 12px; }
    .cp-lightbox button { position: absolute; top: 16px; right: 16px; width: 44px; height: 44px; border-radius: 50%; border: 0; background: rgba(255,255,255,.15); color: #fff; font-size: 22px; cursor: pointer; }
    @keyframes cpDot { 0%, 60%, 100% { transform: translateY(0); opacity: .5; } 30% { transform: translateY(-4px); opacity: 1; } }
    .cp-form { display: flex; gap: 8px; padding: 10px 12px 12px; align-items: flex-end; }
    .cp-rec, .cp-vm { display: flex; gap: 10px; align-items: center; padding: 10px 12px 12px; }
    .cp-rec .dot { width: 10px; height: 10px; border-radius: 50%; background: #dc2626; animation: cpRec 1.2s infinite; }
    .cp-rec #cp-rec-t { font-variant-numeric: tabular-nums; font-weight: 700; min-width: 40px; }
    .cp-rec .bars { display: inline-flex; gap: 3px; align-items: center; height: 26px; } .cp-rec .bars i { width: 4px; height: 4px; border-radius: 2px; background: var(--lagoon); transition: height .1s; }
    .cp-rec .grow, .cp-vm .grow { flex: 1; min-width: 0; }
    .cp-vm { margin: 0 12px 10px; border-radius: 14px; background: color-mix(in srgb, var(--lagoon) 10%, transparent); font-size: 14px; }
    .cp-vm > i { font-size: 20px; color: var(--lagoon); } .cp-vm button { border: 0; border-radius: 10px; padding: 7px 12px; font: inherit; font-weight: 600; cursor: pointer; background: var(--lagoon); color: #fff; }
    .cp-vm #cp-vm-x { background: transparent; color: var(--ink); padding: 6px; }
    .hv-note { display: flex; align-items: center; gap: 8px; } .hv-note audio { height: 36px; max-width: 230px; } .hv-len { font-size: 12px; opacity: .7; }
    @keyframes cpRec { 50% { opacity: .3; } }
    .cp-form textarea { flex: 1; resize: none; border: 1.5px solid var(--line); border-radius: 14px; padding: 10px 12px; font: inherit; max-height: 130px; }
    .cp-send { background: var(--lagoon); color: #fff; border-color: transparent; }
    .pulse { width: 12px; height: 12px; border-radius: 50%; background: #22c55e; animation: cpPulse 1.4s infinite; flex: 0 0 auto; }
    .pulse.big { width: 54px; height: 54px; display: grid; place-items: center; font-size: 24px; color: #fff; }
    .cp-incoming { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); width: min(360px, calc(100vw - 32px)); background: var(--ink); color: #fff; border-radius: 22px; padding: 16px; display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: center; box-shadow: 0 20px 50px rgba(0,0,0,.35); z-index: 50; }
    .cp-incoming .acts { grid-column: 1 / -1; display: flex; gap: 10px; } .cp-incoming .acts button { flex: 1; border: 0; border-radius: 12px; padding: 11px; font-weight: 700; background: #334155; color: #fff; font: inherit; font-weight: 700; } .cp-incoming .yes { background: #16a34a !important; }
    @keyframes cpIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    @keyframes cpPulse { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,.6); } 70% { box-shadow: 0 0 0 14px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
    @media (prefers-reduced-motion: reduce) { .cp-m, .cp-lightbox { animation: none; } .pulse, .cp-typing span { animation: none; } }
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

    const SECTIONS = Object.fromEntries(HOME.sections.map(x => [x.id, x]));
    function bubble(m) {
        if (m.sender === 'system') return `<div class="cp-m sys" data-id="${m.id}">${esc(m.body)}</div>`;
        const tick = m.mine && m.id ? (m.read ? ' <span class="rd">✓✓</span>' : ' ✓') : '';
        return `<div class="cp-m ${m.mine ? 'mine' : ''} ${m.id ? '' : 'sending'}" data-id="${m.id || ''}" data-client="${esc(m.client_id || '')}">${!m.mine ? `<div class="by">${esc(m.name)}</div>` : ''}
            ${m.photo ? `<img src="${esc(m.photo)}" alt="Photo" loading="lazy">` : ''}${m.audio ? HotelVoiceNote.html(m, esc) : ''}${m.body ? `<div class="t">${esc(m.body)}</div>` : ''}
            <div class="tm">${esc(m.time || '')}${tick}</div></div>`;
    }
    const atEnd = () => { const b = $('cp-msgs'); return b.scrollHeight - b.scrollTop - b.clientHeight < 90; };
    const toEnd = () => { const b = $('cp-msgs'); b.scrollTo({ top: b.scrollHeight, behavior: 'smooth' }); $('cp-latest').hidden = true; };
    function add(list) {
        const box = $('cp-msgs'), wasEnd = atEnd();
        let theirs = false;
        list.forEach(m => {
            if (m.id && box.querySelector(`.cp-m[data-id="${m.id}"]`)) return;
            const p = m.client_id && box.querySelector(`.cp-m[data-client="${CSS.escape(m.client_id)}"]`);
            if (m.day && m.day !== st.day) { st.day = m.day; box.insertAdjacentHTML('beforeend', `<div class="cp-day">${esc(m.day)}</div>`); }
            if (p) p.outerHTML = bubble(m); else box.insertAdjacentHTML('beforeend', bubble(m));
            if (m.id) st.lastId = Math.max(st.lastId, m.id);
            if (!m.mine && m.sender !== 'system' && m.id) theirs = true;
        });
        if (theirs) { $('cp-typing').hidden = true; notify(); }
        // Stay where the guest is reading; offer a way down instead of yanking the page.
        if (wasEnd || list.some(m => m.mine)) box.scrollTop = box.scrollHeight; else if (theirs) $('cp-latest').hidden = false;
        $('cp-sugg').hidden = box.querySelectorAll('.cp-m.mine').length > 2;
    }
    $('cp-msgs').addEventListener('scroll', () => { if (atEnd()) $('cp-latest').hidden = true; });
    $('cp-latest').addEventListener('click', toEnd);
    $('cp-msgs').addEventListener('click', e => { const img = e.target.closest('.cp-m img'); if (img) { $('cp-lb').querySelector('img').src = img.src; $('cp-lb').hidden = false; } });
    $('cp-lb').addEventListener('click', () => $('cp-lb').hidden = true);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') $('cp-lb').hidden = true; });

    // A reply while the guest is on another tab: a soft chime and a count in the tab's title.
    const baseTitle = document.title; let missed = 0;
    function notify() {
        if (!document.hidden) return;
        missed++; document.title = `(${missed}) New reply · ${baseTitle}`;
        try { const C = window.AudioContext || window.webkitAudioContext; const c = new C(), o = c.createOscillator(), g = c.createGain(); o.frequency.value = 880; g.gain.setValueAtTime(0.0001, c.currentTime); g.gain.exponentialRampToValueAtTime(0.12, c.currentTime + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + 0.4); o.connect(g).connect(c.destination); o.start(); o.stop(c.currentTime + 0.45); setTimeout(() => c.close(), 600); } catch (e) {}
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { missed = 0; document.title = baseTitle; } });

    function suggestions(list) {
        $('cp-sugg').innerHTML = (list || []).map(q => `<button type="button">${esc(q)}</button>`).join('');
    }
    $('cp-sugg').addEventListener('click', e => { const b = e.target.closest('button'); if (b && st.sec) send(b.textContent); });

    async function open(id) {
        st.sec = id; st.lastId = 0; st.day = '';
        document.querySelectorAll('.cp-sec').forEach(b => b.classList.toggle('on', +b.dataset.id === id));
        $('cp').classList.add('open'); $('cp-panel').hidden = false; $('cp-msgs').innerHTML = ''; $('cp-typing').hidden = true; $('cp-latest').hidden = true;
        suggestions(SECTIONS[id]?.suggestions); $('cp-sugg').hidden = false;
        try {
            const d = await req('/chat/' + id);
            $('cp-name').textContent = d.section.name;
            $('cp-sub').textContent = d.section.open ? 'Open now' + (d.section.hours ? ' · ' + d.section.hours : '') : 'Closed now' + (d.section.hours ? ' · open ' + d.section.hours : '') + ' — leave a message';
            $('cp-call').hidden = !d.section.can_call;
            $('cp-tel').hidden = !d.section.phone; if (d.section.phone) $('cp-tel').href = 'tel:' + d.section.phone;
            if (d.section.suggestions) suggestions(d.section.suggestions);
            add(d.messages);
            if (!d.messages.length) $('cp-msgs').innerHTML = `<div class="cp-m sys">Say hello to ${esc(d.section.name)}. ${d.section.open ? 'Someone will answer here.' : 'They’ll answer when they open.'}</div>`;
            document.querySelector(`.cp-sec[data-id="${id}"] .cp-n`)?.remove();
            if (window.matchMedia('(pointer: fine)').matches) $('cp-text').focus();
        } catch (e) { $('cp-msgs').innerHTML = `<div class="cp-m sys">${esc(e.message)}</div>`; }
    }
    async function refresh() {
        if (!st.sec) return;
        try { const d = await req(`/chat/${st.sec}?after=${st.lastId}`); add(d.messages); if (d.typing) typing();
            if (d.read_up_to) document.querySelectorAll('.cp-m.mine').forEach(el => { if (+el.dataset.id <= d.read_up_to) { const t = el.querySelector('.tm'); if (t && !t.querySelector('.rd')) t.innerHTML = t.innerHTML.replace(' ✓', ' <span class="rd">✓✓</span>'); } });
        } catch (e) {}
    }
    let tT; function typing() { $('cp-typing').hidden = false; if (atEnd()) $('cp-msgs').scrollTop = $('cp-msgs').scrollHeight; clearTimeout(tT); tT = setTimeout(() => $('cp-typing').hidden = true, 6000); }
    document.querySelectorAll('.cp-sec').forEach(b => b.addEventListener('click', () => open(+b.dataset.id)));
    $('cp-back').addEventListener('click', () => { $('cp').classList.remove('open'); $('cp-panel').hidden = true; st.sec = null; });

    async function send(body, photo, client_id, voice) {
        client_id = client_id || uuid();
        const preview = photo ? URL.createObjectURL(photo) : null;
        const pending = { client_id, sender: 'guest', mine: true, body, photo: preview, time: 'Sending…', audio: voice ? URL.createObjectURL(voice.blob) : null, audio_label: voice ? '(' + Math.floor(voice.seconds / 60) + ':' + String(voice.seconds % 60).padStart(2, '0') + ')' : null };
        if (!document.querySelector(`.cp-m[data-client="${CSS.escape(client_id)}"]`)) add([pending]);
        else document.querySelector(`.cp-m[data-client="${CSS.escape(client_id)}"] .tm`).textContent = 'Sending…';
        const fd = new FormData(); fd.append('client_id', client_id); if (body) fd.append('body', body); if (photo) fd.append('photo', photo);
        if (voice) { fd.append('audio', voice.blob, voice.name); fd.append('audio_seconds', voice.seconds); }
        for (let i = 0; i < 3; i++) {
            try { const d = await req('/chat/' + st.sec, { method: 'POST', body: fd }); add([d.message]); setTimeout(refresh, 600); return; }
            catch (e) {
                if (i === 2) {
                    const el = document.querySelector(`.cp-m[data-client="${CSS.escape(client_id)}"] .tm`);
                    if (el) { el.innerHTML = `Not sent · <button type="button" class="retry">Try again</button>`; el.querySelector('.retry').onclick = () => send(body, photo, client_id, voice); }
                } else await new Promise(r => setTimeout(r, 1500 * (i + 1)));
            }
        }
    }
    const autosize = () => { const t = $('cp-text'); t.style.height = 'auto'; t.style.height = Math.min(130, t.scrollHeight) + 'px'; };
    $('cp-form').addEventListener('submit', e => { e.preventDefault(); const t = $('cp-text').value.trim(); if (t) { $('cp-text').value = ''; autosize(); send(t); } });
    $('cp-text').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && window.matchMedia('(pointer: fine)').matches) { e.preventDefault(); $('cp-form').requestSubmit(); } });
    let typAt = 0; $('cp-text').addEventListener('input', () => { autosize(); if (st.sec && Date.now() - typAt > 3000) { typAt = Date.now(); req(`/chat/${st.sec}/typing`, { method: 'POST' }).catch(() => {}); } });
    $('cp-photo').addEventListener('change', e => { const f = e.target.files[0]; if (f && st.sec) send(null, f); e.target.value = ''; });

    // ── Voice messages (also offered when a call isn't answered) ──
    let rec = null;
    if (window.HotelVoiceNote && HotelVoiceNote.supported()) $('cp-mic').hidden = false;
    function record(why) {
        if (rec || !st.sec) return;
        $('cp-vm').hidden = true; $('cp-form').hidden = true; $('cp-rec').hidden = false; $('cp-rec-why').textContent = why || 'Recording… up to 2 minutes';
        const bars = $('cp-rec-b').querySelectorAll('i');
        rec = HotelVoiceNote.start({ max: 120, onTick: (s, lv) => { $('cp-rec-t').textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); bars.forEach(b => b.style.height = (4 + lv * 22 * Math.random()) + 'px'); } });
        rec.promise.catch(() => { stopRec(); alert('The microphone isn’t available. Allow it in the browser to send a voice message.'); });
    }
    function stopRec() { rec = null; $('cp-rec').hidden = true; $('cp-form').hidden = false; }
    $('cp-mic').addEventListener('click', () => record());
    $('cp-rec-x').addEventListener('click', () => { rec?.cancel(); stopRec(); });
    $('cp-rec-ok').addEventListener('click', async () => { const r = rec; if (!r) return; try { const v = await r.stop(); stopRec(); send(null, null, null, v); } catch (e) { stopRec(); } });
    $('cp-vm-go').addEventListener('click', () => record('Leave your message… up to 2 minutes'));
    $('cp-vm-x').addEventListener('click', () => { $('cp-vm').hidden = true; });

    // ── Calls: a floating call card with sound bars, the line's quality, mute and end ──
    function startCall(c, name) {
        const sec = SECTIONS[c.section_id] || SECTIONS[st.sec] || {};
        const ui = new HotelCallUI({ title: name || c.section || sec.name || 'The hotel', subtitle: @json(config('hotel.name')), icon: sec.icon || 'ri-customer-service-2-line', label: 'Voice call' });
        const call = new HotelCall(Object.assign({ call: c, base: `${BASE}/calls/${c.uuid}`, csrf: CSRF, audio: $('cp-audio'),
            onEnd: () => { st.call = null; refresh(); if (call.call.status === 'missed' && call.call.direction === 'in' && window.HotelVoiceNote && HotelVoiceNote.supported()) $('cp-vm').hidden = false; } }, ui.hooks(), { onState: (t, p) => ui.status(t, p) }));
        ui.bind(call);
        st.call = call;
        call.start().catch(e => { ui.status(e.message, 'ended'); call.hangUp(); });
    }
    $('cp-call').addEventListener('click', async () => {
        if (st.call || !st.sec) return;
        try { const c = await req('/calls', { method: 'POST', json: true, body: JSON.stringify({ section_id: st.sec }) }); startCall(c, $('cp-name').textContent); }
        catch (e) { const ui = new HotelCallUI({ title: $('cp-name').textContent }); ui.status(e.message, 'ended'); }
    });

    let ringing = null, stopTone = null;
    function incoming(c) {
        if (!c || st.call || ringing === c.uuid || c.status !== 'ringing' || c.direction !== 'out') return;
        ringing = c.uuid; $('cp-in-t').textContent = c.section || 'The hotel'; $('cp-in').hidden = false; stopTone = hotelRingTone();
        $('cp-in-yes').onclick = async () => { $('cp-in').hidden = true; stopTone?.(); try { const a = await req(`/calls/${c.uuid}/answer`, { method: 'POST' }); if (a.section_id) open(a.section_id); startCall(a, c.section); } catch (e) { const ui = new HotelCallUI({ title: c.section || 'The hotel' }); ui.status(e.message, 'ended'); } };
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
