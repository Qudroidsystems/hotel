@once
<style>
/* ── Rooms module ─────────────────────────────────────────────── */
.rm-status { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap;
    color: var(--st); background: color-mix(in srgb, var(--st) 12%, transparent); border:1px solid color-mix(in srgb, var(--st) 30%, transparent); }
.rm-chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:18px; }
.rm-chip { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:12px; font-size:12.5px; font-weight:600; text-decoration:none;
    background: var(--cb-surface); border:1.5px solid var(--cb-border); color: var(--cb-text); transition: all .15s; }
.rm-chip .dot { width:10px; height:10px; border-radius:50%; background: var(--st, var(--cb-muted)); }
.rm-chip .n { font-weight:800; color: var(--cb-heading); font-variant-numeric: tabular-nums; }
.rm-chip:hover { border-color: var(--st, var(--cb-teal)); color: var(--cb-heading); transform: translateY(-1px); }
.rm-chip.active { border-color: var(--st, var(--cb-teal)); background: color-mix(in srgb, var(--st, #0d9488) 10%, var(--cb-surface)); }

.rm-floor { margin-bottom: 18px; }
.rm-floor-head { display:flex; align-items:center; gap:10px; font-size:12px; font-weight:800; letter-spacing:.6px; text-transform:uppercase; color: var(--cb-muted); margin-bottom:10px; }
.rm-floor-head::after { content:''; flex:1; height:1px; background: var(--cb-border); }
.rm-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap:10px; }
.rm-tile { position:relative; border-radius:12px; padding:12px 12px 10px; background: var(--cb-surface); border:1.5px solid var(--cb-border); cursor:pointer; text-align:left;
    transition: transform .15s, box-shadow .15s, border-color .15s; border-top: 4px solid var(--st); width:100%; color: inherit; }
.rm-tile:hover { transform: translateY(-2px); box-shadow: var(--cb-shadow); border-color: var(--st); }
.rm-tile:focus-visible { outline: 3px solid color-mix(in srgb, var(--st) 45%, transparent); outline-offset: 2px; }
.rm-tile .num { font-size:20px; font-weight:800; color: var(--cb-heading); line-height:1.1; font-variant-numeric: tabular-nums; }
.rm-tile .type { font-size:11px; color: var(--cb-muted); font-weight:600; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.rm-tile .st { display:flex; align-items:center; gap:4px; margin-top:8px; font-size:11px; font-weight:700; color: var(--st); }
.rm-tile .type-dot { position:absolute; top:10px; right:10px; width:8px; height:8px; border-radius:50%; }
.rm-tile.inactive { opacity:.5; border-style:dashed; }

.rm-type-card { border:1px solid var(--cb-border); border-radius: var(--cb-radius); overflow:hidden; background: var(--cb-surface); height:100%; display:flex; flex-direction:column; box-shadow: var(--cb-shadow); }
.rm-type-cover { height:150px; background: linear-gradient(135deg, var(--tc, #405189), color-mix(in srgb, var(--tc, #405189) 55%, #0d9488)); position:relative; display:flex; align-items:center; justify-content:center; color:#fff; }
.rm-type-cover img { width:100%; height:100%; object-fit:cover; }
.rm-type-cover .code { position:absolute; top:10px; left:10px; background: rgba(15,35,66,.75); color:#fff; font-size:11px; font-weight:700; border-radius:6px; padding:2px 8px; }
.rm-type-cover .inactive-tag { position:absolute; top:10px; right:10px; background:#475569; color:#fff; font-size:11px; font-weight:700; border-radius:6px; padding:2px 8px; }
.rm-rate { font-size:22px; font-weight:800; color: var(--cb-heading); }
.rm-rate small { font-size:12px; font-weight:600; color: var(--cb-muted); }
.rm-amenity { display:inline-block; font-size:11px; padding:2px 8px; border-radius:20px; background: var(--cb-surface-2); border:1px solid var(--cb-border); color: var(--cb-text); margin:0 4px 4px 0; }
.rm-meta { display:grid; grid-template-columns: 140px 1fr; gap:8px 14px; font-size:13px; }
.rm-meta dt { color: var(--cb-muted); font-weight:600; }
.rm-meta dd { margin:0; color: var(--cb-heading); font-weight:600; }
.rm-photos { display:grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap:10px; }
.rm-photos .ph { position:relative; border-radius:10px; overflow:hidden; aspect-ratio: 4/3; background: var(--cb-surface-2); }
.rm-photos .ph img { width:100%; height:100%; object-fit:cover; }
.rm-photos .ph button { position:absolute; top:6px; right:6px; border:none; border-radius:8px; background: rgba(220,38,38,.9); color:#fff; padding:2px 7px; font-size:13px; }
.rm-form label { font-weight:600; font-size:13px; margin-bottom:4px; }
.rm-form .form-text { font-size:12px; }
.rm-amenity-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap:6px 14px; }
.rm-timeline { list-style:none; padding:0; margin:0; }
.rm-timeline li { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid var(--cb-border); }
.rm-timeline li:last-child { border-bottom:none; }
.rm-timeline .ic { width:34px; height:34px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:#fff; background: var(--st); }
@media (max-width: 576px) { .rm-grid { grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); } .rm-meta { grid-template-columns: 1fr; } }
</style>
@endonce
