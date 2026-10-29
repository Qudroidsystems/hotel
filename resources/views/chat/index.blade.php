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
    .gc-compose textarea { flex: 1; resize: none; border: 1px solid var(--cb-border); border-radius: 14px; padding: 9px 12px; max-height: 140px; background: var(--cb-surface, #fff); color: var(--cb-text); }
    .gc-iconbtn { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--cb-border); background: var(--cb-surface, #fff); display: grid; place-items: center; font-size: 19px; cursor: pointer; color: var(--cb-text); flex: 0 0 auto; }
    .gc-send { background: #0f766e; color: #fff; border-color: transparent; }
    .gc-empty { margin: auto; text-align: center; color: var(--cb-muted); padding: 40px; }
    .gc-empty i { font-size: 46px; color: #0f766e; }
    .gc-live { font-size: 11.5px; color: var(--cb-muted); display: inline-flex; align-items: center; gap: 5px; }
    .gc-live .dot { width: 8px; height: 8px; border-radius: 50%; background: #94a3b8; }
    .gc-live.on .dot { background: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.2); }
    /* Calls */
    .gc-callbar { display: none; align-items: center; gap: 10px; padding: 10px 16px; background: #0f172a; color: #fff; }
    .gc-callbar.on { display: flex; }
    .gc-callbar .st { flex: 1; font-weight: 600; }
    .gc-callbar button { border: 0; border-radius: 99px; padding: 7px 14px; font-weight: 700; cursor: pointer; }
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
            <div class="gc-callbar" id="gc-callbar" role="status"><i class="ri-phone-fill"></i><span class="st" id="gc-callst"></span><span id="gc-calltime"></span>
                <button id="gc-mute" style="background:#334155;color:#fff">Mute</button><button id="gc-hang" style="background:#dc2626;color:#fff">End</button></div>
            <div class="gc-thread d-none" id="gc-thread"></div>
            <div class="gc-typing d-none" id="gc-typing"></div>
            <div class="gc-quick d-none" id="gc-quick"></div>
            <form class="gc-compose d-none" id="gc-form" autocomplete="off">
                <label class="gc-iconbtn mb-0" title="Send a photo"><i class="ri-image-add-line"></i><input type="file" accept="image/*" id="gc-photo" hidden></label>
                <textarea id="gc-text" rows="1" maxlength="2000" placeholder="Write to the guest… (Enter sends, Shift+Enter for a new line)" aria-label="Message"></textarea>
                <button class="gc-iconbtn gc-send" type="submit" aria-label="Send"><i class="ri-send-plane-2-fill"></i></button>
            </form>
        </section>
    </div>

    <div class="gc-ring" id="gc-ring" role="alertdialog" aria-labelledby="gc-ring-t">
        <div class="d-flex gap-3 align-items-center"><div class="pulse"><i class="ri-phone-fill"></i></div>
            <div><div class="small" style="opacity:.7" id="gc-ring-s"></div><div class="fw-bold" id="gc-ring-t"></div><div class="small" style="opacity:.8" id="gc-ring-r"></div></div></div>
        <div class="act"><button id="gc-decline" style="background:#334155;color:#fff">Decline</button><button id="gc-answer" style="background:#16a34a;color:#fff">Answer</button></div>
    </div>
    <audio id="gc-audio" autoplay playsinline></audio>

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
            if (ev === 'chat.message') { if (st.current && d.conversation && d.conversation.id === st.current) { addMessages([d.message]); markRead(); } loadList(); }
            if (ev === 'chat.typing' && st.current && d.conversation_id === st.current) showTyping();
            if (ev === 'chat.read' && st.current && d.conversation_id === st.current) document.querySelectorAll('.gc-msg.mine .tick').forEach(t => t.textContent = '✓✓');
            if (ev === 'call.ring' || ev === 'call.state') ringCheck();
            if (ev === 'call.signal' && st.call && d.call === st.call.call.uuid) st.call.handle(d);
        },
    });
    if (!@json($live['enabled'] ?? false)) { $('gc-live').querySelector('span:last-child').textContent = 'Checking every few seconds'; }

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
            ${m.photo ? `<a href="${esc(m.photo)}" target="_blank" rel="noopener"><img src="${esc(m.photo)}" alt="Photo" loading="lazy"></a>` : ''}
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
    let typT; function showTyping() { $('gc-typing').textContent = 'The guest is typing…'; clearTimeout(typT); typT = setTimeout(() => $('gc-typing').textContent = '', 6000); }
    $('gc-back').addEventListener('click', () => { $('gc').classList.remove('thread-open'); st.current = null; });

    // ── Writing ──
    async function send(body, photo) {
        if (!st.current || (!body && !photo)) return;
        const client_id = uuid();
        if (body) addMessages([{ client_id, sender: 'staff', mine: true, body, time: 'sending…' }]);
        document.querySelector(`.gc-msg[data-client="${client_id}"]`)?.classList.add('sending');
        const fd = new FormData(); fd.append('client_id', client_id); if (body) fd.append('body', body); if (photo) fd.append('photo', photo);
        for (let i = 0; i < 3; i++) {
            try { const d = await req('/' + st.current, { method: 'POST', body: fd }); addMessages([d.message]); return; }
            catch (e) { if (i === 2) { const el = document.querySelector(`.gc-msg[data-client="${client_id}"] .tm`); if (el) el.textContent = 'Not sent — ' + e.message; } else await new Promise(r => setTimeout(r, 1500 * (i + 1))); }
        }
    }
    $('gc-form').addEventListener('submit', e => { e.preventDefault(); const t = $('gc-text').value.trim(); if (!t) return; $('gc-text').value = ''; autosize(); send(t); });
    $('gc-text').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('gc-form').requestSubmit(); } });
    const autosize = () => { const t = $('gc-text'); t.style.height = 'auto'; t.style.height = Math.min(140, t.scrollHeight) + 'px'; };
    $('gc-text').addEventListener('input', () => { autosize(); if (st.current && Date.now() - st.typingAt > 3000) { st.typingAt = Date.now(); req(`/${st.current}/typing`, { method: 'POST' }).catch(() => {}); } });
    $('gc-photo').addEventListener('change', e => { const f = e.target.files[0]; if (f) send(null, f); e.target.value = ''; });
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
        const call = new HotelCall({ call: c, base: `${API}/calls/${c.uuid}`, csrf: CSRF, audio: $('gc-audio'),
            onState: (text, phase) => { $('gc-callbar').classList.add('on'); $('gc-callst').textContent = text; if (phase === 'ended') setTimeout(() => $('gc-callbar').classList.remove('on'), 2500); },
            onEnd: () => { st.call = null; refreshThread(); } });
        st.call = call;
        call.start().catch(e => { alertBar(e.message); call.hangUp(); });
    }
    function alertBar(t) { $('gc-callbar').classList.add('on'); $('gc-callst').textContent = t; setTimeout(() => { if (!st.call) $('gc-callbar').classList.remove('on'); }, 4000); }
    $('gc-hang').addEventListener('click', () => st.call?.hangUp());
    $('gc-mute').addEventListener('click', e => { if (!st.call) return; const m = e.target.textContent === 'Mute'; st.call.mute(m); e.target.textContent = m ? 'Unmute' : 'Mute'; });
    $('gc-callback').addEventListener('click', async () => {
        if (!st.current || st.call) return;
        try { const c = await req(`/${st.current}/call`, { method: 'POST' }); startCall(c); } catch (e) { alertBar(e.message); }
    });
    setInterval(() => { if (st.call && st.call.seconds()) { const s = st.call.seconds(); $('gc-calltime').textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); } }, 1000);
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
