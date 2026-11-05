/*
 * Smart Hotel — work that carries on with no connection (website).
 *
 * When the hotel system can't be reached, forms on the list the server gives
 * (window.HOTEL_OFFLINE.patterns: housekeeping, repairs, tasks, guest requests …)
 * are kept in this browser instead of being lost, and sent by themselves when
 * the connection is back. Each carries its own id (X-Offline-Id), so the hotel
 * system does it once even if it is sent twice, and the time it was pressed
 * (X-Offline-At). Money, check-in/out and settings are never kept: they wait.
 */
(function () {
    'use strict';
    var cfg = window.HOTEL_OFFLINE || {};
    var patterns = (cfg.patterns || []).map(function (p) { try { return new RegExp(p); } catch (e) { return null; } }).filter(Boolean);
    var DB = 'hotel-offline', STORE = 'outbox', dbp = null, flushing = false;

    function db() {
        if (dbp) return dbp;
        dbp = new Promise(function (resolve, reject) {
            if (!window.indexedDB) return reject(new Error('no indexedDB'));
            var r = indexedDB.open(DB, 1);
            r.onupgradeneeded = function () { r.result.createObjectStore(STORE, { keyPath: 'id' }); };
            r.onsuccess = function () { resolve(r.result); };
            r.onerror = function () { reject(r.error); };
        });
        return dbp;
    }
    function tx(mode, fn) {
        return db().then(function (d) {
            return new Promise(function (resolve, reject) {
                var t = d.transaction(STORE, mode), s = t.objectStore(STORE), out = fn(s);
                t.oncomplete = function () { resolve(out && out.result !== undefined ? out.result : out); };
                t.onerror = function () { reject(t.error); };
            });
        });
    }
    function all() { return tx('readonly', function (s) { return s.getAll(); }).then(function (rows) { return (rows || []).filter(function (r) { return r.user === cfg.user; }); }); }
    function put(row) { return tx('readwrite', function (s) { return s.put(row); }); }
    function del(id) { return tx('readwrite', function (s) { return s.delete(id); }); }

    function newId() {
        var a = new Uint8Array(12);
        (window.crypto || window.msCrypto).getRandomValues(a);
        return 'w' + Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    function canQueue(form) {
        if (!patterns.length || form.hasAttribute('data-no-offline')) return false;
        var url;
        try { url = new URL(form.getAttribute('action') || location.href, location.href); } catch (e) { return false; }
        if (url.origin !== location.origin) return false;
        return patterns.some(function (re) { return re.test(url.pathname); });
    }

    function label(form, submitter) {
        var t = form.getAttribute('data-offline-label') || (submitter && (submitter.innerText || submitter.value)) || '';
        var where = form.closest('[data-offline-title], tr, article, .card, .cb-card');
        var ctx = where ? (where.getAttribute('data-offline-title') || (where.querySelector('strong, .fw-semibold, h5, h6') || {}).innerText || '') : '';
        return ((t || 'Saved action').trim() + (ctx ? ' — ' + ctx.trim() : '')).replace(/\s+/g, ' ').slice(0, 140);
    }

    function queue(form, submitter) {
        var fd = new FormData(form);
        if (submitter && submitter.name) fd.append(submitter.name, submitter.value);
        var fields = [];
        fd.forEach(function (v, k) { if (k !== '_token') fields.push([k, v]); });
        var row = {
            id: newId(), user: cfg.user, url: new URL(form.getAttribute('action') || location.href, location.href).href,
            fields: fields, at: new Date().toISOString(), label: label(form, submitter), page: location.pathname, state: 'waiting', message: null,
        };
        return put(row).then(function () {
            mark(form, 'Saved on this computer — it will be sent when the connection is back.');
            toast('info', 'Saved on this computer', 'It will be sent by itself when the connection is back.');
            render();
        }).catch(function () {
            toast('error', "Couldn't keep it", "This browser can't store it. Try again when the connection is back.");
        });
    }

    function mark(form, text) {
        form.querySelectorAll('button, input[type=submit]').forEach(function (b) { b.disabled = true; });
        var n = document.createElement('div');
        n.className = 'small mt-1'; n.style.color = '#b45309'; n.innerHTML = '<i class="ri-time-line"></i> ' + text;
        form.appendChild(n);
    }

    function toast(icon, title, text) {
        if (window.Swal) Swal.fire({ toast: true, position: 'top', icon: icon, title: title, text: text, timer: 3500, showConfirmButton: false });
    }

    function token() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    /** Send everything waiting, oldest first. Stops at the first sign of no connection. */
    function flush() {
        if (flushing || navigator.onLine === false) return Promise.resolve();
        flushing = true;
        return all().then(function (rows) {
            rows = rows.filter(function (r) { return r.state === 'waiting'; }).sort(function (a, b) { return a.at < b.at ? -1 : 1; });
            var sent = 0, failed = 0;
            var step = function (i) {
                if (i >= rows.length) return Promise.resolve();
                var r = rows[i], fd = new FormData();
                r.fields.forEach(function (f) { fd.append(f[0], f[1]); });
                fd.append('_token', token());
                return fetch(r.url, {
                    method: 'POST', body: fd, credentials: 'same-origin',
                    headers: { 'X-Offline-Id': r.id, 'X-Offline-At': r.at, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token() },
                }).then(function (res) {
                    if (res.status === 419 || res.status === 401) { throw { stop: true, message: 'Sign in again to send what was saved.' }; }
                    if (res.status >= 500 || res.status === 409 || res.status === 429) { throw { stop: true }; }
                    return res.json().catch(function () { return {}; }).then(function (j) {
                        var ok = res.ok && j.ok !== false;
                        if (ok && j.success !== false) { sent++; return del(r.id); }
                        failed++;
                        r.state = 'failed';
                        r.message = j.message || (j.errors && Object.values(j.errors)[0] && Object.values(j.errors)[0][0]) || ('Not saved (' + res.status + ')');
                        return put(r);
                    });
                }).then(function () { return step(i + 1); });
            };
            return step(0).then(function () {
                if (sent) toast('success', sent + ' saved action' + (sent > 1 ? 's' : '') + ' sent', failed ? failed + " didn't go through — see the list." : '');
                else if (failed) toast('warning', failed + " saved action" + (failed > 1 ? 's' : '') + " didn't go through", 'See the list at the bottom left.');
            });
        }).catch(function (e) {
            if (e && e.message && e.stop) toast('warning', 'Not sent yet', e.message);
        }).finally(function () { flushing = false; render(); });
    }

    // ── The little "waiting to send" pill and its list ──────────────────
    var pill = null, panel = null;
    function el(tag, css, html) { var n = document.createElement(tag); if (css) n.style.cssText = css; if (html !== undefined) n.innerHTML = html; return n; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function render() {
        all().then(function (rows) {
            var waiting = rows.filter(function (r) { return r.state === 'waiting'; }).length;
            var failed = rows.filter(function (r) { return r.state === 'failed'; }).length;
            if (!pill) {
                pill = el('button', 'position:fixed;left:14px;bottom:14px;z-index:2001;border:0;border-radius:999px;padding:8px 14px;font-size:13px;font-weight:600;color:#fff;box-shadow:0 6px 20px rgba(0,0,0,.25);display:none');
                pill.type = 'button';
                pill.addEventListener('click', togglePanel);
                document.body.appendChild(pill);
            }
            if (!waiting && !failed) { pill.style.display = 'none'; if (panel) panel.remove(), panel = null; return; }
            pill.style.display = 'block';
            pill.style.background = failed ? '#b91c1c' : '#b45309';
            pill.innerHTML = '<i class="ri-' + (failed ? 'error-warning' : 'time') + '-line"></i> ' + (waiting ? waiting + ' waiting to send' : '') + (waiting && failed ? ' · ' : '') + (failed ? failed + " didn't go through" : '');
            if (panel) fill(rows);
        }).catch(function () {});
    }

    function togglePanel() {
        if (panel) { panel.remove(); panel = null; return; }
        panel = el('div', 'position:fixed;left:14px;bottom:58px;z-index:2001;width:min(420px,92vw);max-height:60vh;overflow:auto;background:var(--cb-surface,#fff);color:inherit;border:1px solid var(--cb-border,#e5e7eb);border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,.25);padding:12px');
        document.body.appendChild(panel);
        all().then(fill);
    }

    function fill(rows) {
        if (!panel) return;
        panel.innerHTML = '<div style="font-weight:700;margin-bottom:6px">Saved on this computer</div><div class="small text-muted mb-2">Sent by itself when the connection is back, each only once.</div>';
        rows.sort(function (a, b) { return a.at < b.at ? -1 : 1; }).forEach(function (r) {
            var row = el('div', 'border-top:1px solid var(--cb-border,#eee);padding:8px 0;font-size:13px');
            row.innerHTML = '<div><strong>' + esc(r.label) + '</strong></div><div class="text-muted">' + new Date(r.at).toLocaleString() + '</div>'
                + (r.state === 'failed' ? '<div style="color:#b91c1c">' + esc(r.message) + '</div>' : '<div style="color:#b45309">Waiting to send</div>');
            var bar = el('div', 'display:flex;gap:6px;margin-top:4px');
            if (r.state === 'failed') {
                var again = el('button', '', 'Try again'); again.className = 'btn btn-sm btn-light';
                again.onclick = function () { r.state = 'waiting'; r.message = null; put(r).then(flush); };
                bar.appendChild(again);
            }
            var drop = el('button', '', r.state === 'failed' ? 'Dismiss' : 'Remove'); drop.className = 'btn btn-sm btn-light';
            drop.onclick = function () { if (r.state === 'failed' || confirm('Remove this saved action? It will not be sent.')) del(r.id).then(render); };
            bar.appendChild(drop);
            row.appendChild(bar);
            panel.appendChild(row);
        });
    }

    window.HotelOffline = { canQueue: canQueue, queue: queue, flush: flush, render: render };
    window.addEventListener('online', function () { setTimeout(flush, 1500); });
    document.addEventListener('DOMContentLoaded', function () { render(); if (navigator.onLine !== false) flush(); });
    setInterval(function () { if (navigator.onLine !== false) flush(); }, 30000);

    // Pages: keep a copy of each one opened, to look at with no connection (cleared on sign-out).
    if ('serviceWorker' in navigator && cfg.sw && (location.protocol === 'https:' || location.hostname === 'localhost')) {
        navigator.serviceWorker.register(cfg.sw, { scope: cfg.scope }).catch(function () {});
        document.addEventListener('submit', function (e) {
            var f = e.target;
            if (f && f.getAttribute && /\/logout$/.test(new URL(f.getAttribute('action') || '', location.href).pathname) && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage('forget');
            }
        }, true);
    }
})();
