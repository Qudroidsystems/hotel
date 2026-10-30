@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<style>
    /* ── The cockpit: dark panels, glowing displays, lamps ───────────────── */
    .ck { --bg: #07090c; --panel: #11151b; --panel2: #0b0e13; --bezel: #262c35; --edge: #3a424e; --ink: #d7dee8; --dim: #7c8796;
          --green: #39ff88; --amber: #ffb000; --red: #ff3b3b; --cyan: #33d6ff; --white: #f2f6fb; --glow: 0 0 8px;
          --lcd: 'Share Tech Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; --label: 'Rajdhani', 'Inter', system-ui, sans-serif;
          background: radial-gradient(1200px 500px at 50% -200px, #1a2029 0%, var(--bg) 70%); color: var(--ink); border-radius: 18px; padding: 14px;
          border: 1px solid #1c2129; box-shadow: inset 0 1px 0 rgba(255,255,255,.04), 0 20px 50px rgba(0,0,0,.35); font-family: var(--label); position: relative; }
    .ck:fullscreen { border-radius: 0; overflow: auto; padding: 18px; }
    .ck * { box-sizing: border-box; }
    .ck button { font-family: var(--label); }
    .ck-panel { background: linear-gradient(180deg, var(--panel) 0%, var(--panel2) 100%); border: 1px solid var(--bezel); border-radius: 14px; padding: 12px 12px 14px; position: relative;
                box-shadow: inset 0 1px 0 rgba(255,255,255,.05), inset 0 -2px 0 rgba(0,0,0,.5), 0 6px 18px rgba(0,0,0,.35); min-width: 0; }
    /* Screws in the bezel corners */
    .ck-panel:before, .ck-panel:after { content: ""; position: absolute; top: 7px; width: 7px; height: 7px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #59616d, #1d2229 70%); box-shadow: 0 0 0 1px #0a0c10; }
    .ck-panel:before { left: 7px; } .ck-panel:after { right: 7px; }
    .ck-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 0 12px 10px; font-size: 12px; letter-spacing: .22em; text-transform: uppercase; color: var(--dim); font-weight: 700; }
    .ck-h b { color: var(--white); font-weight: 700; }
    .ck-h .r { display: flex; gap: 6px; align-items: center; letter-spacing: .08em; }

    .ck-glare { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 12px; padding: 10px 14px; margin-bottom: 12px; border-radius: 14px;
                background: linear-gradient(180deg, #1b2028, #0e1116); border: 1px solid var(--bezel); box-shadow: 0 8px 20px rgba(0,0,0,.4), inset 0 1px 0 rgba(255,255,255,.06); }
    .ck-brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .ck-brand .ic { width: 38px; height: 38px; border-radius: 10px; display: grid; place-items: center; background: #0a0d11; border: 1px solid var(--edge); color: var(--green); font-size: 20px; text-shadow: var(--glow) var(--green); }
    .ck-brand .t { font-weight: 700; letter-spacing: .2em; font-size: 14px; color: var(--white); text-transform: uppercase; }
    .ck-brand .s { font-size: 11px; color: var(--dim); letter-spacing: .14em; text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ck-master { display: flex; gap: 10px; }
    .ck-mbtn { width: 112px; height: 52px; border-radius: 9px; border: 2px solid #000; font-weight: 700; font-size: 12.5px; letter-spacing: .12em; line-height: 1.05; cursor: pointer; text-transform: uppercase;
               background: #2a1313; color: #5e2a2a; box-shadow: inset 0 2px 0 rgba(255,255,255,.08), 0 3px 0 #000; transition: transform .08s; }
    .ck-mbtn.caution { background: #2a2210; color: #5e4c1e; }
    .ck-mbtn:active { transform: translateY(2px); box-shadow: inset 0 2px 0 rgba(255,255,255,.08), 0 1px 0 #000; }
    .ck-mbtn.on { background: var(--red); color: #fff; text-shadow: 0 0 6px #fff; box-shadow: 0 0 22px rgba(255,59,59,.65), inset 0 2px 0 rgba(255,255,255,.3), 0 3px 0 #000; }
    .ck-mbtn.caution.on { background: var(--amber); color: #1b1300; text-shadow: none; box-shadow: 0 0 22px rgba(255,176,0,.6), inset 0 2px 0 rgba(255,255,255,.3), 0 3px 0 #000; }
    .ck-mbtn.on.flash { animation: ckFlash 1s steps(2, start) infinite; }
    .ck-right { display: flex; justify-content: flex-end; align-items: center; gap: 8px; flex-wrap: wrap; }
    .ck-clock { font-family: var(--lcd); font-size: 24px; color: var(--green); text-shadow: var(--glow) rgba(57,255,136,.6); background: #050706; border: 1px solid #1e2a22; border-radius: 8px; padding: 2px 10px; letter-spacing: .06em; }
    .ck-clock small { font-size: 11px; color: var(--dim); text-shadow: none; margin-left: 6px; letter-spacing: .1em; }
    .ck-chip { font-family: var(--lcd); font-size: 11.5px; letter-spacing: .1em; padding: 4px 8px; border-radius: 6px; border: 1px solid var(--edge); color: var(--dim); background: #0a0d11; display: inline-flex; align-items: center; gap: 6px; }
    .ck-chip i.d { width: 7px; height: 7px; border-radius: 50%; background: #333; display: inline-block; }
    .ck-chip.ok i.d { background: var(--green); box-shadow: var(--glow) var(--green); } .ck-chip.warn i.d { background: var(--amber); box-shadow: var(--glow) var(--amber); } .ck-chip.bad i.d { background: var(--red); box-shadow: var(--glow) var(--red); }
    .ck-key { border: 1px solid var(--edge); background: linear-gradient(180deg, #252b34, #171b21); color: var(--ink); border-radius: 8px; padding: 6px 10px; font-weight: 700; font-size: 12px; letter-spacing: .1em; text-transform: uppercase; cursor: pointer;
              box-shadow: inset 0 1px 0 rgba(255,255,255,.08), 0 2px 0 #000; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
    .ck-key:hover { border-color: #56606e; color: var(--white); }
    .ck-key:active { transform: translateY(1px); box-shadow: inset 0 1px 0 rgba(255,255,255,.08); }
    .ck-key:disabled { opacity: .35; cursor: not-allowed; }
    .ck-key.go { color: var(--green); border-color: #1f6b42; } .ck-key.go:hover { background: #0f2a1b; }
    .ck-key.stop { color: var(--red); border-color: #6b1f1f; } .ck-key.stop:hover { background: #2a0f0f; }
    .ck-key.mon { color: var(--cyan); border-color: #1f566b; } .ck-key.xfer { color: var(--amber); border-color: #6b551f; }
    .ck-key.lit { background: #0f2a1b; box-shadow: 0 0 12px rgba(57,255,136,.35), inset 0 1px 0 rgba(255,255,255,.08); }

    .ck-annun { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 6px; margin-bottom: 12px; padding: 10px 12px 12px; }
    .ck-lamp { font-family: var(--lcd); font-size: 11.5px; text-align: center; letter-spacing: .08em; padding: 7px 4px; border-radius: 5px; background: #0b0d10; color: #3a414b; border: 1px solid #1d222a;
               box-shadow: inset 0 2px 4px rgba(0,0,0,.7); text-transform: uppercase; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; user-select: none; }
    .ck-lamp.g { color: var(--green); text-shadow: var(--glow) var(--green); background: #07160e; border-color: #144a2c; }
    .ck-lamp.a { color: var(--amber); text-shadow: var(--glow) var(--amber); background: #1a1203; border-color: #5a420e; }
    .ck-lamp.r { color: #ffd6d6; text-shadow: var(--glow) var(--red); background: #3a0909; border-color: #8a1c1c; }
    .ck-lamp.c { color: var(--cyan); text-shadow: var(--glow) var(--cyan); background: #04161c; border-color: #10485a; }
    .ck-lamp.flash { animation: ckFlash 1s steps(2, start) infinite; }

    .ck-grid { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1.25fr) minmax(0, .9fr); gap: 12px; margin-bottom: 12px; }
    .ck-col { display: grid; gap: 12px; align-content: start; min-width: 0; }

    /* Call lines: each like a radio in the stack */
    .ck-lines { display: grid; gap: 8px; max-height: 560px; overflow: auto; padding: 0 2px 2px; }
    .ck-line { background: #0a0d11; border: 1px solid #222832; border-radius: 10px; padding: 9px 10px; display: grid; gap: 7px; position: relative; }
    .ck-line.ringing { border-color: #5a420e; } .ck-line.escalated { border-color: #8a1c1c; box-shadow: 0 0 0 1px rgba(255,59,59,.3), 0 0 18px rgba(255,59,59,.18); }
    .ck-line.active { border-color: #144a2c; } .ck-line.mine { box-shadow: 0 0 0 1px var(--green), 0 0 16px rgba(57,255,136,.2); }
    .ck-line .top { display: flex; align-items: center; gap: 8px; min-width: 0; }
    .ck-line .ch { font-family: var(--lcd); font-size: 11px; color: var(--dim); letter-spacing: .12em; }
    .ck-line .st { font-family: var(--lcd); font-size: 11px; padding: 2px 6px; border-radius: 4px; letter-spacing: .1em; }
    .ck-line .st.ring { color: var(--amber); background: #1a1203; } .ck-line .st.esc { color: #fff; background: #8a1c1c; animation: ckFlash 1s steps(2, start) infinite; }
    .ck-line .st.on { color: var(--green); background: #07160e; } .ck-line .st.xfer { color: var(--cyan); background: #04161c; }
    .ck-line .who { min-width: 0; flex: 1; }
    .ck-line .who b { color: var(--white); font-size: 15px; font-weight: 700; letter-spacing: .02em; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ck-line .who span { color: var(--dim); font-size: 12px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ck-line .seg { font-family: var(--lcd); font-size: 26px; color: var(--green); text-shadow: var(--glow) rgba(57,255,136,.5); line-height: 1; min-width: 74px; text-align: right; }
    .ck-line.ringing .seg { color: var(--amber); text-shadow: var(--glow) rgba(255,176,0,.5); } .ck-line.escalated .seg { color: var(--red); text-shadow: var(--glow) rgba(255,59,59,.6); }
    .ck-line canvas { width: 100%; height: 34px; display: block; background: #050706; border-radius: 6px; border: 1px solid #141a16; }
    .ck-line .keys { display: flex; gap: 6px; flex-wrap: wrap; }
    .ck-line .meta { font-size: 11.5px; color: var(--dim); display: flex; gap: 10px; flex-wrap: wrap; }
    .ck-line .meta .l { color: var(--cyan); }
    .ck-vip { font-family: var(--lcd); font-size: 10px; color: #1b1300; background: var(--amber); padding: 1px 5px; border-radius: 3px; margin-left: 6px; letter-spacing: .1em; vertical-align: middle; }
    .ck-empty { text-align: center; padding: 26px 10px; color: var(--dim); font-family: var(--lcd); letter-spacing: .14em; font-size: 12px; }
    .ck-empty i { font-size: 30px; display: block; margin-bottom: 6px; color: #2c333d; }

    /* The audio display */
    .ck-scope { width: 100%; height: 190px; display: block; background: #030504; border-radius: 10px; border: 1px solid #16201a; }
    .ck-read { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin-top: 8px; }
    .ck-read div { background: #050706; border: 1px solid #16201a; border-radius: 7px; padding: 5px 8px; }
    .ck-read small { display: block; font-size: 10px; letter-spacing: .16em; color: var(--dim); text-transform: uppercase; }
    .ck-read b { font-family: var(--lcd); font-weight: 400; font-size: 18px; color: var(--green); text-shadow: var(--glow) rgba(57,255,136,.4); white-space: nowrap; }
    .ck-mfdbar { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; align-items: center; }
    .ck-mfdbar .grow { flex: 1; min-width: 120px; font-size: 12px; color: var(--dim); }
    .ck-mfdbar input[type=range] { accent-color: var(--green); width: 110px; }
    .ck-legend { display: flex; gap: 12px; font-size: 11px; color: var(--dim); letter-spacing: .08em; margin-top: 6px; flex-wrap: wrap; }
    .ck-legend i { display: inline-block; width: 18px; height: 3px; border-radius: 2px; vertical-align: middle; margin-right: 5px; }

    /* Network */
    .ck-net { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 12px; align-items: center; }
    .ck-gauge svg { width: 150px; height: 100px; display: block; }
    .ck-links { display: grid; gap: 6px; max-height: 210px; overflow: auto; }
    .ck-link { display: grid; grid-template-columns: 26px minmax(0, 1fr) 70px 54px; gap: 8px; align-items: center; background: #0a0d11; border: 1px solid #1d222a; border-radius: 8px; padding: 5px 8px; }
    .ck-link .nm { font-size: 12.5px; font-weight: 600; color: var(--white); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ck-link .nm small { display: block; color: var(--dim); font-weight: 500; font-size: 11px; }
    .ck-link .ms { font-family: var(--lcd); font-size: 13px; text-align: right; color: var(--green); }
    .ck-bars { display: inline-flex; gap: 2px; align-items: flex-end; height: 16px; }
    .ck-bars b { display: block; width: 4px; border-radius: 1px; background: #2a313b; }
    .ck-bars b:nth-child(1) { height: 5px; } .ck-bars b:nth-child(2) { height: 8px; } .ck-bars b:nth-child(3) { height: 12px; } .ck-bars b:nth-child(4) { height: 16px; }
    .ck-bars[data-n="1"] b:nth-child(-n+1) { background: var(--red); } .ck-bars[data-n="2"] b:nth-child(-n+2) { background: var(--amber); }
    .ck-bars[data-n="3"] b:nth-child(-n+3), .ck-bars[data-n="4"] b { background: var(--green); }
    .ck-spark { width: 70px; height: 22px; }

    /* Weather */
    .ck-wx-now { display: grid; grid-template-columns: auto 1fr; gap: 12px; align-items: center; }
    .ck-wx-now .ic { font-size: 46px; color: var(--amber); text-shadow: 0 0 14px rgba(255,176,0,.45); line-height: 1; }
    .ck-wx-now .tp { font-family: var(--lcd); font-size: 44px; color: var(--white); line-height: 1; }
    .ck-wx-now .tp small { font-size: 16px; color: var(--dim); }
    .ck-wx-now .sm { color: var(--dim); font-size: 12.5px; letter-spacing: .04em; }
    .ck-dials { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin-top: 10px; }
    .ck-dial { text-align: center; background: #050706; border: 1px solid #16201a; border-radius: 9px; padding: 4px 2px 6px; }
    .ck-dial svg { width: 100%; max-width: 92px; height: 58px; display: block; margin: 0 auto; }
    .ck-dial small { font-size: 10px; letter-spacing: .16em; color: var(--dim); text-transform: uppercase; }
    .ck-hours { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(46px, 1fr); gap: 4px; overflow-x: auto; margin-top: 10px; padding-bottom: 2px; }
    .ck-hours div { text-align: center; background: #0a0d11; border: 1px solid #1d222a; border-radius: 7px; padding: 4px 2px; font-size: 11px; color: var(--dim); }
    .ck-hours div b { display: block; font-family: var(--lcd); font-weight: 400; color: var(--white); font-size: 14px; }
    .ck-hours div i { font-size: 16px; color: var(--amber); }
    .ck-days { display: grid; gap: 4px; margin-top: 8px; }
    .ck-days div { display: grid; grid-template-columns: 52px 22px minmax(0, 1fr) 70px; gap: 6px; align-items: center; font-size: 12.5px; }
    .ck-days div .rg { height: 5px; border-radius: 3px; background: linear-gradient(90deg, var(--cyan), var(--amber)); opacity: .8; }
    .ck-days div span:last-child { font-family: var(--lcd); text-align: right; color: var(--white); }

    /* Systems */
    .ck-segs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
    .ck-segs div { background: #050706; border: 1px solid #16201a; border-radius: 8px; padding: 5px 6px; text-align: center; }
    .ck-segs b { display: block; font-family: var(--lcd); font-weight: 400; font-size: 26px; line-height: 1.1; }
    .ck-segs small { font-size: 10px; letter-spacing: .16em; color: var(--dim); }
    .ck-alarms { display: grid; gap: 4px; margin-top: 8px; max-height: 190px; overflow: auto; }
    .ck-alarm { display: flex; gap: 8px; align-items: center; font-size: 12.5px; padding: 5px 8px; border-radius: 7px; background: #1a0a0a; border: 1px solid #4a1616; color: #ffd1d1; }
    .ck-alarm.offline { background: #1a1203; border-color: #5a420e; color: #ffe3a3; }
    .ck-alarm i { font-size: 15px; } .ck-alarm span { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .ck-lower { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; margin-bottom: 12px; }
    .ck-list { display: grid; gap: 5px; max-height: 260px; overflow: auto; }
    .ck-row { display: grid; grid-template-columns: 28px minmax(0, 1fr) auto; gap: 8px; align-items: center; padding: 6px 8px; border-radius: 8px; background: #0a0d11; border: 1px solid #1d222a; font-size: 12.5px; }
    .ck-row .nm { min-width: 0; } .ck-row .nm b { color: var(--white); display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 600; }
    .ck-row .nm span { color: var(--dim); font-size: 11.5px; }
    .ck-row .ic { width: 28px; height: 28px; border-radius: 7px; display: grid; place-items: center; background: #11161c; color: var(--dim); }
    .ck-row.missed .ic { color: var(--red); } .ck-row.ended .ic { color: var(--green); } .ck-row.unread { border-color: #5a420e; }
    .ck-row .act { display: flex; gap: 5px; align-items: center; }
    .ck-row canvas { width: 80px; height: 22px; background: #050706; border-radius: 4px; }

    /* Equipment synoptic */
    .ck-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
    .ck-tabs button { border: 1px solid var(--edge); background: #0a0d11; color: var(--dim); font-weight: 700; font-size: 11.5px; letter-spacing: .12em; text-transform: uppercase; padding: 5px 10px; border-radius: 7px; cursor: pointer; }
    .ck-tabs button.on { color: #04150c; background: var(--green); border-color: var(--green); box-shadow: 0 0 12px rgba(57,255,136,.35); }
    .ck-tabs button .n { font-family: var(--lcd); margin-left: 4px; }
    .ck-search { background: #050706; border: 1px solid var(--edge); color: var(--white); border-radius: 7px; padding: 5px 9px; font-family: var(--lcd); font-size: 13px; width: 130px; }
    .ck-floor { margin-top: 10px; }
    .ck-floor h6 { font-size: 11px; letter-spacing: .2em; color: var(--dim); text-transform: uppercase; margin: 0 0 6px 2px; font-weight: 700; }
    .ck-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 6px; }
    .ck-tiles.areas { grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); }
    .ck-tile { background: #0a0d11; border: 1px solid #222832; border-radius: 9px; padding: 6px 7px 7px; cursor: pointer; text-align: left; color: var(--ink); position: relative; display: grid; gap: 4px; }
    .ck-tile:hover { border-color: #56606e; }
    .ck-tile .no { display: flex; justify-content: space-between; align-items: center; font-family: var(--lcd); font-size: 16px; color: var(--white); }
    .ck-tile .no small { font-family: var(--label); font-size: 10px; letter-spacing: .12em; color: var(--dim); font-weight: 700; }
    .ck-tile .no small.occ { color: var(--cyan); }
    .ck-tile.alarm { border-color: #8a1c1c; box-shadow: inset 0 0 0 1px rgba(255,59,59,.25); } .ck-tile.waste { border-color: #5a420e; }
    .ck-leds { display: flex; flex-wrap: wrap; gap: 3px; }
    .ck-led { width: 15px; height: 15px; border-radius: 4px; display: grid; place-items: center; font-size: 10px; background: #1a1f26; color: #4b5562; }
    .ck-led.on { background: #0b2a18; color: var(--green); box-shadow: 0 0 6px rgba(57,255,136,.4); }
    .ck-led.fault { background: #3a0909; color: #ff9b9b; box-shadow: 0 0 6px rgba(255,59,59,.55); animation: ckFlash 1.2s steps(2, start) infinite; }
    .ck-led.offline { background: #1a1203; color: var(--amber); }
    .ck-tile .sum { font-size: 10.5px; color: var(--dim); font-family: var(--lcd); letter-spacing: .04em; }
    .ck-legend2 { display: flex; gap: 12px; flex-wrap: wrap; font-size: 11px; color: var(--dim); margin-top: 10px; }
    .ck-legend2 span { display: inline-flex; align-items: center; gap: 5px; }

    .ck-note { font-size: 12px; color: var(--dim); background: #04161c; border: 1px solid #10485a; border-radius: 10px; padding: 8px 12px; margin-bottom: 12px; display: flex; gap: 10px; align-items: flex-start; }
    .ck-note i { color: var(--cyan); font-size: 16px; } .ck-note b { color: var(--cyan); }

    /* Dialogs */
    .ck-dlg { position: fixed; inset: 0; z-index: 2100; background: rgba(0,0,0,.6); display: grid; place-items: center; padding: 16px; }
    .ck-dlg[hidden] { display: none; }
    .ck-dlg .box { width: min(520px, 100%); max-height: calc(100vh - 32px); overflow: auto; background: #10141a; color: var(--ink); border: 1px solid var(--edge); border-radius: 14px; padding: 16px; box-shadow: 0 30px 70px rgba(0,0,0,.6); font-family: var(--label); }
    .ck-dlg h5 { color: var(--white); letter-spacing: .14em; text-transform: uppercase; font-size: 14px; margin: 0 0 10px; display: flex; justify-content: space-between; align-items: center; }
    .ck-dlg label { font-size: 11px; letter-spacing: .14em; color: var(--dim); text-transform: uppercase; display: block; margin: 10px 0 4px; }
    .ck-dlg select, .ck-dlg input[type=text], .ck-dlg textarea { width: 100%; background: #050706; color: var(--white); border: 1px solid var(--edge); border-radius: 8px; padding: 8px 10px; font-size: 14px; font-family: inherit; }
    .ck-dlg .opts { display: grid; gap: 5px; max-height: 300px; overflow: auto; margin-top: 6px; }
    .ck-opt { display: grid; grid-template-columns: 26px minmax(0, 1fr) auto; gap: 8px; align-items: center; padding: 7px 9px; border-radius: 8px; border: 1px solid #222832; background: #0a0d11; cursor: pointer; color: var(--ink); text-align: left; width: 100%; }
    .ck-opt:hover, .ck-opt.sel { border-color: var(--amber); background: #1a1203; }
    .ck-opt b { color: var(--white); font-weight: 600; display: block; } .ck-opt small { color: var(--dim); font-size: 11.5px; }
    .ck-opt .busy { font-family: var(--lcd); font-size: 10.5px; color: var(--amber); }
    .ck-dev { display: grid; grid-template-columns: 26px minmax(0, 1fr) auto; gap: 8px; align-items: center; padding: 7px 9px; border-radius: 8px; border: 1px solid #222832; background: #0a0d11; margin-bottom: 5px; }
    .ck-dev .pill { font-family: var(--lcd); font-size: 11px; padding: 2px 7px; border-radius: 4px; letter-spacing: .1em; }
    .ck-dev .pill.on { color: var(--green); background: #07160e; } .ck-dev .pill.off { color: var(--dim); background: #161b21; } .ck-dev .pill.fault { color: #fff; background: #8a1c1c; } .ck-dev .pill.offline { color: var(--amber); background: #1a1203; }
    .ck-dev .sw { display: flex; gap: 4px; margin-top: 4px; }
    .ck-dev .sw button { font-size: 10.5px; padding: 3px 7px; }
    .ck-toast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); z-index: 2200; background: #10141a; color: var(--white, #fff); border: 1px solid #3a424e; border-radius: 10px; padding: 9px 14px; font-size: 13.5px; box-shadow: 0 16px 40px rgba(0,0,0,.5); max-width: calc(100vw - 32px); }
    .ck-toast[hidden] { display: none; }

    @keyframes ckFlash { 0% { opacity: 1; } 50% { opacity: .35; } }
    @media (prefers-reduced-motion: reduce) { .ck .flash, .ck-led.fault, .ck-line .st.esc, .ck-mbtn.on.flash { animation: none !important; } }
    @media (max-width: 1399.98px) { .ck-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } .ck-grid .ck-col.c3 { grid-column: 1 / -1; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
    @media (max-width: 991.98px) { .ck-grid, .ck-lower { grid-template-columns: minmax(0, 1fr); } .ck-grid .ck-col.c3 { grid-template-columns: minmax(0, 1fr); } .ck-glare { grid-template-columns: 1fr; } .ck-master, .ck-right { justify-content: center; } }
    @media (max-width: 575.98px) { .ck { padding: 8px; } .ck-net { grid-template-columns: 1fr; } .ck-gauge svg { margin: 0 auto; } .ck-read { grid-template-columns: repeat(2, minmax(0, 1fr)); } .ck-mbtn { width: 96px; } .ck-link { grid-template-columns: 22px minmax(0, 1fr) 54px; } .ck-link .ck-spark { display: none; } }
</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    @include('it.partials.nav')

    <div class="ck" id="ck">
        <header class="ck-glare">
            <div class="ck-brand">
                <div class="ic"><i class="ri-headphone-line"></i></div>
                <div style="min-width:0"><div class="t">Call Console</div><div class="s">{{ $hotelName }} · IT &amp; security operations</div></div>
            </div>
            <div class="ck-master">
                <button type="button" class="ck-mbtn" id="ck-mw" title="Master warning — press to silence">Master<br>warning</button>
                <button type="button" class="ck-mbtn caution" id="ck-mc" title="Master caution — press to acknowledge">Master<br>caution</button>
            </div>
            <div class="ck-right">
                <span class="ck-chip" id="ck-link" title="Connection to the server"><i class="d"></i> LINK</span>
                <span class="ck-chip" id="ck-hs" title="Headset"><i class="d"></i> HEADSET</span>
                @if ($monitoring)<span class="ck-chip ok" title="Listening in is on; callers are told"><i class="d"></i> MON</span>@endif
                <span class="ck-clock" id="ck-clock">--:--:--<small>LCL</small></span>
                <button type="button" class="ck-key" id="ck-settings" title="Headset and sound"><i class="ri-equalizer-line"></i></button>
                <button type="button" class="ck-key" id="ck-full" title="Full screen"><i class="ri-fullscreen-line"></i></button>
            </div>
        </header>

        @if ($monitoring && $canListen)
            <div class="ck-note"><i class="ri-shield-user-line"></i><div><b>Listening in is recorded.</b> Each time you listen you give a reason; your name, the reason and how long you listened go into the audit trail, and the person on the call sees you listening. Callers are told at the start of every call: “{{ $notice }}”</div></div>
        @endif

        <section class="ck-panel ck-annun" id="ck-annun" aria-label="Warning lights"></section>

        <div class="ck-grid">
            <div class="ck-col">
                <section class="ck-panel">
                    <div class="ck-h"><span><b>COMM</b> · call lines</span><span class="r" id="ck-linecount"></span></div>
                    <div class="ck-lines" id="ck-lines"><div class="ck-empty"><i class="ri-phone-line"></i>Connecting…</div></div>
                </section>
            </div>
            <div class="ck-col">
                <section class="ck-panel">
                    <div class="ck-h"><span><b>AUDIO</b> · <span id="ck-mfd-title">standby</span></span><span class="r" id="ck-mfd-q"></span></div>
                    <canvas class="ck-scope" id="ck-scope" width="900" height="190" aria-label="Sound waves of the call you are on or listening to"></canvas>
                    <div class="ck-legend"><span><i style="background:var(--green)"></i>Guest</span><span><i style="background:var(--amber)"></i>Hotel side</span><span id="ck-mfd-note"></span></div>
                    <div class="ck-read">
                        <div><small>Time</small><b id="ck-r-time">--:--</b></div>
                        <div><small>Round trip</small><b id="ck-r-rtt">---</b></div>
                        <div><small>Packet loss</small><b id="ck-r-loss">---</b></div>
                        <div><small>Level</small><b id="ck-r-lvl">---</b></div>
                    </div>
                    <div class="ck-mfdbar" id="ck-mfdbar">
                        <span class="grow" id="ck-mfd-who">Pick up a line, or listen in, to see and hear it here.</span>
                        <label class="small" style="color:var(--dim);display:flex;gap:6px;align-items:center">VOL <input type="range" id="ck-vol" min="0" max="1" step="0.05" value="1" aria-label="Volume"></label>
                    </div>
                </section>
                <section class="ck-panel">
                    <div class="ck-h"><span><b>NET</b> · network strength</span><span class="r" id="ck-net-sum"></span></div>
                    <div class="ck-net">
                        <div class="ck-gauge" id="ck-gauge"></div>
                        <div class="ck-links" id="ck-links"><div class="ck-empty">Loading…</div></div>
                    </div>
                </section>
            </div>
            <div class="ck-col c3">
                <section class="ck-panel">
                    <div class="ck-h"><span><b>WX</b> · weather</span><span class="r" id="ck-wx-sun"></span></div>
                    <div id="ck-wx"><div class="ck-empty">Loading…</div></div>
                </section>
                <section class="ck-panel">
                    <div class="ck-h"><span><b>SYS</b> · equipment</span><span class="r"><a href="{{ route('it.facilities.index') }}" class="ck-key" style="text-decoration:none;padding:3px 8px"><i class="ri-list-settings-line"></i></a></span></div>
                    <div class="ck-segs">
                        <div><b id="ck-s-on" style="color:var(--green)">--</b><small>ON</small></div>
                        <div><b id="ck-s-off" style="color:var(--dim)">--</b><small>OFF</small></div>
                        <div><b id="ck-s-fault" style="color:var(--red)">--</b><small>FAULT</small></div>
                        <div><b id="ck-s-offline" style="color:var(--amber)">--</b><small>NO SIG</small></div>
                    </div>
                    <div class="ck-alarms" id="ck-alarms"></div>
                </section>
            </div>
        </div>

        <div class="ck-lower">
            <section class="ck-panel">
                <div class="ck-h"><span><b>LOG</b> · recent &amp; missed calls</span><span class="r" id="ck-missed"></span></div>
                <div class="ck-list" id="ck-recent"></div>
            </section>
            <section class="ck-panel">
                <div class="ck-h"><span><b>VMS</b> · voice messages</span><span class="r" id="ck-vcount"></span></div>
                <div class="ck-list" id="ck-voice"></div>
            </section>
        </div>

        <section class="ck-panel">
            <div class="ck-h" style="flex-wrap:wrap">
                <span><b>SYNOPTIC</b> · rooms &amp; areas — on / off</span>
                <span class="r" style="flex-wrap:wrap">
                    <span class="ck-tabs" id="ck-btabs">
                        <button type="button" data-v="rooms" class="on">Rooms</button><button type="button" data-v="areas">Other areas</button>
                    </span>
                    <span class="ck-tabs" id="ck-bfilter">
                        <button type="button" data-f="all" class="on">All</button><button type="button" data-f="alarm">Alarms <span class="n" id="ck-f-alarm"></span></button>
                        <button type="button" data-f="occ">Occupied</button><button type="button" data-f="waste">Empty &amp; running <span class="n" id="ck-f-waste"></span></button>
                    </span>
                    <input type="search" class="ck-search" id="ck-bq" placeholder="Room…" aria-label="Find a room">
                </span>
            </div>
            <div id="ck-board"><div class="ck-empty">Loading…</div></div>
            <div class="ck-legend2">
                <span><i class="ck-led on"><i class="ri-checkbox-blank-circle-fill" style="font-size:7px"></i></i> On</span>
                <span><i class="ck-led"><i class="ri-checkbox-blank-circle-fill" style="font-size:7px"></i></i> Off</span>
                <span><i class="ck-led fault" style="animation:none"><i class="ri-checkbox-blank-circle-fill" style="font-size:7px"></i></i> Fault</span>
                <span><i class="ck-led offline"><i class="ri-checkbox-blank-circle-fill" style="font-size:7px"></i></i> No signal from the hardware</span>
                <span style="color:var(--cyan)">OCC = guest in the room</span>
            </div>
        </section>

        {{-- Dialogs --}}
        <div class="ck-dlg" id="ck-dlg-xfer" hidden role="dialog" aria-modal="true" aria-labelledby="ck-xfer-t">
            <div class="box">
                <h5 id="ck-xfer-t"><span><i class="ri-arrow-left-right-line"></i> Put the call through</span><button type="button" class="ck-key" data-close>✕</button></h5>
                <div style="font-size:13px;color:var(--dim)" id="ck-xfer-who"></div>
                <input type="text" id="ck-xfer-q" placeholder="Search people or sections" style="margin-top:10px">
                <div class="opts" id="ck-xfer-list"></div>
                <label for="ck-xfer-note">Note for them (optional)</label>
                <input type="text" id="ck-xfer-note" maxlength="160" placeholder="e.g. Guest asking about a late check-out">
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px"><button type="button" class="ck-key" data-close>Cancel</button><button type="button" class="ck-key xfer" id="ck-xfer-go" disabled><i class="ri-arrow-left-right-line"></i> Put through</button></div>
            </div>
        </div>
        <div class="ck-dlg" id="ck-dlg-listen" hidden role="dialog" aria-modal="true" aria-labelledby="ck-listen-t">
            <div class="box">
                <h5 id="ck-listen-t"><span><i class="ri-headphone-line"></i> Listen in</span><button type="button" class="ck-key" data-close>✕</button></h5>
                <div style="font-size:13px;color:var(--dim)" id="ck-listen-who"></div>
                <label for="ck-listen-why">Why are you listening? (goes in the audit trail)</label>
                <select id="ck-listen-preset"><option value="">Choose…</option><option>Quality check</option><option>Security concern</option><option>Guest complaint follow-up</option><option>Supervising a new staff member</option><option>Emergency</option></select>
                <textarea id="ck-listen-why" rows="2" maxlength="250" placeholder="A few words" style="margin-top:6px"></textarea>
                <div style="font-size:12px;color:var(--dim);margin-top:8px">You will hear both sides; nobody hears you. The person on the call sees that you are listening.</div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px"><button type="button" class="ck-key" data-close>Cancel</button><button type="button" class="ck-key mon" id="ck-listen-go"><i class="ri-headphone-line"></i> Start listening</button></div>
            </div>
        </div>
        <div class="ck-dlg" id="ck-dlg-set" hidden role="dialog" aria-modal="true" aria-labelledby="ck-set-t">
            <div class="box">
                <h5 id="ck-set-t"><span><i class="ri-headphone-line"></i> Headset &amp; sound</span><button type="button" class="ck-key" data-close>✕</button></h5>
                <div style="font-size:13px;color:var(--dim)">Plug in a headset, then choose it here. Calls you pick up use its microphone; calls and listening play in its earpiece.</div>
                <label for="ck-mic">Microphone</label><select id="ck-mic"></select>
                <label for="ck-out">Earpiece / speaker</label><select id="ck-out"></select>
                <label style="display:flex;gap:8px;align-items:center;text-transform:none;letter-spacing:0;font-size:13px;color:var(--ink)"><input type="checkbox" id="ck-ringer"> Ring out loud for calls nobody answers (after {{ $escalate }} s)</label>
                <div style="display:flex;gap:8px;justify-content:space-between;margin-top:14px;flex-wrap:wrap"><button type="button" class="ck-key" id="ck-detect"><i class="ri-search-eye-line"></i> Find my headset</button><button type="button" class="ck-key go" id="ck-test"><i class="ri-volume-up-line"></i> Test sound</button><button type="button" class="ck-key" data-close>Done</button></div>
            </div>
        </div>
        <div class="ck-dlg" id="ck-dlg-dev" hidden role="dialog" aria-modal="true" aria-labelledby="ck-dev-t">
            <div class="box">
                <h5 id="ck-dev-t"><span id="ck-dev-title">Room</span><button type="button" class="ck-key" data-close>✕</button></h5>
                <div id="ck-dev-list"></div>
            </div>
        </div>
        <div class="ck-toast" id="ck-toast" hidden role="status"></div>
        <audio id="ck-a-call" autoplay playsinline></audio>
        <audio id="ck-a-listen" autoplay playsinline></audio>
        <audio id="ck-a-voice" playsinline></audio>
    </div>

</div>
</div>
</div>
<script src="{{ asset('js/hotel-live.js') }}?v={{ @filemtime(public_path('js/hotel-live.js')) }}"></script>
<script>
(() => {
    const BASE = @json(url('/it/console'));
    const STATE_URL = @json(url('/it/equipment-board')) + '/';
    const CSRF = document.querySelector('meta[name=csrf-token]')?.content || @json(csrf_token());
    const CAN = { answer: @json($canAnswer), listen: @json($canListen), equipment: @json($canEquipment) };
    const ESCALATE = @json($escalate);
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const clock = (s) => (s == null ? '--:--' : Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0'));
    const store = { get: (k, d) => { try { const v = localStorage.getItem('ck.' + k); return v === null ? d : v; } catch (e) { return d; } }, set: (k, v) => { try { localStorage.setItem('ck.' + k, v); } catch (e) {} } };
    const st = { feed: null, board: null, net: null, view: 'rooms', filter: 'all', q: '', call: null, callInfo: null, listen: null, listenInfo: null, q3: null, ack: { w: '', c: '' }, ringStop: null, seenRing: {}, lineLevels: {}, scope: { them: null, me: null, ctx: null } };

    async function api(path, opts = {}) {
        const r = await fetch(path.startsWith('http') ? path : BASE + path, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF } }, opts));
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) throw new Error(j.message || 'That didn’t work. Try again.');
        return j.data;
    }
    function toast(t) { const el = $('ck-toast'); el.textContent = t; el.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, 4200); }
    function chip(id, cls) { const el = $(id); el.classList.remove('ok', 'warn', 'bad'); if (cls) el.classList.add(cls); }

    // ── Clock ───────────────────────────────────────────────────────
    setInterval(() => { const d = new Date(); $('ck-clock').firstChild.nodeValue = d.toLocaleTimeString([], { hour12: false }); }, 1000);

    // ── Headset: microphone and earpiece ─────────────────────────────
    const audios = ['ck-a-call', 'ck-a-listen', 'ck-a-voice'].map($);
    function applySink() {
        const out = store.get('out', '');
        audios.forEach(a => { if (a.setSinkId && out) a.setSinkId(out).catch(() => {}); a.volume = +$('ck-vol').value; });
        chip('ck-hs', store.get('mic', '') || out ? 'ok' : null);
    }
    async function listDevices(ask) {
        if (!navigator.mediaDevices?.enumerateDevices) return;
        if (ask) { try { const s = await navigator.mediaDevices.getUserMedia({ audio: true }); s.getTracks().forEach(t => t.stop()); } catch (e) { toast('The browser didn’t allow the microphone.'); } }
        const list = await navigator.mediaDevices.enumerateDevices();
        const fill = (sel, kind, key) => {
            const cur = store.get(key, '');
            sel.innerHTML = '<option value="">System default</option>' + list.filter(d => d.kind === kind).map((d, i) => `<option value="${esc(d.deviceId)}" ${d.deviceId === cur ? 'selected' : ''}>${esc(d.label || (kind === 'audioinput' ? 'Microphone ' : 'Speaker ') + (i + 1))}</option>`).join('');
        };
        fill($('ck-mic'), 'audioinput', 'mic'); fill($('ck-out'), 'audiooutput', 'out');
        if (!('setSinkId' in HTMLMediaElement.prototype)) { $('ck-out').disabled = true; $('ck-out').title = 'This browser plays through the system’s chosen output'; }
    }
    $('ck-settings').onclick = () => { listDevices(false); $('ck-ringer').checked = store.get('ringer', '1') === '1'; $('ck-dlg-set').hidden = false; };
    $('ck-detect').onclick = () => listDevices(true);
    $('ck-mic').onchange = (e) => { store.set('mic', e.target.value); applySink(); };
    $('ck-out').onchange = (e) => { store.set('out', e.target.value); applySink(); };
    $('ck-ringer').onchange = (e) => store.set('ringer', e.target.checked ? '1' : '0');
    $('ck-vol').oninput = applySink;
    $('ck-test').onclick = () => {
        const Ctx = window.AudioContext || window.webkitAudioContext; if (!Ctx) return;
        const ctx = new Ctx(), o = ctx.createOscillator(), g = ctx.createGain(), dest = ctx.createMediaStreamDestination();
        o.frequency.value = 660; g.gain.value = .15; o.connect(g).connect(dest); o.start(); const a = $('ck-a-voice'); a.srcObject = dest.stream; applySink(); a.play().catch(() => {});
        setTimeout(() => { o.stop(); ctx.close(); a.srcObject = null; }, 700);
    };
    $('ck-full').onclick = () => { const el = $('ck'); document.fullscreenElement ? document.exitFullscreen() : el.requestFullscreen?.().catch(() => {}); };
    document.querySelectorAll('.ck-dlg').forEach(d => d.addEventListener('click', e => { if (e.target === d || e.target.closest('[data-close]')) d.hidden = true; }));
    applySink();

    // ── Feed ────────────────────────────────────────────────────────
    async function poll() {
        try { st.feed = await api('/feed'); chip('ck-link', 'ok'); render(); }
        catch (e) { chip('ck-link', 'bad'); }
        setTimeout(poll, document.hidden ? 8000 : 3000);
    }
    async function pollBoard() {
        try { const d = await api('/board'); st.board = d.board; st.net = d.network; renderBoard(); renderNet(); renderSys(); renderAnnun(); }
        catch (e) {}
        setTimeout(pollBoard, 15000);
    }
    async function pollWx() {
        try { renderWx(await api('/weather')); } catch (e) {}
        setTimeout(pollWx, 600000);
    }

    function render() {
        renderLines(); renderRecent(); renderVoice(); renderAnnun();
    }

    // ── Call lines ──────────────────────────────────────────────────
    function renderLines() {
        const f = st.feed, box = $('ck-lines'), calls = f.calls || [];
        $('ck-linecount').innerHTML = `<span class="ck-chip ${f.stats.ringing ? 'warn' : ''}"><i class="d"></i>${f.stats.ringing} RING</span><span class="ck-chip ${f.stats.active ? 'ok' : ''}"><i class="d"></i>${f.stats.active} ON</span>`;
        if (!calls.length) { box.innerHTML = '<div class="ck-empty"><i class="ri-phone-line"></i>All lines quiet</div>'; return; }
        const keys = calls.map(c => c.uuid + c.status + c.leg + (c.my_listen?.status || '') + (c.mine ? 'm' : '') + c.escalated + (c.listeners || []).join()).join('|');
        if (box.dataset.k !== keys) {
            box.dataset.k = keys;
            box.innerHTML = calls.map((c, i) => lineHtml(c, i)).join('');
        }
        calls.forEach(c => {
            const el = box.querySelector(`[data-uuid="${c.uuid}"]`); if (!el) return;
            el.querySelector('.seg').textContent = c.status === 'ringing' ? clock(c.ring_left) : clock(onFor(c));
        });
        // Ringing nobody picks up: ring out loud (once per call, until master warning is pressed).
        const esc2 = calls.filter(c => c.escalated);
        if (CAN.answer && esc2.length && store.get('ringer', '1') === '1' && !st.call) {
            const fresh = esc2.some(c => !st.seenRing[c.uuid]);
            if (fresh && !st.ringStop && window.hotelRingTone) st.ringStop = hotelRingTone();
        } else stopRing();
    }
    function onFor(c) { if (st.call && st.call.call.uuid === c.uuid && st.call.seconds()) return st.call.seconds(); return c.on_for; }
    function stopRing() { if (st.ringStop) { st.ringStop(); st.ringStop = null; } }
    function lineHtml(c, i) {
        const ring = c.status === 'ringing', cls = ['ck-line', c.status, c.escalated ? 'escalated' : '', c.mine ? 'mine' : ''].join(' ');
        const status = ring ? (c.escalated ? '<span class="st esc">NO ANSWER</span>' : (c.leg > 1 ? '<span class="st xfer">XFER RING</span>' : '<span class="st ring">RINGING</span>')) : '<span class="st on">ON CALL</span>';
        const meta = [];
        if (c.answered_by) meta.push(`<span><i class="ri-user-voice-line"></i> ${esc(c.answered_by)}</span>`);
        if (c.transfer) meta.push(`<span class="l"><i class="ri-arrow-left-right-line"></i> to ${esc(c.transfer.to || '?')}${c.transfer.by ? ' by ' + esc(c.transfer.by) : ''}</span>`);
        if ((c.listeners || []).length) meta.push(`<span class="l"><i class="ri-headphone-line"></i> ${c.listeners.map(esc).join(', ')}</span>`);
        meta.push(`<span>${c.direction === 'out' ? 'Call back' : 'Guest app call'} · ${esc(c.started)}</span>`);
        const keys = [];
        const busy = !!st.call;
        if (ring && CAN.answer) keys.push(`<button type="button" class="ck-key go" data-act="pick" ${busy ? 'disabled title="End your call first"' : ''}><i class="ri-headphone-line"></i> Pick up</button>`);
        if (!ring && CAN.listen && !c.mine) keys.push(c.my_listen ? `<button type="button" class="ck-key stop" data-act="unlisten"><i class="ri-headphone-fill"></i> Stop listening</button>` : `<button type="button" class="ck-key mon" data-act="listen" ${st.listen ? 'disabled' : ''}><i class="ri-headphone-line"></i> Listen</button>`);
        if (CAN.answer) keys.push(`<button type="button" class="ck-key xfer" data-act="xfer"><i class="ri-arrow-left-right-line"></i> Transfer</button>`);
        if (c.mine) keys.push(`<button type="button" class="ck-key ${st.call?.muted ? 'lit' : ''}" data-act="mute"><i class="ri-mic${st.call?.muted ? '-off' : ''}-line"></i> ${st.call?.muted ? 'Unmute' : 'Mute'}</button>`, `<button type="button" class="ck-key stop" data-act="end"><i class="ri-phone-fill" style="display:inline-block;transform:rotate(135deg)"></i> End</button>`);
        else if (ring && CAN.answer) keys.push(`<button type="button" class="ck-key stop" data-act="busy" title="Tell the guest the hotel can’t take the call now"><i class="ri-close-line"></i> Busy</button>`);
        return `<div class="${cls}" data-uuid="${c.uuid}">
            <div class="top"><span class="ch">L${i + 1}</span>${status}<div class="who"><b>${esc(c.guest)}${c.vip ? '<span class="ck-vip">VIP</span>' : ''}</b><span><i class="${esc(c.section_icon || 'ri-chat-3-line')}"></i> ${esc(c.section || '')}${c.room ? ' · Room ' + esc(c.room) : ''}</span></div><span class="seg">--:--</span></div>
            <canvas width="420" height="34" data-wave="${c.uuid}"></canvas>
            <div class="meta">${meta.join('')}</div>
            <div class="keys">${keys.join('')}</div></div>`;
    }
    $('ck-lines').addEventListener('click', async (e) => {
        const b = e.target.closest('[data-act]'); if (!b) return;
        const uuid = b.closest('[data-uuid]').dataset.uuid, c = (st.feed.calls || []).find(x => x.uuid === uuid); if (!c) return;
        const act = b.dataset.act;
        try {
            if (act === 'pick') await pickUp(c);
            if (act === 'end' && st.call) st.call.hangUp();
            if (act === 'mute' && st.call) { st.call.mute(!st.call.muted); $('ck-lines').dataset.k = ''; renderLines(); }
            if (act === 'busy') { await api(`/calls/${uuid}/end`, { method: 'POST', body: JSON.stringify({ reason: 'declined' }) }); toast('The guest was told the hotel can’t take the call just now.'); }
            if (act === 'xfer') openTransfer(c);
            if (act === 'listen') openListen(c);
            if (act === 'unlisten' && st.listen) st.listen.stop();
        } catch (err) { toast(err.message); }
    });

    async function pickUp(c) {
        stopRing(); st.seenRing[c.uuid] = 1;
        const call = await api(`/calls/${c.uuid}/answer`, { method: 'POST' });
        startCall(call, c);
    }
    function startCall(callJson, row) {
        const hc = new HotelCall({ call: callJson, base: `${BASE}/calls/${callJson.uuid}`, csrf: CSRF, audio: $('ck-a-call'), mic: store.get('mic', '') || null, ringback: false,
            onState: (text, phase) => { $('ck-mfd-who').textContent = text; if (phase === 'live') attachScope(hc.remote, hc.stream); if (phase === 'ended') st.lastText = text; },
            onLevel: (them, me) => { st.lineLevels[callJson.uuid] = [them, me]; $('ck-r-lvl').textContent = Math.round(them * 100) + '%'; },
            onQuality: (q, x) => { st.q3 = q; $('ck-r-rtt').textContent = x.rtt != null ? Math.round(x.rtt * 1000) + ' ms' : '---'; $('ck-r-loss').textContent = (x.loss * 100).toFixed(1) + '%'; $('ck-mfd-q').innerHTML = qualityHtml(q); },
            onInfo: (info) => { st.callInfo = info; },
            onEnd: () => { st.call = null; detachScope(); $('ck-mfd-title').textContent = 'standby'; $('ck-mfd-who').textContent = st.lastText || 'Call ended.'; $('ck-mfd-q').innerHTML = ''; $('ck-lines').dataset.k = ''; },
        });
        st.call = hc; applySink();
        $('ck-mfd-title').textContent = 'on call · ' + (row?.guest || callJson.guest || 'guest');
        hc.start().catch(err => { toast(err.message || 'The microphone didn’t start.'); hc.hangUp(); });
        $('ck-lines').dataset.k = '';
    }
    function qualityHtml(q) { return `<span class="ck-bars" data-n="${q === 3 ? 4 : (q === 2 ? 2 : 1)}"><b></b><b></b><b></b><b></b></span> ${q === 3 ? 'GOOD' : (q === 2 ? 'FAIR' : 'POOR')}`; }

    // ── Transfer ────────────────────────────────────────────────────
    let xfer = { uuid: null, pick: null, list: null };
    async function openTransfer(c) {
        xfer = { uuid: c.uuid, pick: null, list: null };
        $('ck-xfer-who').textContent = `${c.guest}${c.room ? ' · Room ' + c.room : ''} — ${c.section || ''}`;
        $('ck-xfer-q').value = ''; $('ck-xfer-note').value = ''; $('ck-xfer-go').disabled = true;
        $('ck-xfer-list').innerHTML = '<div class="ck-empty">Loading…</div>'; $('ck-dlg-xfer').hidden = false;
        try { xfer.list = await api(`/calls/${c.uuid}/targets`); drawTargets(); } catch (e) { $('ck-xfer-list').innerHTML = `<div class="ck-empty">${esc(e.message)}</div>`; }
    }
    function drawTargets() {
        const q = $('ck-xfer-q').value.trim().toLowerCase(), m = (t) => !q || (t.label + ' ' + (t.sub || '')).toLowerCase().includes(q);
        const row = (t) => `<button type="button" class="ck-opt ${xfer.pick && xfer.pick.type === t.type && xfer.pick.id === t.id ? 'sel' : ''}" data-type="${t.type}" data-id="${t.id}"><i class="${esc(t.icon || 'ri-user-line')}" style="font-size:18px;color:var(--amber)"></i><span><b>${esc(t.label)}</b><small>${esc(t.sub || '')}</small></span>${t.busy ? '<span class="busy">ON A CALL</span>' : ''}</button>`;
        const s = xfer.list.sections.filter(m), p = xfer.list.people.filter(m);
        $('ck-xfer-list').innerHTML = (s.length ? '<label>Sections</label>' + s.map(row).join('') : '') + (p.length ? '<label>People</label>' + p.map(row).join('') : '') || '<div class="ck-empty">Nobody matches</div>';
    }
    $('ck-xfer-q').oninput = () => xfer.list && drawTargets();
    $('ck-xfer-list').addEventListener('click', (e) => { const b = e.target.closest('.ck-opt'); if (!b) return; xfer.pick = { type: b.dataset.type, id: +b.dataset.id }; $('ck-xfer-go').disabled = false; drawTargets(); });
    $('ck-xfer-go').onclick = async () => {
        if (!xfer.pick) return;
        const body = { note: $('ck-xfer-note').value }; body[xfer.pick.type === 'user' ? 'user_id' : 'section_id'] = xfer.pick.id;
        try { await api(`/calls/${xfer.uuid}/transfer`, { method: 'POST', body: JSON.stringify(body) }); $('ck-dlg-xfer').hidden = true; toast('Putting the call through. The guest hears it ringing.'); }
        catch (e) { toast(e.message); }
    };

    // ── Listening in ────────────────────────────────────────────────
    let lis = { uuid: null };
    function openListen(c) {
        lis.uuid = c.uuid; $('ck-listen-who').textContent = `${c.guest}${c.room ? ' · Room ' + c.room : ''} with ${c.answered_by || 'staff'} — ${c.section || ''}`;
        $('ck-listen-preset').value = ''; $('ck-listen-why').value = ''; $('ck-dlg-listen').hidden = false;
    }
    $('ck-listen-preset').onchange = (e) => { if (e.target.value) $('ck-listen-why').value = e.target.value; };
    $('ck-listen-go').onclick = async () => {
        const why = $('ck-listen-why').value.trim();
        if (why.length < 3) return toast('Say why you are listening first.');
        try {
            const l = await api(`/calls/${lis.uuid}/listen`, { method: 'POST', body: JSON.stringify({ reason: why }) });
            $('ck-dlg-listen').hidden = true;
            const hl = new HotelListen({ listen: l, base: `${BASE}/listens/${l.id}`, csrf: CSRF, audio: $('ck-a-listen'),
                onState: (text, phase) => { $('ck-mfd-who').textContent = text; if (phase === 'live') attachScope($('ck-a-listen').srcObject, null); },
                onLevel: (v) => { st.lineLevels[l.call] = [v, 0]; $('ck-r-lvl').textContent = Math.round(v * 100) + '%'; },
                onEnd: (t) => { st.listen = null; detachScope(); toast(t); $('ck-mfd-title').textContent = 'standby'; $('ck-lines').dataset.k = ''; },
            });
            st.listen = hl; applySink();
            $('ck-mfd-title').textContent = 'listening · ' + (l.section || '') + (l.with ? ' with ' + l.with : '');
            hl.start(); $('ck-lines').dataset.k = '';
        } catch (e) { toast(e.message); }
    };

    // ── The oscilloscope ────────────────────────────────────────────
    function attachScope(themStream, meStream) {
        detachScope();
        const Ctx = window.AudioContext || window.webkitAudioContext; if (!Ctx) return;
        try {
            const ctx = new Ctx(), tap = (s) => { if (!s) return null; const a = ctx.createAnalyser(); a.fftSize = 2048; ctx.createMediaStreamSource(s).connect(a); return a; };
            st.scope = { ctx, them: tap(themStream), me: tap(meStream) };
        } catch (e) {}
    }
    function detachScope() { if (st.scope.ctx) try { st.scope.ctx.close(); } catch (e) {} st.scope = { ctx: null, them: null, me: null }; }
    const scope = $('ck-scope'), sg = scope.getContext('2d'), tbuf = new Uint8Array(2048);
    let sweep = 0;
    function drawScope() {
        const W = scope.width, H = scope.height;
        sg.fillStyle = 'rgba(3,5,4,0.55)'; sg.fillRect(0, 0, W, H);
        sg.strokeStyle = 'rgba(57,255,136,0.08)'; sg.lineWidth = 1; sg.beginPath();
        for (let x = 0; x <= W; x += W / 12) { sg.moveTo(x, 0); sg.lineTo(x, H); }
        for (let y = 0; y <= H; y += H / 6) { sg.moveTo(0, y); sg.lineTo(W, y); }
        sg.stroke();
        const trace = (an, color, off, gain) => {
            sg.strokeStyle = color; sg.lineWidth = 2; sg.shadowColor = color; sg.shadowBlur = 8; sg.beginPath();
            if (an) { an.getByteTimeDomainData(tbuf); const n = an.fftSize, step = n / W; for (let x = 0; x < W; x++) { const v = (tbuf[Math.floor(x * step)] - 128) / 128; const y = off + v * H * gain; x ? sg.lineTo(x, y) : sg.moveTo(x, y); } }
            else { for (let x = 0; x < W; x += 4) { const y = off + Math.sin((x + sweep) / 24) * 1.2 + (Math.random() - .5) * 1.2; x ? sg.lineTo(x, y) : sg.moveTo(x, y); } }
            sg.stroke(); sg.shadowBlur = 0;
        };
        const live = st.scope.them || st.scope.me;
        trace(st.scope.them, '#39ff88', live && st.scope.me ? H * .32 : H * .5, .9);
        if (st.scope.me) trace(st.scope.me, '#ffb000', H * .72, .7);
        if (!live) { sg.fillStyle = 'rgba(124,135,150,.6)'; sg.font = '14px "Share Tech Mono", monospace'; sg.fillText('NO AUDIO · STANDBY', 16, 24); }
        sweep += 3;
        drawLineWaves();
        requestAnimationFrame(drawScope);
    }
    // Each line's little wave: real sound for a call you are on or listening to; otherwise the line's "carrier".
    let wt = 0;
    function drawLineWaves() {
        if ((wt++ % 3) !== 0) return;
        document.querySelectorAll('[data-wave]').forEach(cv => {
            const g = cv.getContext('2d'), W = cv.width, H = cv.height, u = cv.dataset.wave, c = (st.feed?.calls || []).find(x => x.uuid === u);
            const lv = st.lineLevels[u], real = (st.call && st.call.call.uuid === u) || (st.listen && st.listen.l.call === u);
            g.clearRect(0, 0, W, H);
            const ring = c && c.status === 'ringing', color = ring ? (c.escalated ? '#ff3b3b' : '#ffb000') : '#39ff88';
            g.strokeStyle = color; g.globalAlpha = real ? 1 : .55; g.lineWidth = 1.6; g.beginPath();
            const amp = real && lv ? Math.max(lv[0], lv[1]) : (ring ? (Math.sin(Date.now() / 260) > 0 ? .5 : .05) : .12);
            for (let x = 0; x < W; x += 3) {
                const y = H / 2 + Math.sin(x / (ring ? 6 : 9) + Date.now() / 120) * amp * (H / 2 - 2) * (real ? (.6 + Math.random() * .4) : 1);
                x ? g.lineTo(x, y) : g.moveTo(x, y);
            }
            g.stroke(); g.globalAlpha = 1;
        });
        // Readouts for the active call
        if (st.call) $('ck-r-time').textContent = clock(st.call.seconds());
        else if (st.listen) { st.listenAt = st.listenAt || Date.now(); $('ck-r-time').textContent = clock((Date.now() - st.listenAt) / 1000); }
        else { st.listenAt = null; $('ck-r-time').textContent = '--:--'; }
    }
    requestAnimationFrame(drawScope);

    // ── Recent calls and voice messages ─────────────────────────────
    function renderRecent() {
        const f = st.feed;
        $('ck-missed').innerHTML = `<span class="ck-chip ${f.stats.missed_today ? 'warn' : ''}"><i class="d"></i>${f.stats.missed_today} MISSED TODAY</span><span class="ck-chip ok"><i class="d"></i>${f.stats.answered_today} TAKEN</span>`;
        $('ck-recent').innerHTML = (f.recent || []).map(c => `<div class="ck-row ${c.status}"><span class="ic"><i class="${c.status === 'missed' ? 'ri-phone-missed-line' : (c.status === 'ended' ? 'ri-phone-line' : 'ri-phone-lock-line')}"></i></span>
            <span class="nm"><b>${esc(c.guest)}${c.room ? ' · ' + esc(c.room) : ''}</b><span>${esc(c.section || '')} · ${c.status === 'ended' ? (c.answered_by ? esc(c.answered_by) + ' · ' : '') + clock(c.seconds) : (c.status === 'missed' ? 'Missed' : 'Busy')} · ${esc(c.ended || '')}</span></span>
            <span class="act"><a class="ck-key" style="text-decoration:none;padding:4px 8px" href="${esc(c.chat_url)}" title="Open the chat (call back from there)"><i class="ri-chat-3-line"></i></a></span></div>`).join('') || '<div class="ck-empty">No calls in the last day</div>';
    }
    function renderVoice() {
        const v = st.feed.voice || [];
        $('ck-vcount').innerHTML = `<span class="ck-chip ${v.some(x => x.unread) ? 'warn' : ''}"><i class="d"></i>${v.filter(x => x.unread).length} NEW</span>`;
        const box = $('ck-voice'), k = v.map(x => x.id + (x.unread ? 'u' : '')).join();
        if (box.dataset.k === k) return; box.dataset.k = k;
        box.innerHTML = v.map(m => `<div class="ck-row ${m.unread ? 'unread' : ''}" data-voice="${m.id}"><span class="ic"><i class="ri-voiceprint-line" style="color:var(--amber)"></i></span>
            <span class="nm"><b>${esc(m.guest)}${m.room ? ' · ' + esc(m.room) : ''}</b><span>${esc(m.section || '')} · ${esc(m.clock)} · ${esc(m.at)}</span></span>
            <span class="act">${CAN.listen ? `<canvas width="160" height="44"></canvas><button type="button" class="ck-key go" data-play="${esc(m.url)}" style="padding:4px 8px" title="Play (recorded in the audit trail)"><i class="ri-play-fill"></i></button>` : ''}<a class="ck-key" style="text-decoration:none;padding:4px 8px" href="${esc(m.chat_url)}" title="Open the chat"><i class="ri-chat-3-line"></i></a></span></div>`).join('') || '<div class="ck-empty">No voice messages</div>';
    }
    let vplay = null;
    $('ck-voice').addEventListener('click', (e) => {
        const b = e.target.closest('[data-play]'); if (!b) return;
        const a = $('ck-a-voice'), row = b.closest('[data-voice]'), cv = row.querySelector('canvas');
        if (vplay && vplay.btn === b && !a.paused) { a.pause(); return; }
        a.srcObject = null; a.src = b.dataset.play; applySink(); a.play().catch(() => toast('That voice message didn’t play.'));
        document.querySelectorAll('[data-play]').forEach(x => x.innerHTML = '<i class="ri-play-fill"></i>');
        b.innerHTML = '<i class="ri-pause-fill"></i>';
        vplay = { btn: b, cv };
        a.onended = a.onpause = () => { b.innerHTML = '<i class="ri-play-fill"></i>'; };
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (Ctx && !a._an) { try { const ctx = new Ctx(), src = ctx.createMediaElementSource(a), an = ctx.createAnalyser(); an.fftSize = 256; src.connect(an); an.connect(ctx.destination); a._an = an; } catch (er) {} }
        const g = cv.getContext('2d'), buf = new Uint8Array(128);
        (function frame() {
            if (vplay?.cv !== cv) return;
            g.clearRect(0, 0, cv.width, cv.height);
            const prog = a.duration ? a.currentTime / a.duration : 0;
            if (a._an) a._an.getByteFrequencyData(buf);
            for (let i = 0; i < 32; i++) { const v = a._an ? buf[i * 2] / 255 : (.3 + .7 * Math.abs(Math.sin(i * 1.7))); const h = Math.max(2, v * (cv.height - 4)); g.fillStyle = i / 32 < prog ? '#39ff88' : '#2a3a30'; g.fillRect(i * 5, (cv.height - h) / 2, 3, h); }
            if (!a.paused) requestAnimationFrame(frame);
        })();
    });

    // ── Network ─────────────────────────────────────────────────────
    function gaugeSvg(frac, label, color) {
        const r = 60, cx = 75, cy = 80, a0 = Math.PI, a1 = Math.PI * (1 - frac);
        const p = (a) => [cx + r * Math.cos(a), cy - r * Math.sin(a)];
        const [x1, y1] = p(a0), [x2, y2] = p(a1), [x3, y3] = p(0);
        const ticks = Array.from({ length: 9 }, (_, i) => { const a = Math.PI * (1 - i / 8), [ax, ay] = [cx + (r - 9) * Math.cos(a), cy - (r - 9) * Math.sin(a)], [bx, by] = [cx + (r + 2) * Math.cos(a), cy - (r + 2) * Math.sin(a)]; return `<line x1="${ax}" y1="${ay}" x2="${bx}" y2="${by}" stroke="#3a424e" stroke-width="2"/>`; }).join('');
        const na = Math.PI * (1 - frac), nx = cx + (r - 14) * Math.cos(na), ny = cy - (r - 14) * Math.sin(na);
        return `<svg viewBox="0 0 150 100" role="img" aria-label="Network strength ${Math.round(frac * 100)}%">
            <path d="M${x1} ${y1} A${r} ${r} 0 0 1 ${x3} ${y3}" fill="none" stroke="#1d232b" stroke-width="10" stroke-linecap="round"/>
            ${frac > 0 ? `<path d="M${x1} ${y1} A${r} ${r} 0 0 1 ${x2} ${y2}" fill="none" stroke="${color}" stroke-width="10" stroke-linecap="round" style="filter:drop-shadow(0 0 5px ${color})"/>` : ''}
            ${ticks}<line x1="${cx}" y1="${cy}" x2="${nx}" y2="${ny}" stroke="#f2f6fb" stroke-width="2.5" stroke-linecap="round"/><circle cx="${cx}" cy="${cy}" r="5" fill="#f2f6fb"/>
            <text x="${cx}" y="${cy + 17}" text-anchor="middle" fill="${color}" font-family="Share Tech Mono, monospace" font-size="13">${esc(label)}</text></svg>`;
    }
    function spark(trace) {
        const v = (trace || []).filter(x => x != null); if (v.length < 2) return '<svg class="ck-spark"></svg>';
        const mx = Math.max(...v, 50), pts = v.map((x, i) => `${(i / (v.length - 1)) * 70},${22 - (x / mx) * 20 - 1}`).join(' ');
        return `<svg class="ck-spark" viewBox="0 0 70 22" preserveAspectRatio="none"><polyline points="${pts}" fill="none" stroke="#33d6ff" stroke-width="1.5"/></svg>`;
    }
    const bars = (n) => `<span class="ck-bars" data-n="${n}"><b></b><b></b><b></b><b></b></span>`;
    function renderNet() {
        const n = st.net; if (!n) return;
        const conn = navigator.connection, rows = [];
        if (n.lines) n.lines.links.forEach(l => rows.push(`<div class="ck-link"><span>${bars(l.status === 'offline' ? 0 : Math.max(1, Math.round((l.signal || 0) / 25)))}</span><span class="nm">${esc(l.name)}<small>${esc(l.role || 'Internet')} · ${l.down_mbps != null ? Math.round(l.down_mbps) + '↓ ' + Math.round(l.up_mbps || 0) + '↑ Mbps' : esc(l.status)}</small></span><span class="ms">${l.signal != null ? l.signal + '%' : ''}</span><span class="ms">${l.latency_ms != null ? Math.round(l.latency_ms) + 'ms' : '--'}</span></div>`));
        n.links.forEach(l => rows.push(`<div class="ck-link"><span>${bars(l.bars)}</span><span class="nm">${esc(l.name)}<small>${esc(l.status.toUpperCase())}${l.checked ? ' · ' + esc(l.checked) : ''}</small></span>${spark(l.trace)}<span class="ms" style="color:${l.status === 'down' ? 'var(--red)' : 'var(--green)'}">${l.latency != null ? l.latency + 'ms' : '--'}</span></div>`));
        if (conn) { const b = conn.effectiveType === '4g' ? 4 : (conn.effectiveType === '3g' ? 2 : 1); rows.push(`<div class="ck-link"><span>${bars(navigator.onLine ? b : 0)}</span><span class="nm">This console<small>${esc((conn.effectiveType || '').toUpperCase())}${conn.downlink ? ' · ' + conn.downlink + ' Mbps' : ''}</small></span><span></span><span class="ms">${conn.rtt != null ? conn.rtt + 'ms' : '--'}</span></div>`); }
        $('ck-links').innerHTML = rows.join('') || '<div class="ck-empty">No network monitors yet — add your internet line and Wi-Fi under Monitors.</div>';
        let frac = n.bars == null ? 0 : n.bars / 4;
        if (n.lines?.overall?.signal != null) frac = n.bars == null ? n.lines.overall.signal / 100 : (frac + n.lines.overall.signal / 100) / 2;
        const color = frac >= .7 ? '#39ff88' : (frac >= .4 ? '#ffb000' : '#ff3b3b');
        $('ck-gauge').innerHTML = gaugeSvg(frac, n.bars == null && !n.lines ? 'NO DATA' : Math.round(frac * 100) + '%', color);
        $('ck-net-sum').innerHTML = `<span class="ck-chip ${n.down ? 'bad' : 'ok'}"><i class="d"></i>${n.down ? n.down + ' DOWN' : 'ALL UP'}</span>${n.latency != null ? `<span class="ck-chip">${n.latency} ms avg</span>` : ''}`;
    }

    // ── Weather ─────────────────────────────────────────────────────
    function dial(v, max, label, unit, color) {
        const frac = v == null ? 0 : Math.max(0, Math.min(1, v / max)), r = 34, cx = 46, cy = 44, a = Math.PI * (1 - frac);
        const end = [cx + r * Math.cos(a), cy - r * Math.sin(a)];
        return `<div class="ck-dial"><svg viewBox="0 0 92 58"><path d="M${cx - r} ${cy} A${r} ${r} 0 0 1 ${cx + r} ${cy}" fill="none" stroke="#1d232b" stroke-width="6"/>${frac ? `<path d="M${cx - r} ${cy} A${r} ${r} 0 0 1 ${end[0]} ${end[1]}" fill="none" stroke="${color}" stroke-width="6" style="filter:drop-shadow(0 0 3px ${color})"/>` : ''}<text x="${cx}" y="${cy - 2}" text-anchor="middle" fill="#f2f6fb" font-family="Share Tech Mono, monospace" font-size="15">${v == null ? '--' : v}${unit}</text></svg><small>${label}</small></div>`;
    }
    function renderWx(w) {
        if (!w.available) { $('ck-wx').innerHTML = `<div class="ck-empty"><i class="ri-cloud-off-line"></i>${esc(w.note || 'No weather')}</div>`; return; }
        const n = w.now || {};
        $('ck-wx-sun').innerHTML = w.sunrise ? `<span class="ck-chip"><i class="ri-sun-line"></i>${esc(w.sunrise)}</span><span class="ck-chip"><i class="ri-moon-line"></i>${esc(w.sunset)}</span>` : '';
        $('ck-wx').innerHTML = `<div class="ck-wx-now"><i class="ic ${esc(n.icon)}"></i><div><div class="tp">${n.temp ?? '--'}<small>${esc(w.unit)}</small></div><div class="sm">${esc(n.summary || '')} · feels ${n.feels ?? '--'}${esc(w.unit)}</div></div></div>
            <div class="ck-dials">${dial(n.wind, 60, 'Wind', '', '#33d6ff')}${dial(n.humidity, 100, 'Humidity', '%', '#39ff88')}${dial(w.hours?.[0]?.rain ?? null, 100, 'Rain', '%', '#ffb000')}</div>
            <div class="ck-hours">${(w.hours || []).map(h => `<div>${esc(h.label)}<i class="${esc(h.icon)}" style="display:block"></i><b>${h.temp}°</b>${h.rain != null ? h.rain + '%' : ''}</div>`).join('')}</div>
            <div class="ck-days">${(w.days || []).map(d => `<div><span>${esc(d.label)}</span><i class="${esc(d.icon)}" style="color:var(--amber)"></i><span class="rg" style="opacity:${.25 + (d.rain || 0) / 130}" title="${d.rain ?? 0}% rain"></span><span>${d.min}° / ${d.max}°</span></div>`).join('')}</div>`;
    }

    // ── Systems + board ─────────────────────────────────────────────
    function allDevices() { if (!st.board) return []; return st.board.floors.flatMap(f => f.rooms.flatMap(r => r.devices.map(d => Object.assign({ place: 'Room ' + r.number }, d)))).concat(st.board.areas.flatMap(a => a.devices.map(d => Object.assign({ place: a.name }, d)))); }
    function renderSys() {
        const t = st.board.totals;
        $('ck-s-on').textContent = t.on; $('ck-s-off').textContent = t.off; $('ck-s-fault').textContent = t.fault; $('ck-s-offline').textContent = t.offline;
        $('ck-alarms').innerHTML = st.board.alarms.map(a => `<div class="ck-alarm ${a.state === 'offline' ? 'offline' : ''}"><i class="${esc(a.icon)}"></i><span><b>${esc(a.place)}</b> · ${esc(a.name)}</span><span style="font-family:var(--lcd);font-size:11px">${a.state === 'offline' ? 'NO SIG' : a.state.toUpperCase()}</span></div>`).join('')
            || '<div class="ck-empty" style="padding:12px"><i class="ri-shield-check-line" style="color:var(--green)"></i>No equipment alarms</div>';
        $('ck-f-alarm').textContent = st.board.totals.alarms || ''; $('ck-f-waste').textContent = st.board.totals.empty_rooms_running || '';
    }
    const ledHtml = (d) => `<i class="ck-led ${d.state === 'off' ? '' : d.state}" title="${esc(d.name)}: ${d.state === 'offline' ? 'no signal' : d.state}${d.reading ? ' · ' + esc(d.reading) : ''}"><i class="${esc(d.icon)}"></i></i>`;
    const wasteKinds = ['ac', 'lights', 'tv', 'water_heater', 'fan'];
    function renderBoard() {
        const b = st.board, q = st.q.toLowerCase(), f = st.filter, box = $('ck-board');
        if (!b) return;
        const keep = (r) => (!q || String(r.number || r.name).toLowerCase().includes(q))
            && (f === 'all' || (f === 'alarm' && r.alarms) || (f === 'occ' && r.occupied) || (f === 'waste' && r.number && !r.occupied && r.devices.some(d => d.state === 'on' && wasteKinds.includes(d.kind))));
        if (st.view === 'rooms') {
            const floors = b.floors.map(fl => ({ floor: fl.floor, rooms: fl.rooms.filter(keep) })).filter(fl => fl.rooms.length);
            box.innerHTML = floors.map(fl => `<div class="ck-floor"><h6>${esc(fl.floor)}</h6><div class="ck-tiles">${fl.rooms.map(r => {
                const waste = !r.occupied && r.devices.some(d => d.state === 'on' && wasteKinds.includes(d.kind));
                return `<button type="button" class="ck-tile ${r.alarms ? 'alarm' : ''} ${waste ? 'waste' : ''}" data-room="${r.id}"><span class="no">${esc(r.number)}${r.occupied ? '<small class="occ">OCC</small>' : `<small>${esc(String(r.status || '').toUpperCase().slice(0, 6))}</small>`}</span>
                    <span class="ck-leds">${r.devices.map(ledHtml).join('') || '<span class="sum">no equipment listed</span>'}</span>${r.devices.length ? `<span class="sum">${r.on} on · ${r.off} off${r.fault ? ' · ' + r.fault + ' fault' : ''}</span>` : ''}</button>`;
            }).join('')}</div></div>`).join('') || `<div class="ck-empty">${b.floors.some(fl => fl.rooms.some(r => r.devices.length)) ? 'Nothing matches' : 'No room equipment listed yet. ' + (CAN.equipment ? 'Open <a href="' + STATE_URL + '" style="color:var(--cyan)">Room equipment</a> and press “Add the usual to every room”.' : '')}</div>`;
        } else {
            const areas = b.areas.filter(keep);
            box.innerHTML = areas.length ? `<div class="ck-tiles areas" style="margin-top:10px">${areas.map(a => `<button type="button" class="ck-tile ${a.alarms ? 'alarm' : ''}" data-area="${esc(a.name)}"><span class="no" style="font-family:var(--label);font-weight:700;font-size:14px">${esc(a.name)}<small>${a.devices.length}</small></span><span class="ck-leds">${a.devices.map(ledHtml).join('')}</span><span class="sum">${a.on} on · ${a.off} off${a.fault ? ' · ' + a.fault + ' fault' : ''}${a.offline ? ' · ' + a.offline + ' no sig' : ''}</span></button>`).join('')}</div>`
                : '<div class="ck-empty">No equipment listed for other areas yet.</div>';
        }
        if (!$('ck-dlg-dev').hidden && devOpen) openDevices(devOpen);
    }
    $('ck-btabs').addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; st.view = b.dataset.v; $('ck-btabs').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); renderBoard(); });
    $('ck-bfilter').addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; st.filter = b.dataset.f; $('ck-bfilter').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); renderBoard(); });
    $('ck-bq').oninput = (e) => { st.q = e.target.value.trim(); renderBoard(); };
    let devOpen = null;
    $('ck-board').addEventListener('click', e => { const t = e.target.closest('.ck-tile'); if (!t) return; devOpen = t.dataset.room ? { room: +t.dataset.room } : { area: t.dataset.area }; openDevices(devOpen); $('ck-dlg-dev').hidden = false; });
    function openDevices(which) {
        let title = '', devices = [];
        if (which.room) { st.board.floors.forEach(f => f.rooms.forEach(r => { if (r.id === which.room) { title = 'Room ' + r.number + (r.occupied ? ' · occupied' : ' · ' + r.status); devices = r.devices; } })); }
        else { const a = st.board.areas.find(x => x.name === which.area); title = a?.name || ''; devices = a?.devices || []; }
        $('ck-dev-title').textContent = title;
        $('ck-dev-list').innerHTML = devices.map(d => `<div class="ck-dev"><i class="${esc(d.icon)}" style="font-size:18px;color:var(--dim)"></i><div><b style="color:var(--white)">${esc(d.name)}</b>${d.critical ? ' <span class="ck-vip" style="background:var(--red);color:#fff">CRITICAL</span>' : ''}<div style="font-size:11.5px;color:var(--dim)">${d.reading ? esc(d.reading) + ' · ' : ''}${d.since ? 'since ' + esc(d.since) + ' · ' : ''}${d.source === 'hub' ? 'from hardware' : 'set by staff'}</div>
            ${CAN.equipment ? `<div class="sw"><button type="button" class="ck-key go" data-dev="${d.id}" data-s="on">ON</button><button type="button" class="ck-key" data-dev="${d.id}" data-s="off">OFF</button><button type="button" class="ck-key stop" data-dev="${d.id}" data-s="fault">FAULT</button></div>` : ''}</div>
            <span class="pill ${d.state}">${d.state === 'offline' ? 'NO SIG' : d.state.toUpperCase()}</span></div>`).join('') || '<div class="ck-empty">No equipment listed here.</div>';
    }
    $('ck-dev-list').addEventListener('click', async e => {
        const b = e.target.closest('[data-dev]'); if (!b) return;
        try { await api(STATE_URL + b.dataset.dev + '/state', { method: 'POST', body: JSON.stringify({ state: b.dataset.s }) }); const d = await api('/board'); st.board = d.board; renderBoard(); renderSys(); renderAnnun(); }
        catch (err) { toast(err.message); }
    });

    // ── Annunciator + master warning / caution ──────────────────────
    function renderAnnun() {
        const f = st.feed, b = st.board, n = st.net, devs = allDevices();
        const kindAlarm = (kinds) => devs.filter(d => kinds.includes(d.kind) && (d.state === 'fault' || (d.critical && d.state !== 'on'))).length;
        const kindFault = (kinds) => devs.filter(d => kinds.includes(d.kind) && d.state === 'fault').length;
        const calls = f?.calls || [];
        const L = [
            ['RING', calls.some(c => c.status === 'ringing') ? 'a flash' : ''],
            ['NO ANSWER', calls.some(c => c.escalated) ? 'r flash' : ''],
            ['ON CALL', calls.some(c => c.status === 'active') ? 'g' : ''],
            ['LISTEN', calls.some(c => (c.listeners || []).length) || st.listen ? 'c' : ''],
            ['MISSED', f?.stats.missed_today ? 'a' : ''],
            ['VOICE MSG', (f?.voice || []).some(v => v.unread) ? 'a' : ''],
            ['NET', n ? (n.down ? 'r flash' : (n.links.some(l => l.status === 'degraded') ? 'a' : 'g')) : ''],
            ['IT ALERT', f?.stats.alerts ? 'r' : ''],
            ['POWER', kindAlarm(['generator', 'inverter', 'solar']) ? 'r flash' : (b ? 'g' : '')],
            ['FIRE', kindAlarm(['fire_panel', 'smoke']) ? 'r flash' : (b ? 'g' : '')],
            ['DOOR LOCK', kindAlarm(['door_lock', 'gate']) ? 'r' : ''],
            ['WATER', kindAlarm(['pump']) ? 'r' : ''],
            ['LIFT', kindAlarm(['lift']) ? 'a' : ''],
            ['COLD ROOM', kindAlarm(['cold_room']) ? 'r' : ''],
            ['A/C FAULT', kindFault(['ac']) ? 'a' : ''],
            ['CCTV', kindAlarm(['cctv']) ? 'a' : ''],
            ['NO SIGNAL', b?.totals.offline ? 'a' : ''],
            ['ENERGY', b?.totals.empty_rooms_running ? 'a' : ''],
        ];
        $('ck-annun').innerHTML = L.map(([t, c]) => `<div class="ck-lamp ${c}">${t}</div>`).join('');
        const warn = L.filter(([, c]) => c.startsWith('r')).map(x => x[0]).join(), caut = L.filter(([, c]) => c.startsWith('a')).map(x => x[0]).join();
        $('ck-mw').classList.toggle('on', !!warn); $('ck-mw').classList.toggle('flash', !!warn && warn !== st.ack.w);
        $('ck-mc').classList.toggle('on', !!caut); $('ck-mc').classList.toggle('flash', !!caut && caut !== st.ack.c);
        st.warn = warn; st.caut = caut;
    }
    $('ck-mw').onclick = () => { st.ack.w = st.warn || ''; (st.feed?.calls || []).forEach(c => { if (c.escalated) st.seenRing[c.uuid] = 1; }); stopRing(); renderAnnun(); };
    $('ck-mc').onclick = () => { st.ack.c = st.caut || ''; renderAnnun(); };

    poll(); pollBoard(); pollWx();
    window.addEventListener('beforeunload', (e) => { if (st.call && !st.call.ended) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
@endsection
