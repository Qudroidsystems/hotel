<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ isset($title) ? $title . ' — ' : '' }}{{ $brand ?? config('hotel.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="description" content="Book a room at {{ config('hotel.name') }}{{ config('hotel.booking.address') ? ', ' . config('hotel.booking.address') : '' }}. {{ config('hotel.booking.welcome') }}">
    <meta name="theme-color" content="#14213d">
    <meta name="robots" content="{{ ($noindex ?? false) ? 'noindex, nofollow' : 'index, follow' }}">
    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <link href="{{ asset('vendor/fonts/fonts.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <style>
        :root {
            --ink: #14213d; --ink-2: #3c4a66; --muted: #66748a;
            --lagoon: #0f766e; --lagoon-d: #0b5c56; --lagoon-l: #e3f1ee;
            --paper: #f4f7f6; --card: #ffffff; --line: #d6e0dc;
            --brass: #b7832f; --brass-l: #f3e7d1;
            --bad: #b42318; --bad-l: #fde8e6; --ok: #146c43; --ok-l: #e1f3e8; --warn: #8a5a00; --warn-l: #fff2d6;
            --serif: 'Playfair Display', Georgia, serif;
            --sans: 'DM Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            --r: 14px;
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body { margin: 0; background: var(--paper); color: var(--ink); font: 16px/1.55 var(--sans); }
        a { color: var(--lagoon); }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible { outline: 3px solid #f2b84b; outline-offset: 2px; }
        img { max-width: 100%; display: block; }
        .wrap { max-width: 1040px; margin: 0 auto; padding: 0 20px; }
        .topbar { background: var(--ink); color: #fff; }
        .topbar .wrap { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 64px; }
        .brand { display: flex; align-items: center; gap: 10px; color: #fff; text-decoration: none; font-family: var(--serif); font-size: 21px; font-weight: 700; letter-spacing: .01em; }
        .brand img { height: 30px; width: auto; }
        .topbar nav a { color: #d7e2f3; text-decoration: none; font-size: 14.5px; font-weight: 600; }
        .topbar nav a:hover { color: #fff; }
        h1, h2, h3 { line-height: 1.2; margin: 0; }
        .serif { font-family: var(--serif); }
        .muted { color: var(--muted); }
        .small { font-size: 14px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 50px; padding: 0 22px; border-radius: 12px; border: 0;
            font: 700 16px/1 var(--sans); cursor: pointer; text-decoration: none; background: var(--lagoon); color: #fff; }
        .btn:hover { background: var(--lagoon-d); }
        .btn.quiet { background: transparent; color: var(--ink); border: 1.5px solid var(--line); }
        .btn.quiet:hover { border-color: var(--ink-2); }
        .btn.danger { background: transparent; color: var(--bad); border: 1.5px solid #f1b8b2; }
        .btn[disabled] { opacity: .6; cursor: progress; }
        .field label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; color: var(--ink-2); }
        .field input, .field select, .field textarea { width: 100%; min-height: 50px; border: 1.5px solid var(--line); border-radius: 12px; padding: 12px 14px;
            font: 16px var(--sans); color: var(--ink); background: #fff; }
        .field textarea { min-height: 90px; resize: vertical; }
        .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--lagoon); outline: none; box-shadow: 0 0 0 3px var(--lagoon-l); }
        .notice { border-radius: 12px; padding: 14px 16px; margin: 18px 0; display: flex; gap: 10px; align-items: flex-start; font-size: 15px; }
        .notice i { font-size: 20px; line-height: 1.2; }
        .notice.ok { background: var(--ok-l); color: var(--ok); } .notice.bad { background: var(--bad-l); color: var(--bad); } .notice.warn { background: var(--warn-l); color: var(--warn); }
        .panel { background: var(--card); border: 1px solid var(--line); border-radius: var(--r); padding: 22px; }
        .pill { display: inline-block; font-size: 13px; font-weight: 700; padding: 3px 11px; border-radius: 99px; background: var(--lagoon-l); color: var(--lagoon-d); }
        .pill.warn { background: var(--warn-l); color: var(--warn); } .pill.bad { background: var(--bad-l); color: var(--bad); } .pill.grey { background: #e8edf0; color: var(--ink-2); }

        /* The key tag — the one decorative device on these pages: the stay, as a hotel key fob. */
        .keytag { position: relative; background: var(--ink); color: #fff; border-radius: 22px 22px 22px 70px; padding: 26px 26px 24px 30px;
            box-shadow: inset 0 0 0 3px var(--brass), inset 0 0 0 7px var(--ink), inset 0 0 0 8px rgba(183,131,47,.5); overflow: hidden; }
        .keytag::before { content: ''; position: absolute; left: 22px; bottom: 22px; width: 26px; height: 26px; border-radius: 50%;
            background: var(--paper); box-shadow: inset 0 0 0 3px var(--brass); }
        .keytag .ref { font-size: 13px; color: #c9d4e6; letter-spacing: .04em; }
        .keytag .room { font-family: var(--serif); font-size: 26px; font-weight: 700; margin: 6px 0 14px; color: #fff; }
        .keytag .dates { display: flex; gap: 22px; padding-left: 40px; }
        .keytag .d .lbl { font-size: 13px; color: #c9d4e6; }
        .keytag .d .day { font-family: var(--serif); font-size: 34px; line-height: 1; color: var(--brass-l); }
        .keytag .d .mon { font-size: 14px; color: #e6edf7; }
        .keytag .nights { align-self: center; font-size: 13.5px; color: #c9d4e6; border-left: 1px solid rgba(255,255,255,.2); padding-left: 18px; }

        footer.site { margin-top: 56px; padding: 30px 0 40px; border-top: 1px solid var(--line); color: var(--muted); font-size: 14px; }
        footer.site .wrap { display: flex; flex-wrap: wrap; gap: 10px 28px; justify-content: space-between; }
        @media (max-width: 640px) {
            .wrap { padding: 0 16px; }
            .keytag .dates { gap: 10px 22px; padding-left: 36px; flex-wrap: wrap; }
            .keytag .nights { flex-basis: 100%; border-left: 0; padding-left: 0; border-top: 1px solid rgba(255,255,255,.18); padding-top: 8px; }
            .keytag .d .day { font-size: 28px; }
            .hide-sm { display: none !important; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
    </style>
    @stack('head')
    <link href="{{ asset('qlouds/qlouds.css') }}?v=1" rel="stylesheet">
</head>
<body>
    <header class="topbar">
        <div class="wrap">
            <a href="{{ isset($brand) ? route('hotels.public') : route('book.index') }}" class="brand">{{ $brand ?? config('hotel.name') }}</a>
            <nav style="display:flex;gap:18px">
                @if (\App\Support\Tenancy\CurrentHotel::multi() && ! isset($brand))<a href="{{ route('hotels.public') }}"><i class="ri-hotel-line"></i> All hotels</a>@endif
                <a href="{{ route('stay.find') }}"><i class="ri-key-2-line"></i> My booking</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="site">
        <div class="wrap">
            @isset($brand)
                {{-- The group booking site: no single hotel's details --}}
                <div><strong style="color:var(--ink)">{{ $brand }}</strong></div>
                <div class="w-100" style="flex-basis:100%;text-align:center;margin-top:8px">@include('partials.qlouds-credit')</div>
            @else
            <div><strong style="color:var(--ink)">{{ config('hotel.name') }}</strong>@if (config('hotel.booking.address'))<br>{{ config('hotel.booking.address') }}@endif</div>
            <div>
                @if (config('hotel.booking.contact_phone'))<div><i class="ri-phone-line"></i> <a href="tel:{{ preg_replace('/[^0-9+]/', '', config('hotel.booking.contact_phone')) }}">{{ config('hotel.booking.contact_phone') }}</a></div>@endif
                @if (config('hotel.booking.contact_email'))<div><i class="ri-mail-line"></i> <a href="mailto:{{ config('hotel.booking.contact_email') }}">{{ config('hotel.booking.contact_email') }}</a></div>@endif
            </div>
            <div class="w-100" style="flex-basis:100%;text-align:center;margin-top:8px">@include('partials.qlouds-credit')</div>
            <div>Check-in from {{ \Illuminate\Support\Carbon::createFromFormat('H:i', config('hotel.booking.check_in_time', '14:00'))->format('g:i A') }}, check-out by {{ \Illuminate\Support\Carbon::createFromFormat('H:i', config('hotel.booking.check_out_time', '12:00'))->format('g:i A') }}</div>
            @endisset
        </div>
    </footer>
    <script>
        // One tap = one booking: lock a form's button once it is sent.
        document.addEventListener('submit', e => {
            const f = e.target; if (f.dataset.sent) { e.preventDefault(); return; }
            f.dataset.sent = '1'; f.querySelectorAll('button[type=submit]').forEach(b => { b.disabled = true; });
        });
        window.addEventListener('pageshow', () => document.querySelectorAll('form[data-sent]').forEach(f => { delete f.dataset.sent; f.querySelectorAll('button').forEach(b => b.disabled = false); }));
    </script>
    @stack('scripts')
</body>
</html>
