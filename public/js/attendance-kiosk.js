/*
 * Smart Hotel — staff clock-in kiosk.
 *
 * Works offline: the kiosk keeps a list of every staff badge (as SHA-256
 * hashes, never the codes themselves), saves each scan on the device first
 * and sends it to the office when it can. Every scan has a random id the
 * server accepts once, so re-sending after a timeout never doubles a clock-in.
 *
 * Badges can be read by the tablet camera (needs https or localhost) or by a
 * USB / Bluetooth QR scanner that types the code and presses Enter (works
 * anywhere).
 */
(function () {
    'use strict';

    var URLS = window.KIOSK_URLS, BOOT = window.KIOSK_BOOT, ME = window.KIOSK_USER;
    var PREFIX = 'SHSTAFF:';
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var hhmm = function (d) { return new Date(d).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); };

    // ── SHA-256 (plain JS: crypto.subtle is unavailable on http:// LAN addresses) ──
    function sha256(ascii) {
        function rr(v, a) { return (v >>> a) | (v << (32 - a)); }
        var max = Math.pow(2, 32), result = '', words = [], bitLen = ascii.length * 8, i, j;
        var hash = sha256.h = sha256.h || [], k = sha256.k = sha256.k || [], pl = k.length;
        var isC = {};
        for (var c = 2; pl < 64; c++) {
            if (!isC[c]) {
                for (i = 0; i < 313; i += c) isC[i] = c;
                hash[pl] = (Math.pow(c, .5) * max) | 0;
                k[pl++] = (Math.pow(c, 1 / 3) * max) | 0;
            }
        }
        hash = hash.slice(0, 8);
        ascii += '\x80';
        while (ascii.length % 64 - 56) ascii += '\x00';
        for (i = 0; i < ascii.length; i++) {
            j = ascii.charCodeAt(i);
            if (j >> 8) return null; // ASCII only (badge codes are)
            words[i >> 2] |= j << ((3 - i) % 4) * 8;
        }
        words[words.length] = ((bitLen / max) | 0);
        words[words.length] = (bitLen);
        for (j = 0; j < words.length;) {
            var w = words.slice(j, j += 16), old = hash;
            hash = hash.slice(0, 8);
            for (i = 0; i < 64; i++) {
                var w15 = w[i - 15], w2 = w[i - 2], a = hash[0], e = hash[4];
                var t1 = hash[7] + (rr(e, 6) ^ rr(e, 11) ^ rr(e, 25)) + ((e & hash[5]) ^ ((~e) & hash[6])) + k[i]
                    + (w[i] = (i < 16) ? w[i] : (w[i - 16] + (rr(w15, 7) ^ rr(w15, 18) ^ (w15 >>> 3)) + w[i - 7] + (rr(w2, 17) ^ rr(w2, 19) ^ (w2 >>> 10))) | 0);
                var t2 = (rr(a, 2) ^ rr(a, 13) ^ rr(a, 22)) + ((a & hash[1]) ^ (a & hash[2]) ^ (hash[1] & hash[2]));
                hash = [(t1 + t2) | 0].concat(hash);
                hash[4] = (hash[4] + t1) | 0;
            }
            for (i = 0; i < 8; i++) hash[i] = (hash[i] + old[i]) | 0;
        }
        for (i = 0; i < 8; i++) for (j = 3; j + 1; j--) { var b = (hash[i] >> (j * 8)) & 255; result += ((b < 16) ? 0 : '') + b.toString(16); }
        return result;
    }

    function uuid() {
        var b = crypto.getRandomValues(new Uint8Array(16));
        b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
        var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }

    // ── Device storage ──
    var Store = (function () {
        var db = null, LS = 'smarthotel-kiosk:', useLS = false;
        function lsGet(k) { try { var v = localStorage.getItem(LS + k); return v ? JSON.parse(v) : undefined; } catch (e) { return undefined; } }
        function lsSet(k, v) { localStorage.setItem(LS + k, JSON.stringify(v)); }
        function run(store, mode, work) {
            return new Promise(function (res, rej) {
                var tx = db.transaction(store, mode), st = tx.objectStore(store), out;
                work(st, function (v) { out = v; });
                tx.oncomplete = function () { res(out); }; tx.onerror = function () { rej(tx.error); }; tx.onabort = function () { rej(tx.error); };
            });
        }
        return {
            open: function () {
                return new Promise(function (res) {
                    if (!window.indexedDB) { useLS = true; return res(); }
                    var r; try { r = indexedDB.open('smart-hotel-kiosk', 1); } catch (e) { useLS = true; return res(); }
                    r.onupgradeneeded = function () { var d = r.result; d.createObjectStore('kv'); d.createObjectStore('punches', { keyPath: 'uuid' }); };
                    r.onsuccess = function () { db = r.result; res(); };
                    r.onerror = r.onblocked = function () { useLS = true; res(); };
                });
            },
            get: function (k) { return useLS ? Promise.resolve(lsGet('kv:' + k)) : run('kv', 'readonly', function (st, set) { var q = st.get(k); q.onsuccess = function () { set(q.result); }; }); },
            set: function (k, v) { if (useLS) { lsSet('kv:' + k, v); return Promise.resolve(); } return run('kv', 'readwrite', function (st) { st.put(v, k); }); },
            put: function (p) { if (useLS) { var a = lsGet('punches') || {}; a[p.uuid] = p; lsSet('punches', a); return Promise.resolve(); } return run('punches', 'readwrite', function (st) { st.put(p); }); },
            del: function (id) { if (useLS) { var a = lsGet('punches') || {}; delete a[id]; lsSet('punches', a); return Promise.resolve(); } return run('punches', 'readwrite', function (st) { st.delete(id); }); },
            all: function () { if (useLS) { var a = lsGet('punches') || {}; return Promise.resolve(Object.keys(a).map(function (k) { return a[k]; })); } return run('punches', 'readonly', function (st, set) { var q = st.getAll(); q.onsuccess = function () { set(q.result || []); }; }); },
        };
    })();

    // ── State ──
    var S = { boot: BOOT, csrf: document.querySelector('meta[name=csrf-token]').content, device: null, pointId: null, staff: {}, inState: {}, lastScan: {},
              online: null, auth: true, other: false, syncing: false, pending: 0, rejected: 0, shown: null, facing: 'user', scanner: null, camOk: false };

    function indexStaff() {
        S.staff = {};
        S.boot.staff.forEach(function (s) { S.staff[s.h] = s; if (!(s.id in S.inState)) S.inState[s.id] = s.in; });
    }
    function point() { return S.boot.points.find(function (p) { return p.id === S.pointId; }) || null; }

    // ── Clock ──
    function tick() {
        var n = new Date();
        $('clock').textContent = n.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        $('date').textContent = n.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
    }

    // ── Showing a scan ──
    var resetTimer = null;
    function show(kind, html, keepMs) {
        var r = $('result');
        r.className = 'k-result ' + kind;
        r.innerHTML = html;
        clearTimeout(resetTimer);
        resetTimer = setTimeout(idle, keepMs || 5000);
    }
    function idle() {
        S.shown = null;
        $('result').className = 'k-result idle';
        $('result').innerHTML = '<div class="k-res-icon"><i class="ri-user-follow-line"></i></div><h2>Welcome</h2><p>Scan your badge to clock in or out.</p>';
    }
    function initials(n) { return n.split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w[0].toUpperCase(); }).join(''); }

    function beep(ok) {
        try {
            var a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain();
            o.frequency.value = ok ? 880 : 220; o.connect(g); g.connect(a.destination); g.gain.value = .08;
            o.start(); setTimeout(function () { o.stop(); a.close(); }, ok ? 140 : 380);
        } catch (e) { /* no sound */ }
        if (navigator.vibrate) navigator.vibrate(ok ? 60 : [80, 60, 80]);
    }

    // ── Handling a scan ──
    var lastText = '', lastTextAt = 0;
    async function handle(text) {
        text = (text || '').trim();
        if (!text) return;
        var now = Date.now();
        if (text === lastText && now - lastTextAt < 4000) return; // camera sees the same card many times a second
        lastText = text; lastTextAt = now;

        if (text.indexOf(PREFIX) !== 0) {
            beep(false);
            return show('bad', '<div class="k-res-icon"><i class="ri-error-warning-line"></i></div><h2>Not a staff badge</h2><p>Please scan your staff ID card.</p>', 3500);
        }
        if (!point()) { return menu(); }
        var token = text.slice(PREFIX.length), person = S.staff[sha256(token)] || null;

        if (person) {
            var last = S.lastScan[person.id] || (person.last ? Date.parse(person.last) : 0);
            if (now - last < (S.boot.repeat_seconds || 120) * 1000) {
                beep(true);
                return show('repeat', '<div class="k-res-icon">' + esc(initials(person.name)) + '</div><h2>' + esc(person.name.split(' ')[0]) + ', you’re already done</h2><p>Your scan at <b>' + hhmm(last) + '</b> was recorded.</p>', 3500);
            }
        } else if (S.online) {
            // Not in this kiosk's list: maybe a brand-new badge. Let the office decide.
        } else {
            // Offline and unknown: still keep it — the office will check it.
        }

        var dir = person ? (S.inState[person.id] ? 'out' : 'in') : null;
        var p = point(), photo = (p && p.take_photo) ? snap() : null;
        var rec = { uuid: uuid(), token: text, punched_at: new Date().toISOString(), point_id: p.id, device_id: S.device.tag, was_offline: S.online !== true, photo: photo,
                    _state: 'pending', _created: now, _name: person ? person.name : null, _dir: dir, _uid: person ? person.id : null };
        try {
            await Store.put(rec); // safe on the device before we say "done"
        } catch (e) {
            beep(false);
            return show('bad', '<div class="k-res-icon"><i class="ri-error-warning-line"></i></div><h2>Couldn’t save</h2><p>' + esc(e.message || e) + ' — please scan again.</p>');
        }
        if (person) { S.inState[person.id] = dir === 'in'; S.lastScan[person.id] = now; }
        S.shown = rec.uuid;
        beep(true);

        if (person) {
            show(dir, '<div class="k-res-icon">' + esc(initials(person.name)) + '</div>' +
                '<h2>' + (dir === 'in' ? 'Welcome, ' : 'Goodbye, ') + esc(person.name.split(' ')[0]) + '</h2>' +
                '<div class="big">' + hhmm(now) + '</div><p>Clocked ' + dir.toUpperCase() + (person.dept ? ' · ' + esc(person.dept) : '') + '</p>' +
                '<div id="resTag">' + (S.online ? '<span class="tag">Sending…</span>' : '<span class="tag"><i class="ri-wifi-off-line"></i> Saved on this kiosk — sent when the network is back</span>') + '</div>');
        } else {
            show('repeat', '<div class="k-res-icon"><i class="ri-question-line"></i></div><h2>Badge saved</h2><p>This card isn’t on this kiosk’s list yet. The office will check it.</p><div id="resTag"></div>', 4500);
        }
        renderRecent();
        await refreshCounts();
        sync();
    }

    function snap() {
        try {
            var v = document.querySelector('#reader video');
            if (!v || !v.videoWidth) return null;
            var c = $('snap'), ctx = c.getContext('2d'), w = 320, h = Math.round(320 * v.videoHeight / v.videoWidth);
            c.width = w; c.height = h;
            ctx.drawImage(v, 0, 0, w, h);
            return c.toDataURL('image/jpeg', 0.6);
        } catch (e) { return null; }
    }

    // ── Recent list ──
    async function renderRecent() {
        var all = (await Store.all()).sort(function (a, b) { return b._created - a._created; }).slice(0, 10);
        $('recent').innerHTML = all.length ? all.map(function (p) {
            var dir = (p._server && p._server.direction) || p._dir;
            var st = p._state === 'sent' ? (p._server && p._server.result === 'repeat' ? 'repeat' : '✓') : (p._state === 'rejected' ? '✗ ' + esc(p._error || '') : '⏳');
            return '<li><span class="t">' + hhmm(p._created) + '</span><span class="n">' + esc((p._server && p._server.name) || p._name || 'Unknown badge') + '</span>' +
                (dir ? '<span class="d ' + dir + '">' + dir.toUpperCase() + '</span>' : '') + '<span class="s">' + st + '</span></li>';
        }).join('') : '<li class="s">No scans yet.</li>';
    }

    // ── Network ──
    function request(url, opts, timeout) {
        opts = opts || {};
        var ctl = new AbortController(), t = setTimeout(function () { ctl.abort(); }, timeout || 8000);
        var headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, opts.headers || {});
        return fetch(url, Object.assign({}, opts, { headers: headers, credentials: 'same-origin', cache: 'no-store', signal: ctl.signal })).finally(function () { clearTimeout(t); });
    }
    function post(body) {
        return request(URLS.sync, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': S.csrf }, body: JSON.stringify(body) }, 40000);
    }
    async function refreshCsrf() {
        var r = await request(URLS.csrf, {}, 8000);
        if (r.status === 401) { setAuth(false); throw new Error('signed out'); }
        S.csrf = (await r.json()).csrf_token;
    }
    function setOnline(on) { var was = S.online; S.online = on; renderNet(); if (on && was !== true) { sync(); refreshData(); } }
    function setAuth(ok) { var was = S.auth; S.auth = ok; renderNet(); if (ok && !was) { refreshCsrf().then(sync).catch(function () {}); refreshData(); } }

    var pingTimer = null, fails = 0;
    async function ping() {
        clearTimeout(pingTimer);
        try {
            var r = await request(URLS.ping, {}, 6000);
            if (r.status === 401 || r.status === 419) { setOnline(true); setAuth(false); }
            else if (r.ok) { var j = await r.json(); S.other = j.user !== ME.id; setOnline(true); setAuth(true); }
            else if (r.status === 403) { setOnline(true); S.other = true; renderNet(); }
            else throw new Error(r.status);
            fails = 0;
        } catch (e) { fails++; setOnline(false); }
        pingTimer = setTimeout(ping, S.online ? (S.pending ? 5000 : 20000) : Math.min(3000 * Math.pow(1.5, fails), 20000));
    }

    async function refreshCounts() {
        var all = await Store.all();
        S.pending = all.filter(function (p) { return p._state === 'pending'; }).length;
        S.rejected = all.filter(function (p) { return p._state === 'rejected'; }).length;
        renderNet();
    }

    async function sync() {
        if (S.syncing || S.online !== true || !S.auth || S.other) return;
        var waiting = (await Store.all()).filter(function (p) { return p._state === 'pending'; }).sort(function (a, b) { return a._created - b._created; });
        if (!waiting.length) return;
        S.syncing = true; renderNet();
        var batch = waiting.slice(0, 20), payload = batch.map(function (p) {
            var o = {}; Object.keys(p).forEach(function (k) { if (k[0] !== '_') o[k] = p[k]; }); return o;
        });
        try {
            var r = await post({ punches: payload });
            if (r.status === 419) { await refreshCsrf(); r = await post({ punches: payload }); }
            if (r.status === 401) { setAuth(false); return; }
            if (r.status === 403) { S.other = true; renderNet(); return; }
            if (!r.ok) throw new Error('Server error ' + r.status);
            var j = await r.json();
            for (var i = 0; i < j.results.length; i++) {
                var res = j.results[i], p = batch.find(function (x) { return x.uuid === res.uuid; });
                if (!p) continue;
                if (res.result === 'rejected') { p._state = 'rejected'; p._error = res.message; }
                else { p._state = 'sent'; p._sent = Date.now(); p._server = res; p.photo = null; /* the office has it now */ }
                await Store.put(p);
                if (S.shown === p.uuid) updateShown(p);
                if (p._uid && res.direction) S.inState[p._uid] = res.direction === 'in';
            }
        } catch (e) {
            setOnline(false); // still pending; the server ignores repeats of the same scan
        } finally {
            S.syncing = false;
            await refreshCounts();
            renderRecent();
            if (S.pending && S.online) setTimeout(sync, 400);
        }
    }

    function updateShown(p) {
        var tag = $('resTag'); if (!tag) return;
        if (p._state === 'rejected') { tag.innerHTML = '<span class="tag" style="background:#b91c1c">' + esc(p._error || 'Not accepted') + '</span>'; return; }
        var s = p._server || {};
        if (s.result === 'repeat') tag.innerHTML = '<span class="tag">Already recorded</span>';
        else if (s.direction === 'in' && s.late > 0) tag.innerHTML = '<span class="tag late">' + s.late + ' min late</span>';
        else if (s.direction === 'in') tag.innerHTML = '<span class="tag">On time ✓</span>';
        else tag.innerHTML = '<span class="tag">Recorded ✓</span>';
    }

    async function refreshData() {
        if (S.online !== true || !S.auth) return;
        try {
            var r = await request(URLS.data, {}, 15000);
            if (!r.ok) return;
            var j = await r.json();
            if (j.csrf) S.csrf = j.csrf;
            delete j.csrf;
            S.boot = j;
            // Trust the office for who is in, except people scanned here since.
            var recentUids = {};
            (await Store.all()).forEach(function (p) { if (p._state === 'pending' && p._uid) recentUids[p._uid] = true; });
            j.staff.forEach(function (s) { if (!recentUids[s.id]) S.inState[s.id] = s.in; });
            indexStaff();
            await Store.set('boot', j);
            renderPoint();
        } catch (e) { /* keep the copy we have */ }
    }

    function renderNet() {
        var n = $('net'), t = $('netText'), b = $('banner'), q = $('queue');
        n.className = 'k-net';
        if (S.online === null) t.textContent = 'Checking…';
        else if (!S.online) { n.classList.add('offline'); t.textContent = 'Offline'; }
        else if (!S.auth) { n.classList.add('signedout'); t.textContent = 'Signed out'; }
        else if (S.syncing) { n.classList.add('syncing'); t.textContent = 'Sending…'; }
        else { n.classList.add('online'); t.textContent = 'Online'; }
        q.hidden = !(S.pending || S.rejected);
        q.textContent = S.rejected ? S.rejected + ' not accepted' : S.pending + ' waiting';

        if (S.online === false) { b.hidden = false; b.className = 'k-banner'; b.innerHTML = '<i class="ri-wifi-off-line"></i> No connection — keep scanning. Clock-ins are saved on this kiosk and sent automatically. Please don’t close this page.'; }
        else if (S.online && !S.auth) { b.hidden = false; b.className = 'k-banner warn'; b.innerHTML = '<i class="ri-lock-line"></i> The kiosk was signed out. Scans are safe here' + (S.pending ? ' (' + S.pending + ' waiting)' : '') + '. <a href="' + esc(URLS.login) + '" target="_blank" rel="noopener">Sign in again</a>'; }
        else if (S.other) { b.hidden = false; b.className = 'k-banner warn'; b.innerHTML = 'Someone else signed in on this browser. <a href="#" onclick="location.reload();return false">Reload the kiosk</a>'; }
        else b.hidden = true;
    }

    function renderPoint() {
        var p = point();
        $('pointName').textContent = p ? p.name + (p.location ? ' · ' + p.location : '') : 'Choose this kiosk’s location (⚙)';
    }

    // ── Camera ──
    async function startCamera() {
        if (!window.Html5Qrcode) return camMessage('Scanner unavailable', 'Use a USB or Bluetooth QR scanner — just scan the badge.');
        if (!window.isSecureContext || !navigator.mediaDevices) {
            return camMessage('Camera needs a secure address', 'Browsers only allow the camera on https:// or on this computer (localhost). A USB / Bluetooth QR scanner works right now — just scan the badge.');
        }
        try {
            if (S.scanner) { try { await S.scanner.stop(); } catch (e) {} }
            S.scanner = new Html5Qrcode('reader', { verbose: false });
            await S.scanner.start({ facingMode: S.facing }, { fps: 10, qrbox: function (w, h) { var m = Math.floor(Math.min(w, h) * 0.6); return { width: m, height: m }; } },
                function (text) { handle(text); }, function () {});
            S.camOk = true; $('camMsg').hidden = true;
        } catch (e) {
            camMessage('Camera not available', (e && e.message ? e.message : String(e)) + '. A USB / Bluetooth QR scanner still works.');
        }
    }
    function camMessage(title, text) {
        S.camOk = false;
        $('camMsg').hidden = false;
        $('camMsg').innerHTML = '<i class="ri-qr-scan-2-line"></i><strong>' + esc(title) + '</strong><span>' + esc(text) + '</span>';
    }

    // Handheld scanners type into the (invisible) box and press Enter.
    function keepFocus() { if ($('modal').hidden && document.activeElement !== $('wedge')) $('wedge').focus({ preventScroll: true }); }

    // ── Settings dialog ──
    async function menu() {
        var all = await Store.all(), waiting = all.filter(function (p) { return p._state !== 'sent'; });
        $('mTitle').textContent = 'Kiosk settings';
        $('mBody').innerHTML =
            '<label for="mPoint">This kiosk is at</label><select id="mPoint">' + (point() ? '' : '<option value="">Choose…</option>') +
            S.boot.points.map(function (p) { return '<option value="' + p.id + '"' + (p.id === S.pointId ? ' selected' : '') + '>' + esc(p.name) + (p.location ? ' — ' + esc(p.location) : '') + '</option>'; }).join('') + '</select>' +
            (S.boot.points.length ? '' : '<p style="color:#b91c1c">No kiosks are set up. Add one under Attendance → Staff, shifts &amp; kiosks → Kiosks.</p>') +
            '<label>Camera</label><button type="button" class="btn" id="mFlip">Switch front / back camera</button> <button type="button" class="btn" id="mFull">Full screen</button>' +
            '<label>Waiting to send</label>' + (waiting.length ? waiting.map(function (p) { return '<div class="row"><span>' + hhmm(p._created) + ' · ' + esc(p._name || 'Unknown badge') + '</span><span>' + (p._state === 'rejected' ? esc(p._error) : 'waiting') + '</span></div>'; }).join('') : '<div class="row"><span>Nothing — all sent</span></div>') +
            '<div class="row"><span>Kiosk id</span><span>' + esc(S.device.tag) + '</span></div><div class="row"><span>Signed in as</span><span>' + esc(ME.name) + '</span></div>' +
            '<div class="row"><span>Staff list updated</span><span>' + new Date(S.boot.generated_at).toLocaleString() + '</span></div>' +
            '<button type="button" class="btn primary" id="mSend"' + (S.online ? '' : ' disabled') + '>Send now</button> <a class="btn" href="' + esc(URLS.board) + '">Office view</a>';
        $('modal').hidden = false;
        $('mPoint').addEventListener('change', function () { S.pointId = parseInt(this.value, 10) || null; Store.set('point', S.pointId); renderPoint(); });
        $('mFlip').addEventListener('click', function () { S.facing = S.facing === 'user' ? 'environment' : 'user'; Store.set('facing', S.facing); startCamera(); });
        $('mFull').addEventListener('click', function () { (document.documentElement.requestFullscreen || function () {}).call(document.documentElement); });
        $('mSend').addEventListener('click', function () { $('modal').hidden = true; sync(); });
    }

    // ── Start ──
    async function start() {
        await Store.open();
        if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function () {});
        var dev = await Store.get('device');
        if (!dev) {
            var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', b = crypto.getRandomValues(new Uint8Array(4));
            dev = { tag: Array.prototype.map.call(b, function (x) { return chars[x % chars.length]; }).join('') };
            await Store.set('device', dev);
        }
        S.device = dev;
        var saved = await Store.get('boot');
        if (saved && saved.generated_at > BOOT.generated_at) S.boot = saved; else await Store.set('boot', BOOT);
        indexStaff();
        // Who this kiosk saw since the list was made.
        (await Store.all()).forEach(function (p) { if (p._uid && p._created > Date.parse(S.boot.generated_at)) { S.inState[p._uid] = (p._server && p._server.direction || p._dir) === 'in'; S.lastScan[p._uid] = Math.max(S.lastScan[p._uid] || 0, p._created); } });
        // Forget sent scans after three days.
        var cutoff = Date.now() - 3 * 864e5;
        (await Store.all()).forEach(function (p) { if (p._state === 'sent' && p._created < cutoff) Store.del(p.uuid); });

        var pid = await Store.get('point');
        if (!S.boot.points.some(function (p) { return p.id === pid; })) pid = S.boot.points.length === 1 ? S.boot.points[0].id : null;
        S.pointId = pid;
        S.facing = (await Store.get('facing')) || 'user';

        tick(); setInterval(tick, 1000);
        renderPoint(); renderRecent(); await refreshCounts();
        $('wedge').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var v = this.value; this.value = ''; handle(v); } });
        setInterval(keepFocus, 1500); keepFocus();
        $('menuBtn').addEventListener('click', menu);
        $('queue').addEventListener('click', menu);
        $('modal').addEventListener('click', function (e) { if (e.target === this || e.target.closest('[data-close]')) { this.hidden = true; keepFocus(); } });
        window.addEventListener('online', function () { fails = 0; ping(); });
        window.addEventListener('offline', function () { setOnline(false); });
        window.addEventListener('beforeunload', function (e) { if (S.pending && S.online === false) { e.preventDefault(); e.returnValue = ''; } });
        if (navigator.wakeLock) navigator.wakeLock.request('screen').catch(function () {});
        if (!S.pointId) menu();
        // An offline copy of this page so the kiosk can be reopened without a network (https or localhost only).
        if ('serviceWorker' in navigator && window.isSecureContext) {
            navigator.serviceWorker.register(URLS.sw, { scope: URLS.kiosk }).then(function () { return navigator.serviceWorker.ready; }).then(function (reg) {
                var urls = performance.getEntriesByType('resource').map(function (r) { return r.name; })
                    .filter(function (u) { return u.indexOf(location.origin) === 0 && /\.(css|js|woff2?|ttf|eot|svg|png|ico)(\?|$)/i.test(u); });
                if (reg.active) reg.active.postMessage({ type: 'precache', urls: urls });
            }).catch(function () {});
        }
        startCamera();
        ping();
        setInterval(refreshData, 5 * 60 * 1000);
    }

    start().catch(function (e) { document.body.insertAdjacentHTML('afterbegin', '<div style="background:#b91c1c;color:#fff;padding:12px;font-weight:700">The kiosk could not start: ' + esc(e && e.message) + '</div>'); });

    // For testing in a browser console: KioskScan('SHSTAFF:…')
    window.KioskScan = handle;
})();
