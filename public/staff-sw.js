/*
 * Smart Hotel — Staff App offline support.
 *
 * Pages: network first, so staff always see live data when connected; the
 * last copy of each Staff App screen is kept so it can still be opened with
 * no signal (marked as offline). Styles, scripts, fonts and icons: saved copy
 * first. Forms (POST) always go to the network — nothing is queued, so an
 * action never "half happens" while offline.
 * Browsers only run this on https:// or http://localhost.
 */
const CACHE = 'smart-hotel-staff-v1';
const SCOPE = new URL(self.registration.scope);
const BASE = SCOPE.pathname.replace(/\/$/, '');              // e.g. /hotel/public/staff

const OFFLINE_HTML = `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Offline</title><style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}
.b{max-width:340px}h1{font-size:20px;margin:12px 0}p{color:#94a3b8;font-size:15px;line-height:1.5}button{margin-top:16px;background:#0f766e;color:#fff;border:0;border-radius:10px;padding:12px 20px;font-size:15px}</style></head>
<body><div class="b"><div style="font-size:44px">📶</div><h1>You're offline</h1><p>This screen hasn't been opened on this phone yet, so there's no saved copy. Check Wi-Fi or mobile data and try again.</p>
<button onclick="location.reload()">Try again</button></div></body></html>`;

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.add(new Request(SCOPE.href, { credentials: 'same-origin' })).catch(() => {})).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('smart-hotel-staff-') && k !== CACHE).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

function inApp(url) {
    const p = url.pathname.replace(/\/$/, '');
    return p === BASE || p.startsWith(BASE + '/');
}

function isStatic(url) {
    return /\.(css|js|woff2?|ttf|eot|svg|png|jpe?g|gif|webp|ico)$/i.test(url.pathname);
}

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin) return;

    if (req.mode === 'navigate' && inApp(url)) {
        e.respondWith((async () => {
            const key = url.origin + url.pathname + url.search;
            try {
                const res = await fetch(req);
                // Keep real Staff App pages only — never a login page or an error.
                if (res.ok && !res.redirected && inApp(new URL(res.url))) {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(key, copy));
                }
                return res;
            } catch (err) {
                const hit = (await caches.match(key)) || (await caches.match(url.origin + url.pathname)) || (await caches.match(SCOPE.href));
                return hit || new Response(OFFLINE_HTML, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
            }
        })());
        return;
    }

    if (isStatic(url) && !url.pathname.includes('/files/')) {
        e.respondWith(caches.match(req).then((hit) => {
            const net = fetch(req).then((res) => {
                if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); }
                return res;
            }).catch(() => hit);
            return hit || net;
        }));
    }
});
