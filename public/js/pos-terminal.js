/*
 * Smart Hotel — bar & restaurant till.
 *
 * Offline-first: everything the till needs (menu, prices, in-house guests) is
 * kept on the device, every sale is written to the device's database BEFORE it
 * is shown as done, and a background loop sends waiting sales to the server
 * whenever it can be reached. Each sale has a random id (uuid) that the server
 * accepts only once, so re-sending after a timeout can never double-count.
 */
(function () {
    'use strict';

    var URLS = window.POS_URLS;
    var BOOT = window.POS_BOOT;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    // ════════════════════════════════════════════════════════════════
    //  Device storage — IndexedDB, with a localStorage fallback
    // ════════════════════════════════════════════════════════════════

    var Store = (function () {
        var db = null, useLS = false, LS = 'smarthotel-pos:';

        function open() {
            return new Promise(function (resolve) {
                if (!window.indexedDB) { useLS = true; return resolve(); }
                var req;
                try { req = indexedDB.open('smart-hotel-pos', 1); } catch (e) { useLS = true; return resolve(); }
                req.onupgradeneeded = function () {
                    var d = req.result;
                    if (!d.objectStoreNames.contains('kv')) d.createObjectStore('kv');
                    if (!d.objectStoreNames.contains('orders')) d.createObjectStore('orders', { keyPath: 'uuid' });
                };
                req.onsuccess = function () { db = req.result; resolve(); };
                req.onerror = function () { useLS = true; resolve(); };
                req.onblocked = function () { useLS = true; resolve(); };
            });
        }

        function run(store, mode, work) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(store, mode), st = tx.objectStore(store), out;
                work(st, function (v) { out = v; });
                tx.oncomplete = function () { resolve(out); };
                tx.onerror = function () { reject(tx.error); };
                tx.onabort = function () { reject(tx.error || new Error('Storage aborted')); };
            });
        }

        function lsGet(k) { try { var v = localStorage.getItem(LS + k); return v ? JSON.parse(v) : undefined; } catch (e) { return undefined; } }
        function lsSet(k, v) { localStorage.setItem(LS + k, JSON.stringify(v)); } // throws if full — callers must see that

        return {
            open: open,
            get kind() { return useLS ? 'localStorage' : 'IndexedDB'; },
            get: function (key) {
                if (useLS) return Promise.resolve(lsGet('kv:' + key));
                return run('kv', 'readonly', function (st, set) { var r = st.get(key); r.onsuccess = function () { set(r.result); }; });
            },
            set: function (key, value) {
                if (useLS) { lsSet('kv:' + key, value); return Promise.resolve(); }
                return run('kv', 'readwrite', function (st) { st.put(value, key); });
            },
            /** Atomically take the next order sequence number for this device. */
            nextSeq: function () {
                if (useLS) { var d = lsGet('kv:device'); d.seq = (d.seq || 0) + 1; lsSet('kv:device', d); return Promise.resolve(d.seq); }
                return run('kv', 'readwrite', function (st, set) {
                    var r = st.get('device');
                    r.onsuccess = function () { var d = r.result; d.seq = (d.seq || 0) + 1; st.put(d, 'device'); set(d.seq); };
                });
            },
            putOrder: function (o) {
                if (useLS) { var all = lsGet('orders') || {}; all[o.uuid] = o; lsSet('orders', all); return Promise.resolve(); }
                return run('orders', 'readwrite', function (st) { st.put(o); });
            },
            orders: function () {
                if (useLS) { var all = lsGet('orders') || {}; return Promise.resolve(Object.keys(all).map(function (k) { return all[k]; })); }
                return run('orders', 'readonly', function (st, set) { var r = st.getAll(); r.onsuccess = function () { set(r.result || []); }; });
            },
            deleteOrder: function (uuid) {
                if (useLS) { var all = lsGet('orders') || {}; delete all[uuid]; lsSet('orders', all); return Promise.resolve(); }
                return run('orders', 'readwrite', function (st) { st.delete(uuid); });
            },
        };
    })();

    // ════════════════════════════════════════════════════════════════
    //  State
    // ════════════════════════════════════════════════════════════════

    var S = {
        boot: BOOT,
        csrf: document.querySelector('meta[name=csrf-token]').content,
        device: null,
        outletId: null,
        cat: 'all',
        q: '',
        cart: { lines: [], discount: 0, discountReason: '', table: '' },
        held: [],
        online: null,        // can the server be reached?
        auth: true,          // is the session still signed in?
        otherUser: false,    // someone else signed in on this browser
        syncing: false,
        pending: 0,
        rejected: 0,
        lastSync: null,
        swActive: false,
    };

    var sym = function () { return S.boot.hotel.currency || '₦'; };
    // Same rounding as App\Support\Money::round() on the server (half away from zero).
    var round2 = function (n) {
        var cents = Number((Number(n) * 100).toPrecision(15));
        var r = Math.floor(Math.abs(cents) + 0.5) / 100;
        return cents < 0 ? -r : r;
    };
    var money = function (n) { return sym() + Number(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var plain = function (n) { return Number(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var qtyTxt = function (q) { return String(round2(q)).replace(/\.0+$/, ''); };
    var hhmm = function (d) { return new Date(d).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); };
    var outlet = function () { return S.boot.outlets.find(function (o) { return o.id === S.outletId; }) || null; };

    function uuid() {
        if (window.crypto && crypto.randomUUID && window.isSecureContext) return crypto.randomUUID();
        var b = crypto.getRandomValues(new Uint8Array(16));
        b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
        var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }

    // ════════════════════════════════════════════════════════════════
    //  Prices — the same maths as PosService::price() on the server
    // ════════════════════════════════════════════════════════════════

    function computeTax(amount) {
        var inclusive = !!S.boot.tax.inclusive, taxes = S.boot.tax.taxes || [];
        var rateSum = taxes.reduce(function (a, t) { return a + Number(t.rate); }, 0);
        var breakdown = [];
        if (rateSum > 0) {
            var base = inclusive ? amount / (1 + rateSum / 100) : amount;
            taxes.forEach(function (t) { breakdown.push({ name: t.name, rate: Number(t.rate), amount: round2(base * t.rate / 100) }); });
        }
        var tax = round2(breakdown.reduce(function (a, t) { return a + t.amount; }, 0));
        return { tax: inclusive ? 0 : tax, breakdown: breakdown, total: round2(inclusive ? amount : amount + tax) };
    }

    function price(lines, discount) {
        var groups = {};
        lines.forEach(function (l) { groups[l.charge_type] = round2((groups[l.charge_type] || 0) + round2(l.quantity * l.unit_price)); });
        var subtotal = round2(Object.keys(groups).reduce(function (a, k) { return a + groups[k]; }, 0));
        discount = round2(Math.min(Math.max(discount || 0, 0), subtotal));
        var parts = Object.keys(groups).map(function (k) { return computeTax(groups[k]); });
        if (discount > 0) parts.push(computeTax(-discount));
        var bd = {};
        parts.forEach(function (p) { p.breakdown.forEach(function (t) { bd[t.name] = bd[t.name] || { name: t.name, rate: t.rate, amount: 0 }; bd[t.name].amount = round2(bd[t.name].amount + t.amount); }); });
        return {
            subtotal: subtotal, discount: discount,
            tax: round2(parts.reduce(function (a, p) { return a + p.tax; }, 0)),
            breakdown: Object.keys(bd).map(function (k) { return bd[k]; }),
            total: round2(parts.reduce(function (a, p) { return a + p.total; }, 0)),
        };
    }

    // ════════════════════════════════════════════════════════════════
    //  Rendering — menu
    // ════════════════════════════════════════════════════════════════

    function itemsForOutlet() {
        var oid = S.outletId;
        return S.boot.items.filter(function (i) { return i.outlets.indexOf(oid) !== -1; });
    }

    function renderOutlet() {
        var o = outlet();
        $('outletName').textContent = o ? o.name : 'Choose outlet';
        $('outletDot').style.background = o ? o.color : '#94a3b8';
    }

    function renderCats() {
        var items = itemsForOutlet();
        var used = {};
        items.forEach(function (i) { used[i.category_id] = true; });
        var cats = S.boot.categories.filter(function (c) { return used[c.id]; });
        if (S.cat !== 'all' && !used[S.cat]) S.cat = 'all';
        var html = '<button type="button" data-cat="all" class="' + (S.cat === 'all' ? 'on' : '') + '">All</button>';
        cats.forEach(function (c) {
            html += '<button type="button" data-cat="' + c.id + '" class="' + (S.cat === c.id ? 'on' : '') + '"><span class="sw" style="background:' + esc(c.color) + '"></span>' + esc(c.name) + '</button>';
        });
        $('cats').innerHTML = html;
    }

    function renderGrid() {
        var q = S.q.trim().toLowerCase();
        var catColor = {};
        S.boot.categories.forEach(function (c) { catColor[c.id] = c.color; });
        var inCart = {};
        S.cart.lines.forEach(function (l) { if (l.item_id) inCart[l.item_id] = (inCart[l.item_id] || 0) + l.quantity; });

        var catOrder = {};
        S.boot.categories.forEach(function (c, n) { catOrder[c.id] = n; });
        var list = itemsForOutlet().filter(function (i) {
            if (q) return i.name.toLowerCase().indexOf(q) !== -1 || (i.sku && i.sku.toLowerCase() === q);
            return S.cat === 'all' || i.category_id === S.cat;
        }).map(function (i, n) { return [i, n]; }).sort(function (a, b) {
            // Keep each category together (in menu order) when showing everything.
            return ((catOrder[a[0].category_id] || 0) - (catOrder[b[0].category_id] || 0)) || (a[1] - b[1]);
        }).map(function (x) { return x[0]; });
        if (!outlet()) {
            $('grid').innerHTML = '<div class="pos-empty"><i class="ri-store-2-line"></i>Choose where you are selling (top left).</div>';
            return;
        }
        if (!list.length) {
            $('grid').innerHTML = '<div class="pos-empty"><i class="ri-search-line"></i>' + (q ? 'Nothing matches “' + esc(S.q) + '”.' : 'No items for this outlet yet.') + '</div>';
            return;
        }
        $('grid').innerHTML = list.map(function (i) {
            return '<button type="button" class="pos-item" data-item="' + i.id + '" style="--c:' + esc(catColor[i.category_id] || '#0d9488') + '">' +
                (inCart[i.id] ? '<span class="in">' + qtyTxt(inCart[i.id]) + '</span>' : '') +
                '<span class="nm">' + esc(i.name) + '</span><span class="pr">' + money(i.price) + '</span></button>';
        }).join('');
    }

    function renderMenuFoot() {
        var parts = ['Menu updated ' + new Date(S.boot.generated_at).toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })];
        if (S.device) parts.push('Till ' + S.device.tag);
        parts.push(S.swActive ? 'Can reopen offline' : 'Offline: keep this page open');
        $('menuFoot').textContent = parts.join(' · ');
    }

    // ════════════════════════════════════════════════════════════════
    //  Rendering — order
    // ════════════════════════════════════════════════════════════════

    function renderCart() {
        var c = S.cart, p = price(c.lines, c.discount);
        if (p.discount !== c.discount) c.discount = p.discount;

        $('lines').innerHTML = c.lines.length ? c.lines.map(function (l, idx) {
            return '<div class="pos-line">' +
                '<div><div class="nm">' + esc(l.name) + '</div><div class="meta">' + money(l.unit_price) + ' each</div>' +
                (l.note ? '<div class="note"><i class="ri-chat-1-line"></i> ' + esc(l.note) + '</div>' : '') + '</div>' +
                '<div class="tot">' + money(round2(l.quantity * l.unit_price)) + '</div>' +
                '<div class="ctl"><button type="button" data-dec="' + idx + '" aria-label="One less">−</button><span class="q">' + qtyTxt(l.quantity) + '</span>' +
                '<button type="button" data-inc="' + idx + '" aria-label="One more">+</button><span class="sp"></span>' +
                '<button type="button" data-note="' + idx + '" aria-label="Add a note"><i class="ri-chat-1-line"></i></button>' +
                '<button type="button" class="rm" data-rm="' + idx + '" aria-label="Remove"><i class="ri-close-line"></i></button></div></div>';
        }).join('') : '<div class="pos-empty" style="padding:40px 10px"><i class="ri-shopping-basket-2-line"></i>Tap items to start an order.</div>';

        $('sumSub').textContent = money(p.subtotal);
        $('sumDiscRow').hidden = !(p.discount > 0);
        $('sumDisc').textContent = '−' + money(p.discount);
        $('sumDiscLbl').textContent = 'Discount' + (c.discountReason ? ' (' + c.discountReason + ')' : '');
        $('sumTaxRow').hidden = !(p.tax > 0);
        $('sumTax').textContent = money(p.tax);
        $('sumTaxLbl').textContent = p.breakdown.map(function (t) { return t.name + ' ' + t.rate + '%'; }).join(' + ');
        $('sumTaxNote').textContent = S.boot.tax.inclusive && p.breakdown.length && p.total > 0
            ? 'Includes ' + p.breakdown.map(function (t) { return t.name + ' ' + money(t.amount); }).join(', ') : '';
        $('sumTotal').textContent = money(p.total);
        $('tableLabel').value = c.table || '';

        var empty = !c.lines.length;
        ['btnHold', 'btnKitchen', 'btnDiscount', 'btnClear'].forEach(function (id) { $(id).disabled = empty; });
        $('btnDiscount').hidden = !S.boot.user.can.discount;
        renderPayButtons(empty);

        var n = c.lines.reduce(function (a, l) { return a + l.quantity; }, 0);
        $('cartBarN').textContent = qtyTxt(n) + ' item' + (n === 1 ? '' : 's');
        $('cartBarTotal').textContent = money(p.total);
        $('cartBar').classList.toggle('empty', empty);
        renderGrid();
        saveCartSoon();
    }

    function renderPayButtons(empty) {
        var o = outlet(), roomOk = o && o.room && S.boot.user.can.room;
        var html = '<button type="button" class="cash" data-pay="cash"><i class="ri-money-dollar-box-line"></i> Cash</button>' +
            '<button type="button" class="card" data-pay="card"><i class="ri-bank-card-line"></i> Card</button>' +
            '<button type="button" class="transfer" data-pay="transfer"><i class="ri-bank-line"></i> Transfer</button>' +
            (roomOk ? '<button type="button" class="room" data-pay="room"><i class="ri-hotel-bed-line"></i> Charge to room</button>' : '');
        $('payBtns').innerHTML = html;
        $('payBtns').querySelectorAll('button').forEach(function (b) { b.disabled = empty; });
    }

    var saveTimer = null;
    function saveCartSoon() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () { Store.set('cart', S.cart).catch(function () {}); }, 250);
    }

    // ── Cart actions ──
    function addItem(id) {
        var it = S.boot.items.find(function (i) { return i.id === id; });
        if (!it) return;
        var line = S.cart.lines.find(function (l) { return l.item_id === id && !l.note && l.unit_price === it.price; });
        if (line) line.quantity = round2(line.quantity + 1);
        else S.cart.lines.push({ item_id: it.id, name: it.name, charge_type: it.charge_type, unit_price: it.price, quantity: 1, note: '' });
        renderCart();
    }

    function clearCart() {
        S.cart = { lines: [], discount: 0, discountReason: '', table: '' };
        renderCart();
    }

    // ════════════════════════════════════════════════════════════════
    //  Dialog helper
    // ════════════════════════════════════════════════════════════════

    var modalOnClose = null;
    function openModal(title, body, foot, opts) {
        opts = opts || {};
        $('modalTitle').textContent = title;
        $('modalBody').innerHTML = body;
        $('modalFoot').innerHTML = foot || '';
        $('modal').querySelector('.pos-dialog').classList.toggle('wide', !!opts.wide);
        $('modal').hidden = false;
        modalOnClose = opts.onClose || null;
        var f = $('modal').querySelector('[autofocus]');
        if (f) setTimeout(function () { f.focus(); if (f.select) f.select(); }, 30);
    }
    function closeModal() {
        $('modal').hidden = true;
        var cb = modalOnClose; modalOnClose = null;
        if (cb) cb();
    }
    function toast(msg, bad) {
        var t = $('toast');
        t.textContent = msg; t.className = 'pos-toast' + (bad ? ' bad' : ''); t.hidden = false;
        clearTimeout(t._h); t._h = setTimeout(function () { t.hidden = true; }, 3200);
    }

    // ════════════════════════════════════════════════════════════════
    //  Paying
    // ════════════════════════════════════════════════════════════════

    function payDialog(method) {
        if (!S.cart.lines.length) return;
        var p = price(S.cart.lines, S.cart.discount);
        var due = '<div class="due"><span>To pay</span><strong>' + money(p.total) + '</strong></div>';

        if (method === 'cash') {
            var opts = [p.total], steps = [500, 1000, 2000, 5000, 10000];
            steps.forEach(function (s) { var v = Math.ceil(p.total / s) * s; if (v > p.total && opts.indexOf(v) === -1 && opts.length < 4) opts.push(v); });
            openModal('Cash', due +
                '<div class="quick">' + opts.map(function (v, i) { return '<button type="button" data-tender="' + v + '">' + (i === 0 ? 'Exact' : plain(v).replace('.00', '')) + '</button>'; }).join('') + '</div>' +
                '<div class="fld"><label for="tender">Cash received</label><input id="tender" class="big" type="number" inputmode="decimal" min="0" step="0.01" value="' + p.total + '" autofocus></div>' +
                '<div class="change" id="chg"><span>Change</span><strong>' + money(0) + '</strong></div>',
                '<button type="button" class="btn" data-close>Back</button><button type="button" class="btn go" id="confirmPay">Complete sale</button>');
            var upd = function () {
                var t = parseFloat($('tender').value) || 0, ch = round2(t - p.total);
                $('chg').className = 'change' + (ch < 0 ? ' short' : '');
                $('chg').innerHTML = ch < 0 ? '<span>Short by</span><strong>' + money(-ch) + '</strong>' : '<span>Change</span><strong>' + money(ch) + '</strong>';
                $('confirmPay').disabled = ch < 0;
            };
            $('tender').addEventListener('input', upd);
            $('modalBody').querySelectorAll('[data-tender]').forEach(function (b) { b.addEventListener('click', function () { $('tender').value = b.dataset.tender; upd(); }); });
            $('tender').addEventListener('keydown', function (e) { if (e.key === 'Enter' && !$('confirmPay').disabled) $('confirmPay').click(); });
            upd();
            $('confirmPay').addEventListener('click', function () { completeSale({ method: 'cash', tendered: parseFloat($('tender').value) || p.total }); });
            return;
        }

        if (method === 'card' || method === 'transfer') {
            var accounts = S.boot.bank_accounts || [];
            openModal(method === 'card' ? 'Card (POS machine)' : 'Bank transfer', due +
                (method === 'transfer' && accounts.length ? '<div class="fld"><label for="acct">Paid into</label><select id="acct">' + accounts.map(function (a) { return '<option value="' + a.id + '">' + esc(a.label) + '</option>'; }).join('') + '</select></div>' : '') +
                '<div class="fld"><label for="ref">' + (method === 'card' ? 'Approval code' : 'Transfer reference') + ' (optional)</label><input id="ref" maxlength="100" autocomplete="off" autofocus></div>' +
                (method === 'transfer' ? '<div class="warnbox">Only complete the sale once the transfer shows on the hotel’s account or the guest’s debit alert has been checked.</div>' : ''),
                '<button type="button" class="btn" data-close>Back</button><button type="button" class="btn go" id="confirmPay">Complete sale</button>');
            $('confirmPay').addEventListener('click', function () {
                completeSale({ method: method, reference: $('ref').value.trim() || null, bank_account_id: $('acct') ? parseInt($('acct').value, 10) : null });
            });
            return;
        }

        if (method === 'room') roomDialog(p);
    }

    function roomDialog(p) {
        var rooms = S.boot.rooms || [], chosen = null;
        var stale = S.online ? '' : '<div class="warnbox"><i class="ri-wifi-off-line"></i> Offline — this guest list is from ' + hhmm(S.boot.generated_at) + '. If the guest has already left, the sale will be flagged for a supervisor.</div>';
        openModal('Charge to room', '<div class="due"><span>Amount</span><strong>' + money(p.total) + '</strong></div>' + stale +
            '<div class="fld"><input id="roomQ" placeholder="Room number or guest name…" autocomplete="off" autofocus></div>' +
            '<div class="rooms" id="roomList"></div>' +
            '<div class="fld" style="margin-top:12px"><label for="signedBy">Guest signs as (optional)</label><input id="signedBy" maxlength="120" placeholder="Name of person signing"></div>',
            '<button type="button" class="btn" data-close>Back</button><button type="button" class="btn violet" id="confirmPay" disabled>Charge to room</button>', { wide: true });
        var draw = function () {
            var q = $('roomQ').value.trim().toLowerCase();
            var list = rooms.filter(function (r) {
                return !q || r.rooms.join(' ').toLowerCase().indexOf(q) !== -1 || r.guest.toLowerCase().indexOf(q) !== -1 || r.reference.toLowerCase().indexOf(q) !== -1;
            });
            $('roomList').innerHTML = list.length ? list.map(function (r) {
                return '<button type="button" data-res="' + r.reservation_id + '" class="' + (chosen && chosen.reservation_id === r.reservation_id ? 'on' : '') + '">' +
                    '<span class="no">' + esc(r.rooms.join(', ') || '—') + '</span><span><span class="gn">' + esc(r.guest) + (r.vip ? ' ★' : '') + '</span><br><span class="rf">' + esc(r.reference) + ' · leaves ' + esc(r.departs) + '</span></span></button>';
            }).join('') : '<div class="pos-empty" style="padding:20px">No guest in house matches.</div>';
            $('roomList').querySelectorAll('[data-res]').forEach(function (b) {
                b.addEventListener('click', function () {
                    chosen = rooms.find(function (r) { return r.reservation_id === parseInt(b.dataset.res, 10); });
                    $('confirmPay').disabled = false;
                    $('confirmPay').textContent = 'Charge to Room ' + (chosen.rooms[0] || '');
                    draw();
                });
            });
        };
        $('roomQ').addEventListener('input', draw);
        draw();
        $('confirmPay').addEventListener('click', function () {
            if (!chosen) return;
            completeSale({ method: 'room', reservation_id: chosen.reservation_id, room_number: chosen.rooms.join(', '), guest_name: $('signedBy').value.trim() || chosen.guest });
        });
    }

    var completing = false;
    async function completeSale(pay) {
        if (completing) return;
        completing = true;
        var o = outlet(), c = S.cart, p = price(c.lines, c.discount);
        if (!o) { completing = false; closeModal(); toast('Choose where you are selling first.', true); outletDialog(); return; }
        try {
            var seq = await Store.nextSeq();
            var order = {
                uuid: uuid(),
                number: o.code + '-' + S.device.tag + '-' + String(seq).padStart(4, '0'),
                outlet_id: o.id,
                ordered_at: new Date().toISOString(),
                method: pay.method,
                reservation_id: pay.reservation_id || null,
                room_number: pay.room_number || null,
                guest_name: pay.guest_name || null,
                table_label: (c.table || '').trim() || null,
                reference: pay.reference || null,
                bank_account_id: pay.bank_account_id || null,
                tendered: pay.method === 'cash' ? round2(pay.tendered) : null,
                discount_amount: p.discount,
                discount_reason: p.discount > 0 ? (c.discountReason || null) : null,
                total: p.total,
                cashier_id: S.boot.user.id,
                device_id: S.device.tag,
                was_offline: S.online !== true,
                items: c.lines.map(function (l) { return { item_id: l.item_id, name: l.name, charge_type: l.charge_type, quantity: l.quantity, unit_price: l.unit_price, note: l.note || null }; }),
                // Kept on the device only:
                _state: 'pending', _created: Date.now(), _outlet: o.name, _footer: o.footer, _cashier: S.boot.user.name,
                _subtotal: p.subtotal, _tax: p.tax, _breakdown: p.breakdown,
                _change: pay.method === 'cash' ? round2(pay.tendered - p.total) : null,
            };
            // The sale only counts once it is safely on the device.
            await Store.putOrder(order);
            clearCart();
            closeModal();
            showDone(order);
            refreshCounts().then(sync);
        } catch (e) {
            console.error(e);
            toast('Could not save the sale on this device: ' + (e && e.message ? e.message : e) + '. Nothing was recorded — try again.', true);
        } finally {
            completing = false;
        }
    }

    function showDone(order) {
        var body = '<div class="okbox"><i class="ri-checkbox-circle-fill"></i><h4>Sale saved</h4><div class="num">' + esc(order.number) + '</div>';
        if (order.method === 'cash') body += '<div class="sub">Change</div><div class="chg">' + money(order._change) + '</div>';
        else if (order.method === 'room') body += '<div class="sub" style="margin-top:8px">Charged to Room ' + esc(order.room_number) + ' — ' + esc(order.guest_name) + '</div>';
        else body += '<div class="sub" style="margin-top:8px">' + money(order.total) + ' by ' + (order.method === 'card' ? 'card' : 'transfer') + '</div>';
        body += '<div class="sub" style="margin-top:10px">' + (S.online ? 'Sending to the office…' : '<i class="ri-wifi-off-line"></i> Offline — saved on this till, will be sent automatically.') + '</div></div>';
        openModal('Done', body,
            '<button type="button" class="btn" id="printDone"><i class="ri-printer-line"></i> Receipt</button><button type="button" class="btn primary" data-close autofocus>New sale</button>');
        $('printDone').addEventListener('click', function () { printReceipt(order); });
        if (window.matchMedia('(max-width: 860px)').matches) $('cart').classList.remove('open');
    }

    // ════════════════════════════════════════════════════════════════
    //  Discount, notes, hold, kitchen
    // ════════════════════════════════════════════════════════════════

    function discountDialog() {
        var p = price(S.cart.lines, 0), mode = 'amount';
        openModal('Discount', '<div class="due"><span>Order before discount</span><strong>' + money(p.subtotal) + '</strong></div>' +
            '<div class="seg"><button type="button" class="on" data-mode="amount">' + esc(sym()) + ' Amount</button><button type="button" data-mode="pct">% Percent</button></div>' +
            '<div class="fld"><label for="dval" id="dlbl">Amount off</label><input id="dval" type="number" inputmode="decimal" min="0" step="0.01" value="' + (S.cart.discount || '') + '" autofocus></div>' +
            '<div class="fld"><label for="dreason">Reason</label><input id="dreason" maxlength="255" value="' + esc(S.cart.discountReason) + '" placeholder="e.g. Happy hour, manager comp"></div>',
            (S.cart.discount ? '<button type="button" class="btn" id="dremove">Remove</button>' : '') + '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn primary" id="dapply">Apply</button>');
        $('modalBody').querySelectorAll('[data-mode]').forEach(function (b) {
            b.addEventListener('click', function () {
                mode = b.dataset.mode;
                $('modalBody').querySelectorAll('[data-mode]').forEach(function (x) { x.classList.toggle('on', x === b); });
                $('dlbl').textContent = mode === 'pct' ? 'Percent off' : 'Amount off';
            });
        });
        $('dapply').addEventListener('click', function () {
            var v = parseFloat($('dval').value) || 0, reason = $('dreason').value.trim();
            if (v <= 0) { toast('Enter a discount.', true); return; }
            if (!reason) { toast('Give a reason for the discount.', true); $('dreason').focus(); return; }
            S.cart.discount = round2(mode === 'pct' ? p.subtotal * Math.min(v, 100) / 100 : Math.min(v, p.subtotal));
            S.cart.discountReason = reason;
            closeModal(); renderCart();
        });
        if ($('dremove')) $('dremove').addEventListener('click', function () { S.cart.discount = 0; S.cart.discountReason = ''; closeModal(); renderCart(); });
    }

    function noteDialog(idx) {
        var l = S.cart.lines[idx];
        openModal('Note for ' + l.name, '<div class="fld"><input id="lnote" maxlength="120" value="' + esc(l.note) + '" placeholder="e.g. no ice, well done, extra pepper" autofocus></div>',
            '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn primary" id="nsave">Save</button>');
        var save = function () {
            var note = $('lnote').value.trim();
            if (note && l.quantity > 1 && note !== l.note) {
                // The note applies to one of them; split it off.
                l.quantity = round2(l.quantity - 1);
                S.cart.lines.splice(idx + 1, 0, Object.assign({}, l, { quantity: 1, note: note }));
            } else {
                l.note = note;
            }
            closeModal(); renderCart();
        };
        $('nsave').addEventListener('click', save);
        $('lnote').addEventListener('keydown', function (e) { if (e.key === 'Enter') save(); });
    }

    function holdOrder() {
        if (!S.cart.lines.length) return;
        var doHold = function (label) {
            S.held.unshift({ id: uuid(), label: label, outlet_id: S.outletId, cart: JSON.parse(JSON.stringify(S.cart)), at: Date.now() });
            S.cart.table = label;
            Store.set('held', S.held).then(function () { toast('Held: ' + label); clearCart(); renderHeld(); });
        };
        if ((S.cart.table || '').trim()) return doHold(S.cart.table.trim());
        openModal('Hold order', '<div class="fld"><label for="hlabel">Table or name</label><input id="hlabel" maxlength="40" placeholder="e.g. Table 4, Mr Bello" autofocus></div>' +
            '<div class="tiny" style="color:var(--muted);font-size:12.5px">Held orders stay on this till.</div>',
            '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn primary" id="hsave">Hold</button>');
        var go = function () { var v = $('hlabel').value.trim(); if (!v) { $('hlabel').focus(); return; } closeModal(); doHold(v); };
        $('hsave').addEventListener('click', go);
        $('hlabel').addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); });
    }

    function resumeHeld(id) {
        var h = S.held.find(function (x) { return x.id === id; });
        if (!h) return;
        var go = function () {
            if (S.cart.lines.length) {
                // Swap: put what's on screen on hold first.
                var label = (S.cart.table || '').trim() || 'Order ' + hhmm(Date.now());
                S.held.push({ id: uuid(), label: label, outlet_id: S.outletId, cart: JSON.parse(JSON.stringify(S.cart)), at: Date.now() });
            }
            S.held = S.held.filter(function (x) { return x.id !== id; });
            S.cart = h.cart;
            if (h.outlet_id && h.outlet_id !== S.outletId && S.boot.outlets.some(function (o) { return o.id === h.outlet_id; })) setOutlet(h.outlet_id);
            Store.set('held', S.held);
            showPane('order'); renderCart(); renderHeld();
        };
        go();
    }

    function renderHeld() {
        $('heldN').textContent = S.held.length;
        $('heldList').innerHTML = S.held.length ? S.held.map(function (h) {
            var p = price(h.cart.lines, h.cart.discount), n = h.cart.lines.reduce(function (a, l) { return a + l.quantity; }, 0);
            var o = S.boot.outlets.find(function (x) { return x.id === h.outlet_id; });
            return '<div class="pos-row"><div class="main"><div class="t">' + esc(h.label) + '</div><div class="s">' + qtyTxt(n) + ' items · ' + hhmm(h.at) + (o ? ' · ' + esc(o.name) : '') + '</div></div>' +
                '<span class="amt">' + money(p.total) + '</span><button type="button" data-resume="' + h.id + '">Open</button><button type="button" data-drop="' + h.id + '" aria-label="Delete"><i class="ri-delete-bin-line"></i></button></div>';
        }).join('') : '<div class="pos-empty"><i class="ri-pause-circle-line"></i>No held orders. Use “Hold” to park an open tab.</div>';
    }

    function kitchenTicket() {
        if (!S.cart.lines.length) return;
        var o = outlet();
        var html = '<div class="c b big">' + esc(o ? o.name : '') + ' — ORDER</div><hr>' +
            '<div class="kt">' + esc(S.cart.table || 'Counter') + '</div><div>' + new Date().toLocaleString() + ' · ' + esc(S.boot.user.name) + '</div><hr>' +
            '<table>' + S.cart.lines.map(function (l) {
                return '<tr><td class="kt" style="width:16mm">' + qtyTxt(l.quantity) + '×</td><td class="kt">' + esc(l.name) + (l.note ? '<br><span style="font-size:13px">» ' + esc(l.note) + '</span>' : '') + '</td></tr>';
            }).join('') + '</table><hr>';
        printHtml(html);
    }

    // ════════════════════════════════════════════════════════════════
    //  Receipts (printed from the device copy — works offline)
    // ════════════════════════════════════════════════════════════════

    function receiptHtml(o) {
        var hotel = S.boot.hotel.name;
        var h = '<div class="c b big">' + esc(hotel) + '</div><div class="c">' + esc(o._outlet || '') + '</div><hr>' +
            '<div>Order <span class="b">' + esc((o._server && o._server.number) || o.number) + '</span></div>' +
            '<div>' + new Date(o.ordered_at).toLocaleString() + ' · ' + esc(o._cashier || '') + '</div>' +
            (o.table_label ? '<div>Table: ' + esc(o.table_label) + '</div>' : '') + '<hr><table>';
        o.items.forEach(function (l) {
            h += '<tr><td colspan="2">' + esc(l.name) + '</td></tr><tr><td>&nbsp; ' + qtyTxt(l.quantity) + ' × ' + plain(l.unit_price) + '</td><td class="r">' + plain(round2(l.quantity * l.unit_price)) + '</td></tr>';
            if (l.note) h += '<tr><td colspan="2">&nbsp; (' + esc(l.note) + ')</td></tr>';
        });
        h += '</table><hr><table>';
        if (o.discount_amount > 0 || o._tax > 0) h += '<tr><td>Subtotal</td><td class="r">' + plain(o._subtotal) + '</td></tr>';
        if (o.discount_amount > 0) h += '<tr><td>Discount</td><td class="r">-' + plain(o.discount_amount) + '</td></tr>';
        if (o._tax > 0) (o._breakdown || []).forEach(function (t) { h += '<tr><td>' + esc(t.name) + ' ' + t.rate + '%</td><td class="r">' + plain(t.amount) + '</td></tr>'; });
        h += '<tr class="b big"><td>TOTAL ' + esc(sym()) + '</td><td class="r">' + plain(o.total) + '</td></tr>';
        if (o.method === 'room') {
            h += '<tr><td colspan="2">Charged to room ' + esc(o.room_number) + '</td></tr><tr><td colspan="2">' + esc(o.guest_name) + '</td></tr>';
        } else {
            var label = { cash: 'Cash', card: 'Card', transfer: 'Transfer' }[o.method] || o.method;
            h += '<tr><td>Paid by ' + label + '</td><td class="r">' + plain(o.tendered != null ? o.tendered : o.total) + '</td></tr>';
            if (o._change > 0) h += '<tr><td>Change</td><td class="r">' + plain(o._change) + '</td></tr>';
            if (o.reference) h += '<tr><td colspan="2">Ref: ' + esc(o.reference) + '</td></tr>';
        }
        h += '</table>';
        if (!(o._tax > 0) && (o._breakdown || []).length) h += '<div style="font-size:11px;margin-top:4px">Prices include ' + o._breakdown.map(function (t) { return esc(t.name) + ' ' + plain(t.amount); }).join(', ') + '</div>';
        if (o.method === 'room') h += '<div style="margin-top:22px">Guest signature: ____________________</div>';
        h += '<hr><div class="c">' + esc(o._footer || 'Thank you!') + '</div>';
        return h;
    }
    function printReceipt(o) { printHtml(receiptHtml(o)); }
    function printHtml(html) {
        $('printArea').innerHTML = html;
        setTimeout(function () { window.print(); }, 50);
    }

    // ════════════════════════════════════════════════════════════════
    //  Recent sales on this till
    // ════════════════════════════════════════════════════════════════

    function badgeFor(o) {
        if (o._state === 'sent') return (o._server && o._server.status === 'attention') ? '<span class="badge attn">Flagged</span>' : '<span class="badge sent"><i class="ri-check-line"></i> Sent</span>';
        if (o._state === 'rejected') return '<span class="badge bad">Not accepted</span>';
        return '<span class="badge pending"><i class="ri-time-line"></i> Waiting</span>';
    }

    async function renderRecent() {
        var all = await Store.orders();
        all.sort(function (a, b) { return b._created - a._created; });
        var list = all.slice(0, 40);
        $('recentList').innerHTML = list.length ? list.map(function (o) {
            var how = o.method === 'room' ? 'Room ' + (o.room_number || '') : ({ cash: 'Cash', card: 'Card', transfer: 'Transfer' }[o.method] || o.method);
            return '<div class="pos-row"><div class="main"><div class="t">' + esc((o._server && o._server.number) || o.number) + ' ' + badgeFor(o) + '</div>' +
                '<div class="s">' + hhmm(o._created) + ' · ' + esc(how) + ' · ' + esc(o.items.map(function (l) { return qtyTxt(l.quantity) + '× ' + l.name; }).join(', ')) + '</div></div>' +
                '<span class="amt">' + money(o.total) + '</span><button type="button" data-view="' + o.uuid + '">View</button></div>';
        }).join('') : '<div class="pos-empty"><i class="ri-history-line"></i>No sales on this till yet.</div>';
    }

    async function viewOrder(id) {
        var all = await Store.orders(), o = all.find(function (x) { return x.uuid === id; });
        if (!o) return;
        var msg = '';
        if (o._state === 'pending') msg = '<div class="warnbox"><i class="ri-time-line"></i> Waiting to be sent — it is safe on this till.</div>';
        if (o._state === 'rejected') msg = '<div class="warnbox" style="background:#fee2e2;color:#7f1d1d"><b>The office could not accept this sale:</b> ' + esc(o._error || '') + '<br>Show it to a supervisor. It stays on this till until it is sent.</div>';
        if (o._server && o._server.message) msg += '<div class="warnbox">' + esc(o._server.message) + '</div>';
        openModal('Sale ' + ((o._server && o._server.number) || o.number), msg + '<div style="font-family:ui-monospace,Menlo,monospace;font-size:13px;background:var(--surface-2);padding:12px;border-radius:12px">' + receiptHtml(o) + '</div>',
            (o._state === 'rejected' ? '<button type="button" class="btn" id="retry">Try again</button>' : '') +
            '<button type="button" class="btn" data-close>Close</button><button type="button" class="btn primary" id="reprint"><i class="ri-printer-line"></i> Print</button>');
        $('reprint').addEventListener('click', function () { printReceipt(o); });
        if ($('retry')) $('retry').addEventListener('click', async function () {
            o._state = 'pending'; delete o._error; await Store.putOrder(o); closeModal(); await refreshCounts(); sync();
        });
    }

    // ════════════════════════════════════════════════════════════════
    //  Network & syncing
    // ════════════════════════════════════════════════════════════════

    function request(url, opts, timeout) {
        opts = opts || {};
        var ctl = window.AbortController ? new AbortController() : null;
        var timer = ctl ? setTimeout(function () { ctl.abort(); }, timeout || 8000) : null;
        var headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, opts.headers || {});
        return fetch(url, Object.assign({}, opts, { headers: headers, credentials: 'same-origin', cache: 'no-store', signal: ctl ? ctl.signal : undefined }))
            .finally(function () { if (timer) clearTimeout(timer); });
    }

    function postJson(url, body, timeout) {
        return request(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': S.csrf }, body: JSON.stringify(body) }, timeout);
    }

    async function refreshCsrf() {
        var r = await request(URLS.csrf, {}, 8000);
        if (r.status === 401) { setAuth(false); throw new Error('signed out'); }
        var j = await r.json();
        S.csrf = j.csrf_token;
    }

    function setOnline(on) {
        var was = S.online;
        S.online = on;
        renderNet();
        if (on && was !== true) { sync(); refreshData(); }
    }
    function setAuth(ok) {
        var was = S.auth;
        S.auth = ok;
        renderNet();
        if (ok && !was) { refreshCsrf().then(sync).catch(function () {}); refreshData(); }
    }

    var pingTimer = null, failStreak = 0;
    async function ping() {
        clearTimeout(pingTimer);
        try {
            var r = await request(URLS.ping, {}, 6000);
            if (r.status === 401 || r.status === 419) { setOnline(true); setAuth(false); }
            else if (r.ok) {
                var j = await r.json();
                S.otherUser = j.user !== S.boot.user.id;
                setOnline(true); setAuth(true);
            } else if (r.status === 403) { setOnline(true); S.otherUser = true; renderNet(); }
            else { throw new Error('HTTP ' + r.status); }
            failStreak = 0;
        } catch (e) {
            failStreak++;
            setOnline(false);
        }
        // Check often while there is something to send or the link is down.
        var next = S.online ? (S.pending ? 5000 : 20000) : Math.min(3000 * Math.pow(1.5, failStreak), 20000);
        pingTimer = setTimeout(ping, next);
    }

    async function refreshCounts() {
        var all = await Store.orders();
        S.pending = all.filter(function (o) { return o._state === 'pending'; }).length;
        S.rejected = all.filter(function (o) { return o._state === 'rejected'; }).length;
        renderNet();
        if (!$('recentList').closest('.pos-pane').hidden) renderRecent();
    }

    async function sync() {
        if (S.syncing || S.online !== true || !S.auth || S.otherUser) return;
        var all = await Store.orders();
        var waiting = all.filter(function (o) { return o._state === 'pending'; }).sort(function (a, b) { return a._created - b._created; });
        if (!waiting.length) return;

        S.syncing = true; renderNet();
        var batch = waiting.slice(0, 20);
        var payload = batch.map(function (o) {
            var out = {};
            Object.keys(o).forEach(function (k) { if (k.charAt(0) !== '_') out[k] = o[k]; });
            return out;
        });
        try {
            var r = await postJson(URLS.sync, { orders: payload }, 30000);
            if (r.status === 419) { await refreshCsrf(); r = await postJson(URLS.sync, { orders: payload }, 30000); }
            if (r.status === 401) { setAuth(false); return; }
            if (r.status === 403) { S.otherUser = true; renderNet(); return; }
            if (!r.ok) throw new Error('Server error ' + r.status);
            var j = await r.json();
            for (var i = 0; i < j.results.length; i++) {
                var res = j.results[i], o = batch.find(function (x) { return x.uuid === res.uuid; });
                if (!o) continue;
                if (res.result === 'ok' || res.result === 'duplicate') {
                    o._state = 'sent'; o._sent = Date.now();
                    o._server = { id: res.id, number: res.number, status: res.status, message: res.message };
                } else {
                    o._state = 'rejected'; o._error = res.message;
                }
                await Store.putOrder(o);
            }
            S.lastSync = Date.now();
        } catch (e) {
            // Timed out or the link dropped mid-way. Nothing is lost: the sales are
            // still marked "pending" and will be re-sent; the server ignores repeats.
            setOnline(false);
        } finally {
            S.syncing = false;
            await refreshCounts();
            if (S.pending && S.online) setTimeout(sync, 400);
        }
    }

    async function refreshData() {
        if (S.online !== true || !S.auth) return;
        try {
            var r = await request(URLS.data, {}, 15000);
            if (!r.ok) return;
            var j = await r.json();
            if (j.user.id !== S.boot.user.id) { S.otherUser = true; renderNet(); return; }
            if (j.csrf) S.csrf = j.csrf;
            delete j.csrf;
            S.boot = j;
            await Store.set('boot', j);
            if (!outlet() && j.outlets.length) setOutlet(j.outlets[0].id);
            renderOutlet(); renderCats(); renderCart(); renderMenuFoot();
        } catch (e) { /* keep the copy we have */ }
    }

    function renderNet() {
        var pill = $('netPill'), txt = $('netText'), banner = $('banner');
        pill.className = 'pos-net';
        if (S.online === null) { txt.textContent = 'Checking…'; }
        else if (!S.online) { pill.classList.add('offline'); txt.textContent = 'Offline'; }
        else if (!S.auth) { pill.classList.add('signedout'); txt.textContent = 'Signed out'; }
        else if (S.syncing) { pill.classList.add('syncing'); txt.textContent = 'Sending…'; }
        else { pill.classList.add('online'); txt.textContent = 'Online'; }

        var q = $('queueBtn');
        q.hidden = !(S.pending || S.rejected);
        q.classList.toggle('bad', !!S.rejected);
        $('queueText').textContent = S.rejected ? S.rejected + ' not accepted' + (S.pending ? ' · ' + S.pending + ' to send' : '') : S.pending + ' to send';

        if (S.online === false) {
            banner.hidden = false; banner.className = 'pos-banner offline';
            banner.innerHTML = '<i class="ri-wifi-off-line"></i><span>No connection to the office. <b>Keep selling</b> — every sale is saved on this till and sent automatically when the network is back.' +
                (S.swActive ? '' : ' <b>Don’t close or refresh this page</b> until it’s back.') + '</span>';
        } else if (S.online && !S.auth) {
            banner.hidden = false; banner.className = 'pos-banner signedout';
            banner.innerHTML = '<i class="ri-lock-line"></i><span>Your sign-in has expired. Sales are safe on this till' + (S.pending ? ' (' + S.pending + ' waiting)' : '') + '. </span><a href="' + esc(URLS.login) + '" target="_blank" rel="noopener">Sign in again</a>';
        } else if (S.otherUser) {
            banner.hidden = false; banner.className = 'pos-banner signedout';
            banner.innerHTML = '<i class="ri-user-shared-line"></i><span>Someone else signed in on this browser. Reload the till to continue as them — waiting sales stay on this device.</span><button type="button" onclick="location.reload()">Reload</button>';
        } else {
            banner.hidden = true;
        }
    }

    function queueDialog() {
        Store.orders().then(function (all) {
            var waiting = all.filter(function (o) { return o._state !== 'sent'; }).sort(function (a, b) { return a._created - b._created; });
            var total = waiting.reduce(function (a, o) { return a + o.total; }, 0);
            openModal('Sales waiting on this till',
                '<div class="due"><span>' + waiting.length + ' sale' + (waiting.length === 1 ? '' : 's') + '</span><strong>' + money(total) + '</strong></div>' +
                (S.online ? '' : '<div class="warnbox"><i class="ri-wifi-off-line"></i> Offline. They will be sent automatically.</div>') +
                waiting.map(function (o) {
                    return '<div class="pos-row"><div class="main"><div class="t">' + esc(o.number) + ' ' + badgeFor(o) + '</div><div class="s">' + new Date(o._created).toLocaleString() + (o._error ? ' · ' + esc(o._error) : '') + '</div></div><span class="amt">' + money(o.total) + '</span><button type="button" data-view="' + o.uuid + '">View</button></div>';
                }).join('') +
                '<p style="font-size:12.5px;color:var(--muted);margin-top:12px">Last sent to the office: ' + (S.lastSync ? new Date(S.lastSync).toLocaleTimeString() : 'not yet this session') + '. Saved with ' + Store.kind + '.</p>',
                '<button type="button" class="btn" id="qBackup"><i class="ri-download-2-line"></i> Backup file</button><button type="button" class="btn" data-close>Close</button><button type="button" class="btn primary" id="qSend"' + (S.online ? '' : ' disabled') + '>Send now</button>', { wide: true });
            $('qSend').addEventListener('click', function () { closeModal(); sync(); });
            $('qBackup').addEventListener('click', function () {
                // A file a supervisor can keep if this device ever has to be reset before it reconnects.
                var blob = new Blob([JSON.stringify({ exported_at: new Date().toISOString(), device: S.device.tag, orders: waiting }, null, 2)], { type: 'application/json' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob); a.download = 'till-' + S.device.tag + '-unsent-' + new Date().toISOString().slice(0, 16).replace(/[:T]/g, '') + '.json';
                document.body.appendChild(a); a.click(); a.remove();
            });
        });
    }

    // ════════════════════════════════════════════════════════════════
    //  Outlets & panes
    // ════════════════════════════════════════════════════════════════

    function setOutlet(id) {
        S.outletId = id;
        Store.set('outlet', id);
        S.cat = 'all';
        renderOutlet(); renderCats(); renderCart();
    }

    function outletDialog() {
        var list = S.boot.outlets;
        openModal('Where are you selling?', list.length ? '<div class="rooms">' + list.map(function (o) {
            return '<button type="button" data-outlet="' + o.id + '" class="' + (o.id === S.outletId ? 'on' : '') + '"><span class="no" style="color:' + esc(o.color) + ';min-width:70px">' + esc(o.code) + '</span><span class="gn">' + esc(o.name) + '</span></button>';
        }).join('') + '</div>' : '<div class="pos-empty">No outlets are set up yet. Ask a manager to add one under Menu & Prices.</div>', '');
        $('modalBody').querySelectorAll('[data-outlet]').forEach(function (b) {
            b.addEventListener('click', function () { setOutlet(parseInt(b.dataset.outlet, 10)); closeModal(); });
        });
    }

    function showPane(name) {
        document.querySelectorAll('.pos-tabs [data-pane]').forEach(function (b) { b.classList.toggle('on', b.dataset.pane === name); });
        document.querySelectorAll('.pos-pane').forEach(function (p) { p.hidden = p.dataset.pane !== name; });
        if (name === 'recent') renderRecent();
        if (name === 'held') renderHeld();
    }

    // ════════════════════════════════════════════════════════════════
    //  Events
    // ════════════════════════════════════════════════════════════════

    function wire() {
        $('cats').addEventListener('click', function (e) {
            var b = e.target.closest('[data-cat]'); if (!b) return;
            S.cat = b.dataset.cat === 'all' ? 'all' : parseInt(b.dataset.cat, 10);
            S.q = ''; $('search').value = '';
            renderCats(); renderGrid();
        });
        $('grid').addEventListener('click', function (e) {
            var b = e.target.closest('[data-item]'); if (b) addItem(parseInt(b.dataset.item, 10));
        });
        $('search').addEventListener('input', function () { S.q = this.value; renderGrid(); });
        $('search').addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            // Barcode scanners type the code and press Enter.
            var q = this.value.trim().toLowerCase(), items = itemsForOutlet();
            var hit = items.find(function (i) { return i.sku && i.sku.toLowerCase() === q; });
            if (!hit) { var m = items.filter(function (i) { return i.name.toLowerCase().indexOf(q) !== -1; }); if (m.length === 1) hit = m[0]; }
            if (hit) { addItem(hit.id); this.value = ''; S.q = ''; renderGrid(); }
        });
        $('lines').addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            var L = S.cart.lines;
            if (b.dataset.inc) { L[b.dataset.inc].quantity = round2(L[b.dataset.inc].quantity + 1); }
            else if (b.dataset.dec) { var l = L[b.dataset.dec]; l.quantity = round2(l.quantity - 1); if (l.quantity <= 0) L.splice(b.dataset.dec, 1); }
            else if (b.dataset.rm) { L.splice(b.dataset.rm, 1); }
            else if (b.dataset.note) { return noteDialog(parseInt(b.dataset.note, 10)); }
            if (!L.length) { S.cart.discount = 0; S.cart.discountReason = ''; }
            renderCart();
        });
        $('tableLabel').addEventListener('input', function () { S.cart.table = this.value; saveCartSoon(); });
        $('payBtns').addEventListener('click', function (e) { var b = e.target.closest('[data-pay]'); if (b && !b.disabled) payDialog(b.dataset.pay); });
        $('btnClear').addEventListener('click', function () {
            if (!S.cart.lines.length) return;
            openModal('Clear this order?', '<p>The items on screen will be removed. Nothing has been charged.</p>',
                '<button type="button" class="btn" data-close>Keep it</button><button type="button" class="btn danger" id="doClear">Clear</button>');
            $('doClear').addEventListener('click', function () { closeModal(); clearCart(); });
        });
        $('btnHold').addEventListener('click', holdOrder);
        $('btnKitchen').addEventListener('click', kitchenTicket);
        $('btnDiscount').addEventListener('click', discountDialog);
        $('outletBtn').addEventListener('click', outletDialog);
        $('queueBtn').addEventListener('click', queueDialog);
        document.querySelectorAll('.pos-tabs [data-pane]').forEach(function (b) { b.addEventListener('click', function () { showPane(b.dataset.pane); }); });
        $('heldList').addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            if (b.dataset.resume) resumeHeld(b.dataset.resume);
            if (b.dataset.drop) {
                var h = S.held.find(function (x) { return x.id === b.dataset.drop; });
                openModal('Delete held order?', '<p>Delete “' + esc(h.label) + '”? Nothing has been charged for it.</p>',
                    '<button type="button" class="btn" data-close>Keep</button><button type="button" class="btn danger" id="doDrop">Delete</button>');
                $('doDrop').addEventListener('click', function () { S.held = S.held.filter(function (x) { return x.id !== h.id; }); Store.set('held', S.held); closeModal(); renderHeld(); });
            }
        });
        document.addEventListener('click', function (e) {
            var v = e.target.closest('[data-view]'); if (v) { viewOrder(v.dataset.view); }
        });
        $('modal').addEventListener('click', function (e) { if (e.target === this || e.target.closest('[data-close]')) closeModal(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !$('modal').hidden) closeModal();
            if (e.key === '/' && $('modal').hidden && document.activeElement.tagName !== 'INPUT') { e.preventDefault(); $('search').focus(); }
        });
        $('cartBar').addEventListener('click', function () { $('cart').classList.add('open'); });
        $('cartClose').addEventListener('click', function () { $('cart').classList.remove('open'); });

        window.addEventListener('online', function () { failStreak = 0; ping(); });
        window.addEventListener('offline', function () { setOnline(false); });
        document.addEventListener('visibilitychange', function () { if (!document.hidden) ping(); });
        // Leaving the till while offline: without the offline copy it can't be reopened until the network is back.
        window.addEventListener('beforeunload', function (e) {
            if (S.online === false && !S.swActive) { e.preventDefault(); e.returnValue = ''; }
        });
        document.querySelectorAll('[data-leave]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                if (S.online === false) {
                    e.preventDefault();
                    openModal('You are offline', '<p>The office pages can’t open without a connection. Stay on the till — your sales are safe here.</p>', '<button type="button" class="btn primary" data-close>Stay on the till</button>');
                }
            });
        });
    }

    // ════════════════════════════════════════════════════════════════
    //  Start
    // ════════════════════════════════════════════════════════════════

    async function start() {
        await Store.open();
        if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function () {});

        // This device's till id (part of every order number).
        var device = await Store.get('device');
        if (!device) {
            var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', b = crypto.getRandomValues(new Uint8Array(4));
            device = { tag: Array.prototype.map.call(b, function (x) { return chars[x % chars.length]; }).join(''), seq: 0 };
            await Store.set('device', device);
        }
        S.device = device;

        // Use whichever menu copy is newer: the page's, or the one saved on the device.
        var saved = await Store.get('boot');
        if (saved && saved.user && saved.user.id === BOOT.user.id && saved.generated_at > BOOT.generated_at) S.boot = saved;
        else await Store.set('boot', BOOT);

        S.held = (await Store.get('held')) || [];
        var cart = await Store.get('cart');
        if (cart && cart.lines) S.cart = cart;

        var oid = await Store.get('outlet');
        if (!S.boot.outlets.some(function (o) { return o.id === oid; })) oid = S.boot.outlets.length === 1 ? S.boot.outlets[0].id : null;
        S.outletId = oid;

        // Tidy: forget sales sent more than a week ago.
        var week = Date.now() - 7 * 864e5;
        (await Store.orders()).forEach(function (o) { if (o._state === 'sent' && (o._sent || o._created) < week) Store.deleteOrder(o.uuid); });

        wire();
        renderOutlet(); renderCats(); renderCart(); renderHeld(); renderMenuFoot();
        $('app').removeAttribute('data-loading');
        await refreshCounts();
        if (!S.outletId && S.boot.outlets.length) outletDialog();

        // An offline copy of this page, so the till can be reopened without a network (needs https or localhost).
        if ('serviceWorker' in navigator && window.isSecureContext) {
            navigator.serviceWorker.register(URLS.sw, { scope: URLS.terminal }).then(function () {
                return navigator.serviceWorker.ready;
            }).then(function (reg) {
                // Hand the worker every file this page loaded (CSS, this script, icon fonts) to keep offline.
                var urls = performance.getEntriesByType('resource').map(function (r) { return r.name; })
                    .filter(function (u) { return u.indexOf(location.origin) === 0 && /\.(css|js|woff2?|ttf|eot|svg|png|ico)(\?|$)/i.test(u); });
                if (reg.active) reg.active.postMessage({ type: 'precache', urls: urls });
                S.swActive = true; renderMenuFoot(); renderNet();
            }).catch(function () {});
        }

        ping();
        setInterval(function () { refreshData(); }, 3 * 60 * 1000);
    }

    start().catch(function (e) {
        console.error(e);
        document.body.insertAdjacentHTML('afterbegin', '<div style="background:#b91c1c;color:#fff;padding:12px;font-weight:700">The till could not start: ' + esc(e && e.message) + '</div>');
    });
})();
