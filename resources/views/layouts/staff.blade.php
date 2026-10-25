<!doctype html>
@php
    $me = auth()->user();
    $badges = app(\App\Services\Staff\StaffAppService::class)->counts($me);
    $canApprove = $me->canAny(['Approve expenses', 'Approve purchases', 'Review cash shifts', 'Post stock counts']);
    $r = request()->route()?->getName();
    $tabs = array_filter([
        ['staff.home', 'ri-home-5-line', 'ri-home-5-fill', 'Today', null],
        ['staff.work', 'ri-task-line', 'ri-task-fill', 'My work', $badges['work']],
        $canApprove ? ['staff.approvals', 'ri-checkbox-circle-line', 'ri-checkbox-circle-fill', 'Approve', $badges['approvals']] : null,
        ['staff.report', 'ri-add-circle-line', 'ri-add-circle-fill', 'Report', null],
        ['staff.more', 'ri-menu-line', 'ri-menu-fill', 'More', null],
    ]);
@endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $pagetitle ?? 'Staff' }} · {{ config('hotel.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f766e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Staff">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="{{ route('staff.manifest') }}">
    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <link rel="apple-touch-icon" href="{{ asset('staff-app/apple-touch-icon.png') }}">
    <link href="{{ asset('vendor/fonts/fonts.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/cb-ui.css') }}?v={{ @filemtime(public_path('css/cb-ui.css')) }}" rel="stylesheet">
    @include('reservations.partials.styles')
    <style>
        :root { --sa-bg: #f1f5f9; --sa-card: #fff; --sa-text: #0f172a; --sa-muted: #64748b; --sa-line: #e2e8f0; --sa-brand: #0f766e; --sa-brand-2: #115e59; }
        @media (prefers-color-scheme: dark) { :root { --sa-bg: #0b1220; --sa-card: #111a2e; --sa-text: #e2e8f0; --sa-muted: #94a3b8; --sa-line: #1e293b; } }
        html, body { background: var(--sa-bg); color: var(--sa-text); }
        body, button, input, select, textarea { font-family: var(--cb-font, 'Plus Jakarta Sans'), system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        body { font-size: 15px; -webkit-tap-highlight-color: transparent; padding-bottom: calc(72px + env(safe-area-inset-bottom)); overscroll-behavior-y: contain; }
        .sa-top { position: sticky; top: 0; z-index: 20; background: linear-gradient(135deg, var(--sa-brand-2), var(--sa-brand)); color: #fff;
            padding: calc(10px + env(safe-area-inset-top)) 16px 12px; display: flex; align-items: center; gap: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.12); }
        .sa-top h1 { font-size: 18px; font-weight: 700; margin: 0; color: #fff; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sa-top .hotel { font-size: 11.5px; opacity: .8; display: block; font-weight: 500; }
        .sa-avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,.18); display: grid; place-items: center; font-weight: 700; font-size: 13px; color: #fff; text-decoration: none; }
        .sa-main { padding: 14px 14px 8px; max-width: 640px; margin: 0 auto; }
        .sa-nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; background: var(--sa-card); border-top: 1px solid var(--sa-line);
            display: flex; padding: 6px 4px calc(6px + env(safe-area-inset-bottom)); box-shadow: 0 -2px 12px rgba(15,23,42,.06); }
        .sa-nav a { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 4px 0; color: var(--sa-muted); text-decoration: none; font-size: 11px; font-weight: 600; position: relative; }
        .sa-nav a i { font-size: 23px; line-height: 1; }
        .sa-nav a.on { color: var(--sa-brand); }
        .sa-badge { position: absolute; top: 0; left: 50%; margin-left: 6px; background: #dc2626; color: #fff; border-radius: 10px; font-size: 10.5px; padding: 0 6px; min-width: 18px; line-height: 17px; text-align: center; }
        .sa-card { background: var(--sa-card); border-radius: 16px; border: 1px solid var(--sa-line); padding: 14px; margin-bottom: 12px; }
        .sa-card h2 { font-size: 15px; font-weight: 700; margin: 0 0 10px; color: var(--sa-text); display: flex; align-items: center; gap: 8px; }
        .sa-sec { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; color: var(--sa-muted); margin: 18px 4px 8px; }
        .sa-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .sa-tile { background: var(--sa-card); border: 1px solid var(--sa-line); border-radius: 16px; padding: 14px 8px; text-align: center; color: var(--sa-text); text-decoration: none;
            display: flex; flex-direction: column; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; line-height: 1.2; min-height: 92px; justify-content: center; }
        .sa-tile i { font-size: 26px; color: var(--tile, var(--sa-brand)); }
        .sa-tile:active { transform: scale(.97); }
        .sa-kpis { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .sa-kpi { background: var(--sa-card); border: 1px solid var(--sa-line); border-radius: 14px; padding: 12px; }
        .sa-kpi .v { font-size: 20px; font-weight: 800; color: var(--sa-text); line-height: 1.1; }
        .sa-kpi .l { font-size: 12px; color: var(--sa-muted); margin-top: 3px; }
        .sa-item { background: var(--sa-card); border: 1px solid var(--sa-line); border-radius: 16px; padding: 14px; margin-bottom: 10px; position: relative; }
        .sa-item.urgent { border-left: 5px solid #dc2626; }
        .sa-item .t { font-weight: 700; font-size: 16px; color: var(--sa-text); }
        .sa-item .m { color: var(--sa-muted); font-size: 13px; margin-top: 2px; }
        .sa-item .big { font-size: 26px; font-weight: 800; line-height: 1; color: var(--sa-text); }
        .sa-btns { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
        .sa-btn { flex: 1 1 auto; min-height: 46px; border-radius: 12px; border: 0; font-weight: 700; font-size: 15px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 0 14px; text-decoration: none; background: #e2e8f0; color: #0f172a; }
        .sa-btn.go { background: var(--sa-brand); color: #fff; } .sa-btn.warn { background: #f59e0b; color: #fff; } .sa-btn.bad { background: #fee2e2; color: #b91c1c; }
        .sa-btn:disabled { opacity: .55; }
        .sa-btns form { flex: 1 1 auto; display: flex; margin: 0; } .sa-btns form .sa-btn { width: 100%; }
        .sa-main .form-control, .sa-main .form-select { min-height: 46px; font-size: 16px; border-radius: 12px; }
        .sa-main label { font-weight: 600; font-size: 13.5px; margin-bottom: 4px; }
        .sa-empty { text-align: center; color: var(--sa-muted); padding: 34px 10px; }
        .sa-empty i { font-size: 44px; display: block; color: #22c55e; margin-bottom: 6px; }
        .sa-offline { display: none; background: #f59e0b; color: #111; text-align: center; font-size: 13px; font-weight: 600; padding: 6px; }
        body.is-offline .sa-offline { display: block; }
        .sa-main .cb-banner { border-radius: 12px; }
        details.sa-more > summary { list-style: none; cursor: pointer; } details.sa-more > summary::-webkit-details-marker { display: none; }
    </style>
    @include('partials.qlouds-head', ['key' => 'web'])
</head>
<body>
@include('partials.qlouds-splash', ['key' => 'web'])
    <header class="sa-top">
        <div style="min-width:0;flex:1"><span class="hotel">{{ config('hotel.name') }}</span><h1>{{ $pagetitle ?? 'Staff' }}</h1></div>
        <a href="{{ route('staff.more') }}" class="sa-avatar" aria-label="My account">{{ $me->initials }}</a>
    </header>
    <div class="sa-offline"><i class="ri-wifi-off-line"></i> Offline — showing the last saved copy. Actions need a connection.</div>

    <main class="sa-main">
        @include('rooms.partials.flash')
        @yield('content')
    </main>

    <nav class="sa-nav" aria-label="Staff app">
        @foreach ($tabs as [$route, $icon, $iconOn, $label, $badge])
            <a href="{{ route($route) }}" class="{{ $r === $route ? 'on' : '' }}" @if ($r === $route) aria-current="page" @endif>
                <i class="{{ $r === $route ? $iconOn : $icon }}"></i>{{ $label }}
                @if ($badge)<span class="sa-badge">{{ $badge > 99 ? '99+' : $badge }}</span>@endif
            </a>
        @endforeach
    </nav>

    <script src="{{ asset('theme/layouts/assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script>
        // One tap = one action: disable the buttons of a form once it is sent.
        document.addEventListener('submit', function (e) {
            const f = e.target;
            if (!f.matches('form[data-once]')) return;
            if (f.dataset.sent) { e.preventDefault(); return; }
            f.dataset.sent = '1';
            f.querySelectorAll('button[type=submit], button:not([type])').forEach(b => { b.disabled = true; b.dataset.label = b.innerHTML; b.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; });
        }, true);
        // Back/forward cache: re-enable buttons when the page is shown again.
        window.addEventListener('pageshow', () => document.querySelectorAll('form[data-sent]').forEach(f => { delete f.dataset.sent; f.querySelectorAll('button[disabled]').forEach(b => { b.disabled = false; if (b.dataset.label) b.innerHTML = b.dataset.label; }); }));
        const net = () => document.body.classList.toggle('is-offline', !navigator.onLine);
        window.addEventListener('online', net); window.addEventListener('offline', net); net();
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register(@json(asset('staff-sw.js')), { scope: @json(rtrim(parse_url(route('staff.home'), PHP_URL_PATH) ?: '/staff', '/') . '/') }).catch(() => {});
        }
    </script>
    @stack('scripts')
</body>
</html>
