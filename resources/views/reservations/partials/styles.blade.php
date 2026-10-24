@include('rooms.partials.styles')
@once
<style>
.rs-status { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap;
    color: var(--st); background: color-mix(in srgb, var(--st) 12%, transparent); border:1px solid color-mix(in srgb, var(--st) 30%, transparent); }
.rs-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
.rs-tab { display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:12px; font-size:13px; font-weight:700; text-decoration:none;
    background: var(--cb-surface); border:1.5px solid var(--cb-border); color: var(--cb-text); }
.rs-tab .n { background: var(--cb-surface-2); color: var(--cb-heading); border-radius:20px; padding:1px 9px; font-size:12px; }
.rs-tab.active { background: var(--cb-navy); border-color: var(--cb-navy); color:#fff; }
.rs-tab.active .n { background: rgba(255,255,255,.18); color:#fff; }
.rs-guest { font-weight:700; color: var(--cb-heading); }
.rs-ref { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:12px; font-weight:700; color: var(--cb-navy-2); background: var(--cb-surface-2); border:1px solid var(--cb-border); border-radius:6px; padding:1px 7px; }
.rs-room-chip { display:inline-flex; align-items:center; gap:4px; font-size:11.5px; font-weight:700; border-radius:7px; padding:2px 8px; background: var(--cb-surface-2); border:1px solid var(--cb-border); color: var(--cb-heading); margin:0 3px 3px 0; }
.rs-room-chip.unassigned { border-style:dashed; color: var(--cb-muted); font-weight:600; }
.rs-step { font-size:11px; font-weight:800; letter-spacing:.6px; text-transform:uppercase; color: var(--cb-teal); margin-bottom:4px; }
.rs-type-row { display:grid; grid-template-columns: 1fr auto; gap:10px; align-items:center; padding:14px 16px; border:1.5px solid var(--cb-border); border-radius:12px; margin-bottom:10px; background: var(--cb-surface); }
.rs-type-row.soldout { opacity:.55; }
.rs-type-row .nm { font-weight:800; color: var(--cb-heading); }
.rs-qty { display:flex; align-items:center; gap:6px; }
.rs-qty button { width:32px; height:32px; border-radius:9px; border:1.5px solid var(--cb-border); background: var(--cb-surface); font-weight:800; color: var(--cb-heading); }
.rs-qty button:disabled { opacity:.4; }
.rs-qty span { min-width:24px; text-align:center; font-weight:800; font-size:16px; }
.rs-line { border:1px dashed var(--cb-border); border-radius:10px; padding:10px 12px; margin-bottom:8px; background: var(--cb-surface-2); }
.rs-summary { position: sticky; top: 90px; }
.rs-total { font-size:26px; font-weight:800; color: var(--cb-heading); }
.rs-guest-hit { display:flex; justify-content:space-between; gap:10px; width:100%; text-align:left; padding:9px 12px; border:none; background:none; border-bottom:1px solid var(--cb-border); }
.rs-guest-hit:hover { background: var(--cb-hover); }
.rs-actions { display:flex; flex-wrap:wrap; gap:8px; }
.rs-actions form { margin:0; }
</style>
@endonce
