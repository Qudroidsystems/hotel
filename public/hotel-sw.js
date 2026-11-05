/*
 * Smart Hotel — the website with no connection.
 *
 * Pages: network first, so staff always see live data when the hotel system
 * answers; the last copy of each page opened is kept so it can still be read
 * with no connection (a red bar says so). Styles, scripts, fonts and icons:
 * saved copy first. Forms are never touched here: the page's own script keeps
 * the safe ones and sends them later (js/offline-queue.js).
 * The copies are wiped when someone signs out.
 */
const CACHE = 'smart-hotel-web-v1';
const SCOPE = new URL(self.registration.scope);
const BASE = SCOPE.pathname.replace(/\/$/, '');
const MAX_PAGES = 80;

const OFFLINE_HTML = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title>
<style>body{font-family:system-ui,sans-serif;background:#f8fafc;color:#0f172a;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}
.b{max-width:380px}h1{font-size:20px}p{color:#475569;line-height:1.5}button{margin-top:12px;background:#0f766e;color:#fff;border:0;border-radius:10px;padding:10px 18px;font-size:15px}</style></head>
<body><div class="b"><div style="font-size:42px">📶</div><h1>No connection to the hotel system</h1><p>This page hasn't been opened on this computer yet, so there's no saved copy. Pages you have opened before still work, and saved actions are sent when the connection is back.</p><button onclick="location.reload()">Try again</button></div></body></html>`;

self.addEventListener('install', (e) => { self.skipWaiting(); });
self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('smart-hotel-web-') && k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('message', (e) => { if (e.data === 'forget') e.waitUntil(caches.delete(CACHE)); });

const inApp = (url) => { const p = url.pathname.replace(/\/$/, ''); return p === BASE || p.startsWith(BASE + '/'); };
const isStatic = (url) => /\.(css|js|woff2?|ttf|eot|svg|png|jpe?g|gif|webp|ico)$/i.test(url.pathname);
// Never kept: sign-in pages, files to download, the guest-facing pages and live feeds.
const skip = (url) => /\/(login|logout|two-factor|password|stay|book|api|broadcasting|ping|storage)(\/|$)|\.(pdf|csv|xlsx)$/i.test(url.pathname);

async function trim(cache) {
    const keys = await cache.keys();
    const pages = keys.filter((k) => !isStatic(new URL(k.url)));
    for (const k of pages.slice(0, Math.max(0, pages.length - MAX_PAGES))) await cache.delete(k);
}

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin || !inApp(url) || skip(url)) return;

    if (req.mode === 'navigate') {
        e.respondWith((async () => {
            const key = url.origin + url.pathname + url.search;
            try {
                const res = await fetch(req);
                if (res.ok && !res.redirected && (res.headers.get('content-type') || '').includes('text/html')) {
                    const copy = res.clone();
                    caches.open(CACHE).then(async (c) => { await c.put(key, copy); trim(c); });
                }
                return res;
            } catch (err) {
                const hit = (await caches.match(key)) || (await caches.match(url.origin + url.pathname));
                return hit || new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
            }
        })());
        return;
    }
    if (isStatic(url)) {
        e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
            if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); }
            return res;
        })));
    }
});
