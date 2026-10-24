<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f2342">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Staff clock-in | {{ config('hotel.name') }}</title>
    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <link rel="stylesheet" href="{{ asset('theme/layouts/assets/css/icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/attendance-kiosk.css') }}?v={{ @filemtime(public_path('css/attendance-kiosk.css')) }}">
</head>
<body>
<div class="k" id="app">
    <header class="k-top">
        <div class="k-brand"><img src="{{ asset(config('hotel.logo_light', config('hotel.logo'))) }}" alt=""><span><strong>{{ config('hotel.name') }}</strong><small id="pointName">Staff clock-in</small></span></div>
        <div class="k-clock"><span id="clock">--:--</span><small id="date"></small></div>
        <div class="k-status">
            <span class="k-net" id="net"><span class="dot"></span><span id="netText">Checking…</span></span>
            <button type="button" class="k-queue" id="queue" hidden></button>
            <button type="button" class="k-icon" id="menuBtn" aria-label="Kiosk settings"><i class="ri-settings-3-line"></i></button>
        </div>
    </header>
    <div class="k-banner" id="banner" hidden></div>

    <main class="k-main">
        <section class="k-cam">
            <div id="reader" class="k-reader"></div>
            <div class="k-cam-msg" id="camMsg" hidden></div>
            <div class="k-hint"><i class="ri-qr-scan-2-line"></i> Hold your staff badge up to the camera</div>
            <input type="text" id="wedge" class="k-wedge" autocomplete="off" aria-label="Badge scanner input">
        </section>
        <section class="k-panel">
            <div class="k-result idle" id="result">
                <div class="k-res-icon"><i class="ri-user-follow-line"></i></div>
                <h2>Welcome</h2>
                <p>Scan your badge to clock in or out.</p>
            </div>
            <div class="k-recent">
                <h3>Recent scans on this kiosk</h3>
                <ul id="recent"></ul>
            </div>
        </section>
    </main>
    <canvas id="snap" width="320" height="240" hidden></canvas>

    <div class="k-modal" id="modal" hidden>
        <div class="k-dialog">
            <div class="k-dialog-head"><h3 id="mTitle"></h3><button type="button" class="k-icon" data-close aria-label="Close"><i class="ri-close-line"></i></button></div>
            <div class="k-dialog-body" id="mBody"></div>
        </div>
    </div>
</div>

<script>
    window.KIOSK_BOOT = @json($boot);
    window.KIOSK_USER = @json(['id' => auth()->id(), 'name' => auth()->user()->name]);
    window.KIOSK_URLS = {
        ping: @json(route('attendance.kiosk.ping')),
        data: @json(route('attendance.kiosk.data')),
        sync: @json(route('attendance.kiosk.sync')),
        csrf: @json(route('refresh.csrf')),
        login: @json(route('login')),
        board: @json(route('attendance.board')),
        kiosk: @json(route('attendance.kiosk')),
        sw: @json(asset('pos-sw.js')),
    };
</script>
<script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
<script src="{{ asset('js/attendance-kiosk.js') }}?v={{ @filemtime(public_path('js/attendance-kiosk.js')) }}"></script>
</body>
</html>
