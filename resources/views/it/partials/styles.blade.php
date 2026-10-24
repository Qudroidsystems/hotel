<style>
    .it-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 18px; }
    .it-tile { border-radius: 14px; padding: 16px 18px; background: var(--cb-card-bg, #fff); border: 1px solid var(--cb-border, #e9ebec); position: relative; overflow: hidden; }
    .it-tile::before { content: ''; position: absolute; inset: 0 auto 0 0; width: 5px; background: var(--tile, #16a34a); }
    .it-tile .t-head { display: flex; align-items: center; gap: 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--bs-secondary-color, #6c757d); font-weight: 600; }
    .it-tile .t-head i { font-size: 18px; color: var(--tile); }
    .it-tile .t-main { font-size: 22px; font-weight: 700; color: var(--cb-heading); margin: 6px 0 2px; }
    .it-tile .t-sub { font-size: 12.5px; color: var(--bs-secondary-color, #6c757d); }
    .it-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: var(--dot, #94a3b8); flex: 0 0 auto; }
    .it-dot.pulse { box-shadow: 0 0 0 0 rgba(220, 38, 38, .6); animation: itPulse 1.6s infinite; }
    @keyframes itPulse { 0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, .55); } 70% { box-shadow: 0 0 0 8px rgba(220, 38, 38, 0); } 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); } }
    .it-bars { display: flex; gap: 2px; align-items: flex-end; height: 22px; }
    .it-bars span { flex: 1 1 0; min-width: 3px; max-width: 8px; height: 100%; border-radius: 2px; background: #e2e8f0; }
    .it-bars span.up { background: #22c55e; } .it-bars span.degraded { background: #f59e0b; } .it-bars span.down { background: #dc2626; }
    .it-bars span.maintenance { background: #38bdf8; } .it-bars span.unknown { background: #cbd5e1; }
    .it-mon { display: grid; grid-template-columns: minmax(0, 2.2fr) minmax(0, 1fr) 90px 80px; gap: 12px; align-items: center; padding: 10px 16px; border-bottom: 1px solid var(--cb-border, #e9ebec); }
    .it-mon:last-child { border-bottom: 0; }
    .it-mon .nm { font-weight: 600; color: var(--cb-heading); }
    .it-mon .tg { font-size: 12px; color: var(--bs-secondary-color, #6c757d); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .it-mon .num { text-align: right; font-size: 13px; }
    .it-sec { padding: 8px 16px; font-size: 11.5px; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; color: var(--bs-secondary-color, #6c757d); background: var(--cb-subtle-bg, rgba(148, 163, 184, .08)); }
    .it-alert { display: flex; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--cb-border, #e9ebec); }
    .it-alert:last-child { border-bottom: 0; }
    .it-strip { display: flex; gap: 1px; height: 34px; }
    .it-strip span { flex: 1 1 0; border-radius: 2px; background: #e2e8f0; }
    @media (max-width: 767.98px) {
        .it-mon { grid-template-columns: minmax(0, 1fr) 70px; }
        .it-mon .hide-sm { display: none; }
    }
</style>
