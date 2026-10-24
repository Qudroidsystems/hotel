<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | {{ config('hotel.name') }}</title>
    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, Roboto, Arial, sans-serif; color: #0f172a; background: #f1f5f9; margin: 0; padding: 24px; }
        .sheet { max-width: @yield('width', '800px'); margin: 0 auto; background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 6px 24px rgba(15,35,66,.12); }
        .head { background: linear-gradient(135deg, #0f2342, #1e4a7e 55%, #0d9488); color: #fff; padding: 22px 28px; display: flex; justify-content: space-between; gap: 16px; align-items: center; }
        .head img { height: 44px; background: #fff; border-radius: 8px; padding: 4px; }
        .head h1 { font-size: 20px; margin: 0; }
        .head p { margin: 4px 0 0; opacity: .85; font-size: 12.5px; }
        .body { padding: 22px 28px; }
        .cols { display: flex; justify-content: space-between; gap: 20px; flex-wrap: wrap; font-size: 13.5px; }
        .cols .lbl { color: #64748b; font-size: 11.5px; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px; }
        .cols strong { font-size: 15px; }
        .ref { font-family: ui-monospace, Menlo, monospace; font-weight: 800; letter-spacing: 1px; color: #1e4a7e; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; font-size: 13.5px; }
        th { text-align: left; background: #f8fafc; color: #475569; font-size: 11.5px; text-transform: uppercase; letter-spacing: .4px; padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
        td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #64748b; font-size: 12px; }
        .totals { margin: 14px 0 0 auto; width: 100%; max-width: 330px; font-size: 14px; }
        .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
        .totals .grand { border-top: 2px solid #0f172a; margin-top: 4px; padding-top: 8px; font-weight: 800; font-size: 17px; }
        .stamp { display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 800; letter-spacing: .5px; color: #fff; }
        .note { margin-top: 18px; font-size: 12px; color: #475569; background: #f8fafc; border-radius: 10px; padding: 10px 14px; }
        .foot { padding: 12px 28px; border-top: 1px solid #e2e8f0; font-size: 11.5px; color: #64748b; display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
        .bar { max-width: @yield('width', '800px'); margin: 0 auto 14px; display: flex; gap: 8px; justify-content: flex-end; }
        .bar button, .bar a { border: none; background: #0f2342; color: #fff; padding: 9px 16px; border-radius: 9px; font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: none; }
        .bar a { background: #64748b; }
        @media (max-width: 600px) { body { padding: 10px; } .body { padding: 16px; } .head { padding: 16px; } }
        @media print { body { background: #fff; padding: 0; } .bar { display: none; } .sheet { box-shadow: none; border-radius: 0; max-width: none; }
            .head, .stamp { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
        @yield('styles')
    </style>
</head>
<body>
    <div class="bar">
        <a href="@yield('back', url()->previous())">Back</a>
        <button type="button" onclick="window.print()">Print / save as PDF</button>
    </div>
    <div class="sheet">
        <div class="head">
            <div><h1>@yield('heading')</h1><p>{{ config('hotel.name') }}{{ config('hotel.tagline') ? ' · ' . config('hotel.tagline') : '' }}</p></div>
            <img src="{{ asset(config('hotel.logo')) }}" alt="{{ config('hotel.name') }}">
        </div>
        <div class="body">@yield('body')</div>
        <div class="foot"><span>Printed {{ now()->format('j M Y, g:i A') }} by {{ auth()->user()->name }}</span><span>{{ config('hotel.name') }}</span></div>
    </div>
</body>
</html>
