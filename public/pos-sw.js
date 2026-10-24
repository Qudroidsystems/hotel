/*
 * Smart Hotel — offline copy of the bar & restaurant till and the staff
 * clock-in kiosk (registered once for each, with the page as its scope).
 *
 * Lets a till be reopened (or refreshed) while the network is down. Browsers
 * only allow this on https:// or http://localhost; on a plain http:// LAN
 * address the till still works offline as long as the page stays open.
 *
 * - The till page: network first (always fresh when online), saved copy when offline.
 * - Styles, scripts, fonts, images: saved copy first, refreshed in the background.
 * - Data calls (/pos/sync, /pos/ping, /pos/data …): never cached — the page handles them.
 */
const CACHE = 'smart-hotel-pos-v1';
const SCOPE = new URL(self.registration.scope);
const TILL = SCOPE.pathname.replace(/\/$/, '');          // e.g. /hotel/public/pos

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.add(new Request(SCOPE.href, { credentials: 'same-origin' })).catch(() => {})).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('smart-hotel-pos-') && k !== CACHE).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

// The till page tells us which files it loaded (styles, script, icon fonts) so they
// can be saved too — it loaded them before this worker was running.
self.addEventListener('message', (e) => {
    if (!e.data || e.data.type !== 'precache' || !Array.isArray(e.data.urls)) return;
    e.waitUntil(caches.open(CACHE).then((c) => Promise.all(e.data.urls
        .filter((u) => { try { return new URL(u).origin === location.origin; } catch (err) { return false; } })
        .map((u) => c.match(u).then((hit) => hit || c.add(new Request(u, { credentials: 'same-origin' }))).catch(() => {})))));
});

function isTillPage(url) {
    return url.pathname.replace(/\/$/, '') === TILL;
}

function isStatic(url) {
    return /\.(css|js|woff2?|ttf|eot|svg|png|jpe?g|gif|webp|ico)$/i.test(url.pathname);
}

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;                     // sales go straight to the network
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;

    if (req.mode === 'navigate' && isTillPage(url)) {
        e.respondWith((async () => {
            try {
                const res = await fetch(req);
                // Only keep a real till page (not a login redirect or an error).
                if (res.ok && !res.redirected && isTillPage(new URL(res.url))) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(SCOPE.href, copy));
                }
                return res;
            } catch (err) {
                const cached = await caches.match(SCOPE.href);
                return cached || new Response('<h2 style="font-family:sans-serif;padding:20px">Offline — the till has not been opened on this device while online yet.</h2>', { headers: { 'Content-Type': 'text/html' } });
            }
        })());
        return;
    }

    if (isStatic(url)) {
        e.respondWith((async () => {
            const cache = await caches.open(CACHE);
            const cached = await cache.match(req);
            const fresh = fetch(req).then((res) => { if (res.ok) cache.put(req, res.clone()); return res; }).catch(() => null);
            return cached || (await fresh) || new Response('', { status: 504 });
        })());
    }
    // Everything else (JSON calls, other pages): normal network behaviour.
});
