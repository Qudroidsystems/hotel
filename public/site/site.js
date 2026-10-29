/* The hotel's public website: header, menu, hero slider, booking drawer, carousels, reveals, parallax,
   counters, gallery lightbox. Plain JavaScript, no libraries; everything still works without it. */
(function () {
    'use strict';
    const doc = document.documentElement, body = document.body;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const $ = (s, el) => (el || document).querySelector(s);
    const $$ = (s, el) => Array.from((el || document).querySelectorAll(s));
    doc.classList.add('js');

    // ── Announcement height (the header sits under it over the hero) ──
    const announce = $('.announce');
    const setAnnounce = () => doc.style.setProperty('--announce-h', announce ? announce.offsetHeight + 'px' : '0px');
    setAnnounce(); window.addEventListener('resize', setAnnounce);

    // ── Header: solid after the hero starts to scroll away, hides going down, back going up ──
    const header = $('.site-header');
    let lastY = window.scrollY, ticking = false;
    const toTop = $('.to-top'), ring = toTop && $('circle', toTop), mobileBook = $('.mobile-book');
    function onScroll() {
        const y = window.scrollY;
        if (header) {
            header.classList.toggle('solid', y > 40);
            const goingDown = y > lastY && y > 320;
            header.classList.toggle('away', goingDown && !body.classList.contains('menu-open') && !body.classList.contains('drawer-open'));
        }
        const max = doc.scrollHeight - window.innerHeight;
        if (toTop) {
            toTop.classList.toggle('show', y > 700);
            if (ring) ring.style.strokeDashoffset = String(151 - 151 * Math.min(1, y / Math.max(1, max)));
        }
        if (mobileBook) mobileBook.classList.toggle('show', y > 500 && y < max - 300);
        lastY = y;
        parallax();
        ticking = false;
    }
    window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(onScroll); } }, { passive: true });
    if (toTop) toTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }));

    // ── Menu (phones and tablets) ──
    const burger = $('.burger');
    if (burger) {
        burger.addEventListener('click', () => {
            const open = !body.classList.contains('menu-open');
            body.classList.toggle('menu-open', open); body.classList.toggle('no-scroll', open);
            burger.setAttribute('aria-expanded', String(open));
        });
        $$('.menu a').forEach(a => a.addEventListener('click', () => { body.classList.remove('menu-open', 'no-scroll'); burger.setAttribute('aria-expanded', 'false'); }));
    }

    // ── Booking drawer ──
    const drawer = $('.drawer');
    function openDrawer(promo) {
        if (!drawer) return;
        if (promo) { const p = $('input[name=promo]', drawer); if (p) p.value = promo; }
        body.classList.add('drawer-open', 'no-scroll'); body.classList.remove('menu-open');
        drawer.setAttribute('aria-hidden', 'false');
        setTimeout(() => { const f = $('input[type=date]', drawer); if (f) f.focus({ preventScroll: true }); }, 450);
    }
    function closeDrawer() { body.classList.remove('drawer-open', 'no-scroll'); if (drawer) drawer.setAttribute('aria-hidden', 'true'); }
    $$('[data-book]').forEach(b => b.addEventListener('click', e => { e.preventDefault(); openDrawer(b.dataset.promo); }));
    $$('[data-close-drawer]').forEach(b => b.addEventListener('click', closeDrawer));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeDrawer(); closeLightbox(); body.classList.remove('menu-open', 'no-scroll'); } });
    if (location.hash === '#book') setTimeout(() => openDrawer(new URLSearchParams(location.search).get('promo')), 600);

    // Check-out always after check-in, in every booking form.
    $$('form[data-booking]').forEach(f => {
        const i = $('input[name=check_in]', f), o = $('input[name=check_out]', f);
        if (!i || !o) return;
        i.addEventListener('change', () => {
            if (!i.value) return;
            const d = new Date(i.value + 'T00:00'); d.setDate(d.getDate() + 1);
            const next = d.toISOString().slice(0, 10); o.min = next;
            if (!o.value || o.value <= i.value) o.value = next;
        });
    });

    // Guests: adults / children / rooms steppers with a summary line.
    $$('[data-guests]').forEach(box => {
        const btn = $('.val', box), pop = $('.guests-pop', box);
        const show = () => {
            const n = k => +$('input[name=' + k + ']', box).value;
            const a = n('adults'), c = n('children'), r = n('rooms');
            btn.textContent = a + (a === 1 ? ' adult' : ' adults') + (c ? ', ' + c + (c === 1 ? ' child' : ' children') : '') + (r > 1 ? ' · ' + r + ' rooms' : '');
        };
        $$('.stepper', box).forEach(s => {
            const input = $('input', s), out = $('output', s), min = +input.min, max = +input.max;
            const sync = () => { out.textContent = input.value; $('[data-step="-1"]', s).disabled = +input.value <= min; $('[data-step="1"]', s).disabled = +input.value >= max; show(); };
            $$('[data-step]', s).forEach(b => b.addEventListener('click', () => { input.value = Math.min(max, Math.max(min, +input.value + +b.dataset.step)); sync(); }));
            sync();
        });
        if (pop) {
            btn.addEventListener('click', e => { e.preventDefault(); pop.classList.toggle('open'); btn.setAttribute('aria-expanded', pop.classList.contains('open')); });
            document.addEventListener('click', e => { if (!box.contains(e.target)) pop.classList.remove('open'); });
        }
    });

    // ── Hero slider: crossfade with slow zoom, progress bars, arrows, swipe, pause on hover ──
    $$('.hero[data-slider]').forEach(hero => {
        const slides = $$('.slide', hero), dots = $$('.dots button', hero), countEl = $('.count b', hero);
        // The first slide is shown without JavaScript; take it off and on again so its words animate in.
        slides.forEach(s => s.classList.remove('on'));
        const first = () => requestAnimationFrame(() => requestAnimationFrame(() => { slides[0] && slides[0].classList.add('on'); if (dots[0]) dots[0].classList.add('on'); }));
        if (slides.length < 2) { first(); return; }
        const dur = 7000; let at = 0, timer = null, paused = false;
        hero.style.setProperty('--dur', dur + 'ms');
        function go(n) {
            slides[at].classList.remove('on'); dots[at] && dots[at].classList.remove('on');
            at = (n + slides.length) % slides.length;
            slides[at].classList.add('on');
            dots.forEach((d, i) => { d.classList.toggle('done', i < at); d.classList.remove('on'); });
            if (dots[at]) { void dots[at].offsetWidth; dots[at].classList.add('on'); }
            if (countEl) countEl.textContent = String(at + 1).padStart(2, '0');
            schedule();
        }
        function schedule() { clearTimeout(timer); if (!paused && !reduce) timer = setTimeout(() => go(at + 1), dur); }
        dots.forEach((d, i) => d.addEventListener('click', () => go(i)));
        $$('[data-dir]', hero).forEach(b => b.addEventListener('click', () => go(at + +b.dataset.dir)));
        hero.addEventListener('mouseenter', () => { paused = true; hero.classList.add('paused'); clearTimeout(timer); });
        hero.addEventListener('mouseleave', () => { paused = false; hero.classList.remove('paused'); schedule(); });
        let sx = null;
        hero.addEventListener('touchstart', e => { sx = e.touches[0].clientX; }, { passive: true });
        hero.addEventListener('touchend', e => { if (sx === null) return; const dx = e.changedTouches[0].clientX - sx; if (Math.abs(dx) > 50) go(at + (dx < 0 ? 1 : -1)); sx = null; });
        document.addEventListener('visibilitychange', () => { if (document.hidden) clearTimeout(timer); else schedule(); });
        first();
        schedule();
    });

    // Headings that rise word by word.
    $$('.split').forEach(el => {
        if (el.dataset.done) return; el.dataset.done = '1';
        const words = el.textContent.trim().split(/\s+/);
        el.innerHTML = words.map((w, i) => '<span class="w"><span style="transition-delay:' + (0.08 * i + (+el.dataset.delay || 0)).toFixed(2) + 's">' + w.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])) + '</span></span>').join(' ');
    });

    // ── Reveal on scroll ──
    const io = 'IntersectionObserver' in window && !reduce ? new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (!e.isIntersecting) return;
            e.target.classList.add('in');
            if (e.target.matches('[data-count]')) count(e.target);
            io.unobserve(e.target);
        });
    }, { rootMargin: '0px 0px -12% 0px', threshold: 0.08 }) : null;
    // Children of [data-stagger] come in one after another.
    $$('[data-stagger]').forEach(g => Array.from(g.children).forEach((c, i) => { if (!c.hasAttribute('data-reveal')) c.setAttribute('data-reveal', g.dataset.stagger || 'up'); c.style.setProperty('--d', (i % 6) * 0.11 + 's'); }));
    $$('[data-reveal], .split:not(.hero .split), [data-count]').forEach(el => io ? io.observe(el) : el.classList.add('in'));
    if (!io) $$('[data-count]').forEach(count);

    function count(el) {
        if (el.dataset.counted) return; el.dataset.counted = '1';
        const raw = el.textContent.trim(), m = raw.match(/^([^0-9]*)([0-9][0-9,.]*)(.*)$/);
        if (!m || reduce) return;
        const target = parseFloat(m[2].replace(/,/g, '')), dec = (m[2].split('.')[1] || '').length, t0 = performance.now(), d = 1800;
        const step = t => {
            const p = Math.min(1, (t - t0) / d), v = target * (1 - Math.pow(1 - p, 3));
            el.textContent = m[1] + v.toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec }) + m[3];
            if (p < 1) requestAnimationFrame(step); else el.textContent = raw;
        };
        requestAnimationFrame(step);
    }

    // ── Parallax: pictures drift a little slower than the page ──
    const px = reduce ? [] : $$('[data-parallax]');
    function parallax() {
        const h = window.innerHeight;
        px.forEach(el => {
            const r = (el.parentElement || el).getBoundingClientRect();
            if (r.bottom < -100 || r.top > h + 100) return;
            const speed = parseFloat(el.dataset.parallax) || 0.12;
            // Never further than the extra height the picture has, so its frame never shows an edge.
            const room = Math.max(0, (el.offsetHeight - (el.parentElement || el).clientHeight) / 2);
            const off = Math.max(-room, Math.min(room, (r.top + r.height / 2 - h / 2) * -speed));
            el.style.transform = 'translate3d(0,' + off.toFixed(1) + 'px,0)';
        });
    }
    onScroll();

    // ── Carousels: arrows, drag with the mouse, progress line ──
    $$('[data-rail]').forEach(wrap => {
        const rail = $('.rail', wrap), prev = $('[data-rail-prev]', wrap), next = $('[data-rail-next]', wrap), bar = $('.rail-bar span', wrap);
        if (!rail) return;
        const stepBy = () => { const c = rail.children[0]; return c ? c.getBoundingClientRect().width + parseFloat(getComputedStyle(rail).columnGap || 24) : rail.clientWidth; };
        const update = () => {
            const max = rail.scrollWidth - rail.clientWidth;
            if (prev) prev.disabled = rail.scrollLeft <= 4;
            if (next) next.disabled = rail.scrollLeft >= max - 4;
            if (bar) { const w = Math.max(10, rail.clientWidth / rail.scrollWidth * 100); bar.style.width = w + '%'; bar.style.left = (max > 0 ? rail.scrollLeft / max * (100 - w) : 0) + '%'; }
        };
        if (prev) prev.addEventListener('click', () => rail.scrollBy({ left: -stepBy(), behavior: 'smooth' }));
        if (next) next.addEventListener('click', () => rail.scrollBy({ left: stepBy(), behavior: 'smooth' }));
        rail.addEventListener('scroll', () => requestAnimationFrame(update), { passive: true });
        window.addEventListener('resize', update); update();
        let down = false, sx = 0, sl = 0, moved = false;
        rail.addEventListener('pointerdown', e => { if (e.pointerType !== 'mouse') return; down = true; moved = false; sx = e.clientX; sl = rail.scrollLeft; });
        window.addEventListener('pointermove', e => { if (!down) return; const dx = e.clientX - sx; if (Math.abs(dx) > 5) { moved = true; rail.classList.add('dragging'); } rail.scrollLeft = sl - dx; });
        window.addEventListener('pointerup', () => { if (!down) return; down = false; setTimeout(() => rail.classList.remove('dragging'), 0); });
        rail.addEventListener('click', e => { if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }, true);
    });

    // ── Guest reviews: one at a time ──
    $$('[data-quotes]').forEach(box => {
        const qs = $$('.quote', box), dots = $$('.quote-dots button', box.parentElement);
        if (qs.length < 2) { qs.forEach(q => q.classList.add('on')); return; }
        let at = 0, t;
        const go = n => { qs[at].classList.remove('on'); dots[at] && dots[at].classList.remove('on'); at = (n + qs.length) % qs.length; qs[at].classList.add('on'); dots[at] && dots[at].classList.add('on'); clearTimeout(t); if (!reduce) t = setTimeout(() => go(at + 1), 7000); };
        dots.forEach((d, i) => d.addEventListener('click', () => go(i)));
        go(0);
    });

    // ── Gallery: filters and a lightbox (keys, swipe) ──
    $$('[data-filters]').forEach(f => {
        const grid = $(f.dataset.filters);
        $$('button', f).forEach(b => b.addEventListener('click', () => {
            $$('button', f).forEach(x => x.classList.toggle('on', x === b));
            $$('figure', grid).forEach((fig, i) => {
                const show = !b.dataset.cat || fig.dataset.cat === b.dataset.cat;
                fig.classList.toggle('hide', !show);
                if (show && !reduce) { fig.animate([{ opacity: 0, transform: 'translateY(24px)' }, { opacity: 1, transform: 'none' }], { duration: 700, delay: (i % 8) * 50, easing: 'cubic-bezier(.2,.7,.1,1)', fill: 'backwards' }); }
            });
        }));
    });
    const lb = $('.lightbox');
    let group = [], gi = 0;
    function showLb() { const f = group[gi]; if (!f) return; const img = $('img', lb); img.src = f.dataset.full || $('img', f).src; img.alt = $('img', f).alt; $('.cap', lb).textContent = f.dataset.caption || ''; }
    function closeLightbox() { if (lb && lb.classList.contains('open')) { lb.classList.remove('open'); body.classList.remove('no-scroll'); } }
    if (lb) {
        document.addEventListener('click', e => {
            const f = e.target.closest('[data-lightbox] figure');
            if (!f) return;
            group = $$('figure:not(.hide)', f.closest('[data-lightbox]')); gi = group.indexOf(f);
            showLb(); lb.classList.add('open'); body.classList.add('no-scroll');
        });
        $('.close', lb).addEventListener('click', closeLightbox);
        $('.prev', lb).addEventListener('click', () => { gi = (gi - 1 + group.length) % group.length; showLb(); });
        $('.next', lb).addEventListener('click', () => { gi = (gi + 1) % group.length; showLb(); });
        lb.addEventListener('click', e => { if (e.target === lb) closeLightbox(); });
        document.addEventListener('keydown', e => { if (!lb.classList.contains('open')) return; if (e.key === 'ArrowLeft') $('.prev', lb).click(); if (e.key === 'ArrowRight') $('.next', lb).click(); });
        let sx = null;
        lb.addEventListener('touchstart', e => { sx = e.touches[0].clientX; }, { passive: true });
        lb.addEventListener('touchend', e => { if (sx === null) return; const dx = e.changedTouches[0].clientX - sx; if (Math.abs(dx) > 50) $(dx < 0 ? '.next' : '.prev', lb).click(); sx = null; });
    }

    // ── Leaving for another page of the site: a short curtain ──
    if (!reduce) {
        document.addEventListener('click', e => {
            const a = e.target.closest('a[href]');
            if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0 || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-book')) return;
            const url = new URL(a.href, location.href);
            if (url.origin !== location.origin || (url.pathname === location.pathname && url.hash) || url.href === location.href) return;
            e.preventDefault();
            const c = document.createElement('div'); c.className = 'curtain leave'; body.appendChild(c);
            setTimeout(() => { location.href = url.href; }, 480);
        });
        window.addEventListener('pageshow', e => { if (e.persisted) $$('.curtain.leave').forEach(c => c.remove()); });
    }

    // One tap = one send: lock a form's button once it is sent.
    document.addEventListener('submit', e => {
        const f = e.target; if (f.dataset.sent) { e.preventDefault(); return; }
        f.dataset.sent = '1'; $$('button[type=submit]', f).forEach(b => { b.disabled = true; });
    });
    window.addEventListener('pageshow', () => $$('form[data-sent]').forEach(f => { delete f.dataset.sent; $$('button', f).forEach(b => b.disabled = false); }));
})();
