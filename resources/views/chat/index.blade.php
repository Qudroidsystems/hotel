@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<style>
    .gc { display: grid; grid-template-columns: 340px minmax(0, 1fr); height: calc(100vh - 210px); min-height: 520px; background: var(--cb-surface, #fff); border: 1px solid var(--cb-border); border-radius: 16px; overflow: hidden; }
    .gc-side { border-right: 1px solid var(--cb-border); display: flex; flex-direction: column; min-height: 0; }
    .gc-filters { padding: 12px; border-bottom: 1px solid var(--cb-border); display: grid; gap: 8px; }
    .gc-chips { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 2px; }
    .gc-chip { border: 1px solid var(--cb-border); background: transparent; border-radius: 99px; padding: 4px 11px; font-size: 12.5px; font-weight: 600; white-space: nowrap; color: var(--cb-text); cursor: pointer; }
    .gc-chip.on { background: var(--cb-heading, #0f172a); color: #fff; border-color: transparent; }
    .gc-chip .n { background: #dc2626; color: #fff; border-radius: 99px; padding: 0 6px; margin-left: 4px; font-size: 11px; }
    .gc-list { overflow-y: auto; flex: 1; }
    .gc-item { display: flex; gap: 10px; padding: 11px 12px; border-bottom: 1px solid var(--cb-border); cursor: pointer; align-items: flex-start; }
    .gc-item:hover { background: var(--cb-surface-2, #f8fafc); }
    .gc-item.on { background: rgba(13,148,136,.08); }
    .gc-av { width: 40px; height: 40px; border-radius: 12px; background: #b7832f; color: #fff; font-weight: 800; font-size: 13px; display: grid; place-items: center; flex: 0 0 auto; }
    .gc-item .who { font-weight: 700; color: var(--cb-heading); display: flex; justify-content: space-between; gap: 6px; }
    .gc-item .who small { font-weight: 500; color: var(--cb-muted); white-space: nowrap; }
    .gc-item .pre { font-size: 13px; color: var(--cb-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .gc-item.unread .pre { color: var(--cb-text); font-weight: 600; }
    .gc-item .badge-n { background: #0d9488; color: #fff; border-radius: 99px; font-size: 11px; padding: 1px 7px; font-weight: 700; }
    .gc-main { display: flex; flex-direction: column; min-height: 0; min-width: 0; }
    .gc-head { padding: 12px 16px; border-bottom: 1px solid var(--cb-border); display: flex; gap: 12px; align-items: center; }
    .gc-head h3 { font-size: 16px; margin: 0; color: var(--cb-heading); }
    .gc-head .meta { font-size: 12.5px; color: var(--cb-muted); }
    .gc-thread { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 6px; background: var(--cb-bg, #f4f6f5); }
    .gc-day { align-self: center; font-size: 11.5px; color: var(--cb-muted); background: var(--cb-surface, #fff); border-radius: 99px; padding: 2px 10px; margin: 8px 0; }
    .gc-msg { max-width: min(560px, 78%); padding: 8px 12px 6px; border-radius: 16px; background: var(--cb-surface, #fff); box-shadow: 0 1px 1px rgba(0,0,0,.04); align-self: flex-start; animation: gcIn .22s ease-out; }
    .gc-msg.mine { align-self: flex-end; background: #0f766e; color: #fff; border-bottom-right-radius: 6px; }
    .gc-msg:not(.mine) { border-bottom-left-radius: 6px; }
    .gc-msg.sys { align-self: center; background: transparent; box-shadow: none; color: var(--cb-muted); font-size: 12.5px; text-align: center; }
    .gc-msg .by { font-size: 11.5px; font-weight: 700; opacity: .75; }
    .gc-msg .txt { white-space: pre-wrap; word-wrap: break-word; }
    .gc-msg img { max-width: 260px; border-radius: 10px; display: block; margin: 4px 0; cursor: zoom-in; }
    .gc-msg .tm { font-size: 10.5px; opacity: .7; text-align: right; margin-top: 2px; }
    .gc-msg.sending { opacity: .6; }
    .gc-typing { font-size: 12.5px; color: var(--cb-muted); padding: 0 16px 6px; height: 20px; }
    .gc-quick { display: flex; gap: 6px; overflow-x: auto; padding: 8px 12px 0; }
    .gc-quick button { border: 1px solid var(--cb-border); background: var(--cb-surface, #fff); border-radius: 99px; padding: 4px 10px; font-size: 12px; white-space: nowrap; cursor: pointer; color: var(--cb-text); }
    .gc-compose { display: flex; gap: 8px; padding: 10px 12px 12px; align-items: flex-end; }
    .gc-rec { align-items: center; } .gc-rec[hidden] { display: none; } .gc-rec .dot { width: 10px; height: 10px; border-radius: 50%; background: #dc2626; animation: gcRec 1.2s infinite; }
    .hv-note { display: flex; align-items: center; gap: 8px; } .hv-note audio { height: 36px; max-width: 240px; } .hv-len { font-size: 12px; opacity: .7; }
    @keyframes gcRec { 50% { opacity: .3; } }
    .gc-compose textarea { flex: 1; resize: none; border: 1px solid var(--cb-border); border-radius: 14px; padding: 9px 12px; max-height: 140px; background: var(--cb-surface, #fff); color: var(--cb-text); }
    .gc-iconbtn { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--cb-border); background: var(--cb-surface, #fff); display: grid; place-items: center; font-size: 19px; cursor: pointer; color: var(--cb-text); flex: 0 0 auto; }
    .gc-send { background: #0f766e; color: #fff; border-color: transparent; }
    .gc-empty { margin: auto; text-align: center; color: var(--cb-muted); padding: 40px; }
    .gc-empty i { font-size: 46px; color: #0f766e; }
    .gc-live { font-size: 11.5px; color: var(--cb-muted); display: inline-flex; align-items: center; gap: 5px; }
    .gc-live .dot { width: 8px; height: 8px; border-radius: 50%; background: #94a3b8; }
    .gc-live.on .dot { background: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.2); }
    .gc-msg .retry { background: none; border: 0; color: inherit; text-decoration: underline; font-weight: 700; padding: 0; cursor: pointer; }
    .gc-typing .dots span { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: currentColor; margin-right: 3px; animation: gcDot 1.2s infinite; }
    .gc-typing .dots span:nth-child(2) { animation-delay: .15s; } .gc-typing .dots span:nth-child(3) { animation-delay: .3s; }
    @keyframes gcDot { 0%, 60%, 100% { transform: translateY(0); opacity: .5; } 30% { transform: translateY(-3px); opacity: 1; } }
    .gc-lightbox { position: fixed; inset: 0; background: rgba(0,0,0,.88); z-index: 3000; display: grid; place-items: center; padding: 20px; }
    .gc-lightbox[hidden] { display: none; }
    .gc-lightbox img { max-width: 100%; max-height: 90vh; border-radius: 12px; }
    .gc-lightbox button { position: absolute; top: 18px; right: 18px; }
    /* Calls */
    .gc-ring { position: fixed; right: 24px; bottom: 24px; z-index: 1060; width: 320px; background: #0f172a; color: #fff; border-radius: 20px; padding: 18px; box-shadow: 0 20px 50px rgba(0,0,0,.35); display: none; animation: gcIn .25s ease-out; }
    .gc-ring.on { display: block; }
    .gc-ring .pulse { width: 52px; height: 52px; border-radius: 50%; background: #16a34a; display: grid; place-items: center; font-size: 24px; animation: gcPulse 1.4s infinite; }
    .gc-ring .act { display: flex; gap: 10px; margin-top: 14px; }
    .gc-ring .act button { flex: 1; border: 0; border-radius: 12px; padding: 10px; font-weight: 700; cursor: pointer; }
    @keyframes gcIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    @keyframes gcPulse { 0% { box-shadow: 0 0 0 0 rgba(22,163,74,.6); } 70% { box-shadow: 0 0 0 16px rgba(22,163,74,0); } 100% { box-shadow: 0 0 0 0 rgba(22,163,74,0); } }
    @media (prefers-reduced-motion: reduce) { .gc-msg, .gc-ring { animation: none; } .gc-ring .pulse { animation: none; } }
    @media (max-width: 900px) {
        .gc { grid-template-columns: 1fr; height: calc(100vh - 170px); }
        .gc.thread-open .gc-side { display: none; }
        .gc:not(.thread-open) .gc-main { display: none; }
    }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-chat-smile-3-line" subtitle="Guests write and call from the guest app and their booking page. You see the sections you answer; calls ring here and in the staff app.">
        <x-slot:actions>
            <span class="cb-hero-btn gc-live" id="gc-live" title="Live updates"><span class="dot"></span> <span>Checking</span></span>
            @can('Manage guest chat')<a class="cb-hero-btn" href="{{ route('chat.sections') }}"><i class="ri-settings-3-line"></i> Sections</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @if ($sections->isEmpty())
        <div class="cb-banner info"><i class="ri-information-line"></i><div>You don’t answer any chat section yet. A manager adds you (or your role) under <strong>Guest Chat → Sections</strong>.</div></div>
    @endif

    <div class="gc" id="gc">
        <aside class="gc-side">
            <div class="gc-filters">
                <input type="search" class="form-control form-control-sm" id="gc-q" placeholder="Guest name or booking reference" aria-label="Search chats">
                <div class="gc-chips" id="gc-sections" role="tablist" aria-label="Sections"></div>
                <div class="gc-chips">
                    <button class="gc-chip on" data-status="open">Open</button><button class="gc-chip" data-status="closed">Closed</button><button class="gc-chip" data-status="all">All</button>
                </div>
            </div>
            <div class="gc-list" id="gc-list" aria-live="polite"></div>
        </aside>
        <section class="gc-main" id="gc-main">
            <div class="gc-empty" id="gc-none"><i class="ri-chat-smile-3-line"></i><p class="mb-0 mt-2">Pick a conversation on the left.</p></div>
            <div class="gc-head d-none" id="gc-head">
                <button class="gc-iconbtn d-md-none" id="gc-back" aria-label="Back to the list"><i class="ri-arrow-left-line"></i></button>
                <div class="gc-av" id="gc-hav"></div>
                <div class="flex-grow-1 min-w-0"><h3 id="gc-hname"></h3><div class="meta" id="gc-hmeta"></div></div>
                <button class="gc-iconbtn" id="gc-callback" title="Call the guest"><i class="ri-phone-line"></i></button>
                <a class="gc-iconbtn" id="gc-booking" title="Open the booking" target="_blank"><i class="ri-hotel-bed-line"></i></a>
                <button class="gc-iconbtn" id="gc-close" title="Close the chat (it reopens when the guest writes)"><i class="ri-check-double-line"></i></button>
            </div>
            <div class="gc-thread d-none" id="gc-thread"></div>
            <div class="gc-typing d-none" id="gc-typing"></div>
            <div class="gc-quick d-none" id="gc-quick"></div>
            <form class="gc-compose d-none" id="gc-form" autocomplete="off">
                <label class="gc-iconbtn mb-0" title="Send a photo"><i class="ri-image-add-line"></i><input type="file" accept="image/*" id="gc-photo" hidden></label>
                <textarea id="gc-text" rows="1" maxlength="2000" placeholder="Write to the guest… (Enter sends, Shift+Enter for a new line)" aria-label="Message"></textarea>
                <button type="button" class="gc-iconbtn" id="gc-mic" title="Record a voice message" aria-label="Record a voice message" hidden><i class="ri-mic-line"></i></button>
                <button class="gc-iconbtn gc-send" type="submit" aria-label="Send"><i class="ri-send-plane-2-fill"></i></button>
            </form>
            <div class="gc-compose gc-rec" id="gc-rec" hidden><span class="dot"></span><strong id="gc-rec-t" style="font-variant-numeric:tabular-nums;min-width:40px">0:00</strong><span class="flex-grow-1 small text-muted">Recording a voice message…</span>
                <button type="button" class="gc-iconbtn" id="gc-rec-x" aria-label="Cancel"><i class="ri-delete-bin-line"></i></button><button type="button" class="gc-iconbtn gc-send" id="gc-rec-ok" aria-label="Send the voice message"><i class="ri-send-plane-2-fill"></i></button></div>
        </section>
    </div>

    <div class="gc-ring" id="gc-ring" role="alertdialog" aria-labelledby="gc-ring-t">
        <div class="d-flex gap-3 align-items-center"><div class="pulse"><i class="ri-phone-fill"></i></div>
            <div><div class="small" style="opacity:.7" id="gc-ring-s"></div><div class="fw-bold" id="gc-ring-t"></div><div class="small" style="opacity:.8" id="gc-ring-r"></div></div></div>
        <div class="act"><button id="gc-decline" style="background:#334155;color:#fff">Decline</button><button id="gc-answer" style="background:#16a34a;color:#fff">Answer</button></div>
    </div>
    <audio id="gc-audio" autoplay playsinline></audio>
    <div class="gc-lightbox" id="gc-lb" hidden role="dialog" aria-label="Photo"><img alt="Photo"><button type="button" class="btn-close btn-close-white" aria-label="Close"></button></div>

</div>
</div>
</div>
<script src="{{ asset('js/hotel-live.js') }}?v={{ @filemtime(public_path('js/hotel-live.js')) }}"></script>
<script>
(() => {
    const API = @json(url('/chat/api'));
    const CSRF = document.querySelector('meta[name=csrf-token]')?.content || @json(csrf_token());
    const BOOKING = @json(url('/reservations')) + '/';
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const initials = (n) => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(x => x[0]).join('').toUpperCase();
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
    const st = { section: null, status: 'open', q: '', current: null, lastId: 0, list: [], typingAt: 0, call: null, ringing: {}, stopRing: null };
    const req = (path, opts = {}) => fetch(API + path, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, ...(opts.json ? { 'Content-Type': 'application/json' } : {}) } }, opts))
        .then(async r => { const j = await r.json().catch(() => ({})); if (!r.ok) throw new Error(j.message || 'Something went wrong.'); return j.data; });

    // ── Live updates: a socket when the hotel runs one, polling always (slower when the socket is up) ──
    let socketUp = false;
    const live = new HotelLive({
        config: @json($live), channels: @json($channels), authUrl: @json(route('realtime.auth')), csrf: CSRF,
        onState: (up) => { socketUp = up; $('gc-live').classList.toggle('on', up); $('gc-live').querySelector('span:last-child').textContent = up ? 'Live' : 'Checking every few seconds'; },
        onEvent: (ch, ev, d) => {
            if (ev === 'chat.message') { if (st.current && d.conversation && d.conversation.id === st.current) { addMessages([d.message]); markRead(); } loadList(); if (d.message && !d.message.mine && d.message.sender === 'guest') notify(d); }
            if (ev === 'chat.typing' && st.current && d.conversation_id === st.current) showTyping();
            if (ev === 'chat.read' && st.current && d.conversation_id === st.current) document.querySelectorAll('.gc-msg.mine .tick').forEach(t => t.textContent = '✓✓');
            if (ev === 'call.ring' || ev === 'call.state') ringCheck();
            if (ev === 'call.signal' && st.call && d.call === st.call.call.uuid) st.call.handle(d);
        },
    });
    if (!@json($live['enabled'] ?? false)) { $('gc-live').querySelector('span:last-child').textContent = 'Checking every few seconds'; }

    // A guest writes while this tab is in the background: a chime, a count in the title and (when allowed) a notification.
    const baseTitle = document.title; let missed = 0;
    function notify(d) {
        if (!document.hidden) return;
        missed++; document.title = `(${missed}) ${baseTitle}`;
        try { const C = window.AudioContext || window.webkitAudioContext; const c = new C(), o = c.createOscillator(), g = c.createGain(); o.frequency.value = 880; g.gain.setValueAtTime(0.0001, c.currentTime); g.gain.exponentialRampToValueAtTime(0.12, c.currentTime + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + 0.4); o.connect(g).connect(c.destination); o.start(); o.stop(c.currentTime + 0.45); setTimeout(() => c.close(), 600); } catch (e) {}
        if ('Notification' in window && Notification.permission === 'granted') {
            const n = new Notification((d.conversation && d.conversation.guest) || 'A guest wrote', { body: d.message.body || 'Sent a photo', tag: 'chat-' + (d.conversation ? d.conversation.id : '') });
            n.onclick = () => { window.focus(); if (d.conversation) open(d.conversation.id); n.close(); };
        }
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { missed = 0; document.title = baseTitle; } });

    // ── The list ──
    function chips(sections) {
        $('gc-sections').innerHTML = `<button class="gc-chip ${st.section === null ? 'on' : ''}" data-sec="">All</button>` + sections.map(s =>
            `<button class="gc-chip ${st.section === s.id ? 'on' : ''}" data-sec="${s.id}" title="${s.open ? 'Open now' : 'Closed now'}${s.hours ? ' · ' + esc(s.hours) : ''}"><i class="${esc(s.icon)}"></i> ${esc(s.name)}${s.unread ? `<span class="n">${s.unread}</span>` : ''}</button>`).join('');
    }
    async function loadList() {
        try {
            const p = new URLSearchParams({ status: st.status }); if (st.section) p.set('section', st.section); if (st.q) p.set('q', st.q);
            const d = await req('?' + p);
            chips(d.sections); st.list = d.conversations;
            $('gc-list').innerHTML = d.conversations.length ? d.conversations.map(c => `
                <div class="gc-item ${c.unread ? 'unread' : ''} ${c.id === st.current ? 'on' : ''}" data-id="${c.id}" tabindex="0" role="button">
                    <div class="gc-av">${c.room ? esc(c.room) : esc(initials(c.guest))}</div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="who"><span class="text-truncate">${esc(c.guest)}${c.vip ? ' <span class="status-pill st-warning">VIP</span>' : ''}</span><small>${esc(c.last_label || '')}</small></div>
                        <div class="small text-muted"><i class="${esc(c.section_icon)}"></i> ${esc(c.section)}${c.assigned ? ' · ' + esc(c.assigned) : ''}</div>
                        <div class="d-flex justify-content-between gap-2"><div class="pre">${c.typing ? '<em>typing…</em>' : esc(c.last || '')}</div>${c.unread ? `<span class="badge-n">${c.unread}</span>` : ''}</div>
                    </div></div>`).join('') : '<div class="gc-empty"><i class="ri-inbox-2-line"></i><p class="mt-2 mb-0">No chats here.</p></div>';
            (d.ringing || []).forEach(c => ring(c));
            if (!(d.ringing || []).length) stopRing();
        } catch (e) {}
    }
    $('gc-sections').addEventListener('click', e => { const b = e.target.closest('[data-sec]'); if (!b) return; st.section = b.dataset.sec ? +b.dataset.sec : null; loadList(); });
    document.querySelectorAll('[data-status]').forEach(b => b.addEventListener('click', () => { document.querySelectorAll('[data-status]').forEach(x => x.classList.toggle('on', x === b)); st.status = b.dataset.status; loadList(); }));
    let qT; $('gc-q').addEventListener('input', e => { clearTimeout(qT); qT = setTimeout(() => { st.q = e.target.value.trim(); loadList(); }, 300); });
    $('gc-list').addEventListener('click', e => { const it = e.target.closest('.gc-item'); if (it) open(+it.dataset.id); });
    $('gc-list').addEventListener('keydown', e => { if (e.key === 'Enter') { const it = e.target.closest('.gc-item'); if (it) open(+it.dataset.id); } });

    // ── A thread ──
    let lastDay = '';
    function bubble(m) {
        if (m.sender === 'system') return `<div class="gc-msg sys" data-id="${m.id}">${esc(m.body)}</div>`;
        return `<div class="gc-msg ${m.mine ? 'mine' : ''}" data-id="${m.id || ''}" data-client="${esc(m.client_id || '')}">
            ${!m.mine ? `<div class="by">${esc(m.name)}</div>` : (m.name && m.name !== 'You' ? `<div class="by">${esc(m.name)}</div>` : '')}
            ${m.photo ? `<img src="${esc(m.photo)}" alt="Photo" loading="lazy">` : ''}
            ${m.audio ? HotelVoiceNote.html(m, esc) : ''}
            ${m.body ? `<div class="txt">${esc(m.body)}</div>` : ''}
            <div class="tm">${esc(m.time || '')}${m.mine ? ` <span class="tick">${m.read ? '✓✓' : '✓'}</span>` : ''}</div></div>`;
    }
    function addMessages(list) {
        const box = $('gc-thread'), atEnd = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
        list.forEach(m => {
            if (m.id && box.querySelector(`.gc-msg[data-id="${m.id}"]`)) return;
            const pending = m.client_id && box.querySelector(`.gc-msg[data-client="${CSS.escape(m.client_id)}"]`);
            if (m.day && m.day !== lastDay) { lastDay = m.day; box.insertAdjacentHTML('beforeend', `<div class="gc-day">${esc(m.day)}</div>`); }
            if (pending) { pending.outerHTML = bubble(m); } else { box.insertAdjacentHTML('beforeend', bubble(m)); }
            if (m.id) st.lastId = Math.max(st.lastId, m.id);
        });
        if (atEnd || list.some(m => m.mine)) box.scrollTop = box.scrollHeight;
    }
    async function open(id, force) {
        if (st.current === id && !force) return;
        st.current = id; st.lastId = 0; lastDay = '';
        $('gc').classList.add('thread-open');
        ['gc-head', 'gc-thread', 'gc-form', 'gc-quick', 'gc-typing'].forEach(x => $(x).classList.remove('d-none')); $('gc-none').classList.add('d-none');
        $('gc-thread').innerHTML = '<div class="gc-empty">Loading…</div>';
        document.querySelectorAll('.gc-item').forEach(x => x.classList.toggle('on', +x.dataset.id === id));
        try {
            const d = await req('/' + id);
            const c = d.conversation;
            $('gc-hav').textContent = c.room || initials(c.guest);
            $('gc-hname').textContent = c.guest + (c.vip ? ' · VIP' : '');
            $('gc-hmeta').textContent = [c.section, c.room ? 'Room ' + c.room : null, c.reference, c.stay, c.stay_status, c.phone].filter(Boolean).join(' · ');
            $('gc-booking').href = BOOKING + c.reservation_id;
            $('gc-callback').hidden = !d.can_call;
            $('gc-thread').innerHTML = '';
            addMessages(d.messages);
            if (d.guest_read_up_to) document.querySelectorAll('.gc-msg.mine').forEach(el => { if (+el.dataset.id <= d.guest_read_up_to) { const t = el.querySelector('.tick'); if (t) t.textContent = '✓✓'; } });
            $('gc-quick').innerHTML = d.quick_replies.map(q => `<button type="button">${esc(q)}</button>`).join('');
            $('gc-thread').scrollTop = $('gc-thread').scrollHeight;
            $('gc-text').focus();
            loadList();
        } catch (e) { $('gc-thread').innerHTML = `<div class="gc-empty">${esc(e.message)}</div>`; }
    }
    async function refreshThread() {
        if (!st.current) return;
        try {
            const d = await req(`/${st.current}?after=${st.lastId}`);
            addMessages(d.messages);
            if (d.typing) showTyping();
            if (d.guest_read_up_to) document.querySelectorAll('.gc-msg.mine').forEach(el => { if (+el.dataset.id <= d.guest_read_up_to) { const t = el.querySelector('.tick'); if (t) t.textContent = '✓✓'; } });
        } catch (e) {}
    }
    const markRead = () => refreshThread();
    $('gc-thread').addEventListener('click', e => { const img = e.target.closest('.gc-msg img'); if (img) { $('gc-lb').querySelector('img').src = img.src; $('gc-lb').hidden = false; } });
    $('gc-lb').addEventListener('click', () => $('gc-lb').hidden = true);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') $('gc-lb').hidden = true; });
    let typT; function showTyping() { $('gc-typing').innerHTML = '<span class="dots"><span></span><span></span><span></span></span> The guest is typing…'; clearTimeout(typT); typT = setTimeout(() => $('gc-typing').textContent = '', 6000); }
    $('gc-back').addEventListener('click', () => { $('gc').classList.remove('thread-open'); st.current = null; });

    // ── Writing ──
    async function send(body, photo, client_id, voice) {
        if (!st.current || (!body && !photo && !voice)) return;
        const retry = !!client_id; client_id = client_id || uuid();
        if (!retry) addMessages([{ client_id, sender: 'staff', mine: true, body, photo: photo ? URL.createObjectURL(photo) : null, audio: voice ? URL.createObjectURL(voice.blob) : null, audio_label: voice ? '(' + voice.seconds + ' s)' : null, time: 'sending…' }]);
        document.querySelector(`.gc-msg[data-client="${client_id}"]`)?.classList.add('sending');
        const fd = new FormData(); fd.append('client_id', client_id); if (body) fd.append('body', body); if (photo) fd.append('photo', photo);
        if (voice) { fd.append('audio', voice.blob, voice.name); fd.append('audio_seconds', voice.seconds); }
        for (let i = 0; i < 3; i++) {
            try { const d = await req('/' + st.current, { method: 'POST', body: fd }); addMessages([d.message]); return; }
            catch (e) {
                if (i === 2) { const el = document.querySelector(`.gc-msg[data-client="${client_id}"] .tm`); if (el) { el.innerHTML = `Not sent — ${esc(e.message)} <button type="button" class="retry">Try again</button>`; el.querySelector('.retry').onclick = () => send(body, photo, client_id, voice); } }
                else await new Promise(r => setTimeout(r, 1500 * (i + 1)));
            }
        }
    }
    $('gc-form').addEventListener('submit', e => { e.preventDefault(); const t = $('gc-text').value.trim(); if (!t) return; $('gc-text').value = ''; autosize(); send(t); });
    $('gc-text').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('gc-form').requestSubmit(); } });
    const autosize = () => { const t = $('gc-text'); t.style.height = 'auto'; t.style.height = Math.min(140, t.scrollHeight) + 'px'; };
    $('gc-text').addEventListener('input', () => { autosize(); if (st.current && Date.now() - st.typingAt > 3000) { st.typingAt = Date.now(); req(`/${st.current}/typing`, { method: 'POST' }).catch(() => {}); } });
    $('gc-photo').addEventListener('change', e => { const f = e.target.files[0]; if (f) send(null, f); e.target.value = ''; });
    let rec = null;
    if (window.HotelVoiceNote && HotelVoiceNote.supported()) $('gc-mic').hidden = false;
    const recDone = () => { rec = null; $('gc-rec').hidden = true; $('gc-form').classList.remove('d-none'); };
    $('gc-mic').addEventListener('click', () => {
        if (rec || !st.current) return;
        $('gc-form').classList.add('d-none'); $('gc-rec').hidden = false;
        rec = HotelVoiceNote.start({ max: 120, onTick: (s) => { $('gc-rec-t').textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); } });
        rec.promise.catch(() => { recDone(); alertBar('The microphone isn’t available in this browser.'); });
    });
    $('gc-rec-x').addEventListener('click', () => { rec?.cancel(); recDone(); });
    $('gc-rec-ok').addEventListener('click', async () => { const r = rec; if (!r) return; try { const v = await r.stop(); recDone(); send(null, null, null, v); } catch (e) { recDone(); } });
    $('gc-quick').addEventListener('click', e => { const b = e.target.closest('button'); if (b) { $('gc-text').value = b.textContent; $('gc-text').focus(); autosize(); } });
    $('gc-close').addEventListener('click', async () => { if (!st.current) return; await req(`/${st.current}/close`, { method: 'POST' }).catch(() => {}); loadList(); });

    // ── Calls ──
    function ring(c) {
        if (st.call || st.ringing[c.uuid] === 'declined') return;
        st.ringing[c.uuid] = c;
        $('gc-ring-s').textContent = 'Incoming call · ' + c.section; $('gc-ring-t').textContent = c.guest; $('gc-ring-r').textContent = c.room ? 'Room ' + c.room : '';
        $('gc-ring').dataset.uuid = c.uuid; $('gc-ring').classList.add('on');
        if (!st.stopRing) st.stopRing = hotelRingTone();
        if (document.hidden && 'Notification' in window && Notification.permission === 'granted') new Notification('Incoming call — ' + c.section, { body: c.guest + (c.room ? ' · room ' + c.room : ''), tag: c.uuid });
    }
    function stopRing() { $('gc-ring').classList.remove('on'); if (st.stopRing) { st.stopRing(); st.stopRing = null; } }
    async function ringCheck() { try { const list = await req('/calls/ringing'); if (list.length) ring(list[0]); else stopRing(); } catch (e) {} }
    $('gc-decline').addEventListener('click', async () => { const u = $('gc-ring').dataset.uuid; st.ringing[u] = 'declined'; stopRing(); await req(`/calls/${u}/end`, { method: 'POST', json: true, body: JSON.stringify({ reason: 'declined' }) }).catch(() => {}); });
    $('gc-answer').addEventListener('click', () => answer($('gc-ring').dataset.uuid));
    async function answer(u) {
        stopRing();
        try { const c = await req(`/calls/${u}/answer`, { method: 'POST' }); startCall(c); open(c.conversation_id, true); }
        catch (e) { alertBar(e.message); }
    }
    function startCall(c) {
        const conv = st.list.find(x => x.id === c.conversation_id) || {};
        const who = c.guest || conv.guest || $('gc-hname').textContent || 'Guest';
        const ui = new HotelCallUI({ title: who, subtitle: [c.section || conv.section, (c.room || conv.room) ? 'Room ' + (c.room || conv.room) : null].filter(Boolean).join(' · '), initials: (c.room || conv.room) || initials(who), label: 'Guest call',
            transfer: { targets: (u) => req(`/calls/${u}/targets`), put: (u, t) => req(`/calls/${u}/transfer`, { method: 'POST', json: true, body: JSON.stringify(t.type === 'user' ? { user_id: t.id } : { section_id: t.id }) }) } });
        const call = new HotelCall(Object.assign({ call: c, base: `${API}/calls/${c.uuid}`, csrf: CSRF, audio: $('gc-audio'), onEnd: () => { st.call = null; refreshThread(); } }, ui.hooks()));
        ui.bind(call);
        st.call = call;
        call.start().catch(e => { ui.status(e.message, 'ended'); call.hangUp(); });
    }
    function alertBar(t) { const ui = new HotelCallUI({ title: $('gc-hname').textContent || 'Call' }); ui.status(t, 'ended'); }
    $('gc-callback').addEventListener('click', async () => {
        if (!st.current || st.call) return;
        try { const c = await req(`/${st.current}/call`, { method: 'POST' }); startCall(c); } catch (e) { alertBar(e.message); }
    });
    if ('Notification' in window && Notification.permission === 'default') document.addEventListener('click', () => Notification.requestPermission(), { once: true });

    // ── Polling: often without a socket, rarely with one ──
    const tick = (fn, fast, slow) => { const run = () => { fn(); setTimeout(run, socketUp ? slow : fast); }; setTimeout(run, fast); };
    tick(loadList, 5000, 30000);
    tick(refreshThread, 2500, 20000);
    tick(ringCheck, 4000, 30000);
    loadList().then(() => {
        const p = new URLSearchParams(location.search);
        if (p.get('answer')) answer(p.get('answer'));
        else if (p.get('c')) open(+p.get('c'));
    });
})();
</script>
@endsection
