<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pagetitle }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; margin: 0; padding: 24px; color: #0f172a; }
        .slip { max-width: 340px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 24px; text-align: center; border: 1px dashed #94a3b8; }
        .hotel { font-size: 13px; text-transform: uppercase; letter-spacing: .12em; color: #64748b; }
        h1 { font-size: 20px; margin: 6px 0 14px; }
        .label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: .08em; margin-top: 14px; }
        .ssid { font-size: 18px; font-weight: 600; }
        .code { font-family: ui-monospace, Menlo, monospace; font-size: 30px; font-weight: 800; letter-spacing: .14em; margin: 4px 0; }
        .small { font-size: 12.5px; color: #475569; line-height: 1.5; }
        button { margin-top: 18px; padding: 8px 18px; border-radius: 8px; border: 0; background: #0f172a; color: #fff; font-size: 14px; cursor: pointer; }
        @media print { body { background: #fff; padding: 0; } button { display: none; } .slip { border: 1px dashed #000; } }
    </style>
</head>
<body>
    <div class="slip">
        <div class="hotel">{{ config('hotel.name') }}</div>
        <h1>Your Wi-Fi</h1>
        <div class="label">Network</div>
        <div class="ssid">{{ $ssid }}</div>
        <div class="label">Code</div>
        <div class="code">{{ $pass->code }}</div>
        <p class="small">{{ $pass->holder }}{{ $pass->room_number ? ' · Room ' . $pass->room_number : '' }}<br>
            {{ $pass->devices }} device{{ $pass->devices === 1 ? '' : 's' }} · {{ $pass->speed_label }}{{ $pass->data_cap_mb ? ' · ' . \App\Models\Network\NetworkPlan::size($pass->data_cap_mb) . ' a day' : '' }}<br>
            Valid until {{ $pass->expires_at->format('D j M, g:i A') }}</p>
        <p class="small">Join "{{ $ssid }}", then type the code on the page that opens.</p>
        <button type="button" onclick="window.print()">Print</button>
    </div>
</body>
</html>
