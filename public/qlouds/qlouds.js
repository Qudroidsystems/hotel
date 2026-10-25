/* The Qlouds splash (resources/views/partials/qlouds-splash.blade.php): plays once per browser session,
   about 3 seconds, and any click or key skips it. The page keeps loading underneath. */
(function () {
    var el = document.getElementById('qlouds-splash');
    if (!el) return;
    var key = el.getAttribute('data-key') || 'qlouds-splash';
    try { sessionStorage.setItem(key, '1'); } catch (e) {}
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var gone = false;
    function hide() {
        if (gone) return; gone = true;
        el.classList.add('done');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 600);
    }
    setTimeout(hide, reduced ? 900 : 3200);
    el.addEventListener('click', hide);
    document.addEventListener('keydown', hide, { once: true });
})();
