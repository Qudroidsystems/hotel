@php $r = $reservation; $g = $r->guest; @endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking {{ $r->reference }} | {{ config('hotel.name') }}</title>
    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, Roboto, Arial, sans-serif; color: #0f172a; background: #f1f5f9; margin: 0; padding: 24px; }
        .sheet { max-width: 760px; margin: 0 auto; background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 6px 24px rgba(15,35,66,.12); }
        .head { background: linear-gradient(135deg, #0f2342, #1e4a7e 55%, #0d9488); color: #fff; padding: 26px 30px; display: flex; justify-content: space-between; gap: 16px; align-items: center; }
        .head img { height: 46px; background: #fff; border-radius: 8px; padding: 4px; }
        .head h1 { font-size: 20px; margin: 0; }
        .head p { margin: 4px 0 0; opacity: .8; font-size: 13px; }
        .body { padding: 26px 30px; }
        .ref { font-family: ui-monospace, Menlo, monospace; font-size: 26px; font-weight: 800; letter-spacing: 2px; color: #1e4a7e; }
        .grid { display: grid; grid-template-columns: 1fr 180px; gap: 24px; align-items: start; }
        .qr { border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px; text-align: center; font-size: 11px; color: #64748b; }
        .qr #qr { display: flex; justify-content: center; margin-bottom: 6px; }
        dl { display: grid; grid-template-columns: 130px 1fr; gap: 6px 14px; margin: 16px 0 0; font-size: 14px; }
        dt { color: #64748b; } dd { margin: 0; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 22px; font-size: 14px; }
        th { text-align: left; background: #f8fafc; color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; padding: 9px 10px; border-bottom: 1px solid #e2e8f0; }
        td { padding: 10px; border-bottom: 1px solid #f1f5f9; }
        .num { text-align: right; }
        .total td { font-weight: 800; font-size: 16px; border-bottom: none; }
        .status { display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; color: #fff; }
        .note { margin-top: 20px; font-size: 12.5px; color: #475569; background: #f8fafc; border-radius: 10px; padding: 12px 14px; }
        .foot { padding: 14px 30px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; display: flex; justify-content: space-between; }
        .bar { max-width: 760px; margin: 0 auto 14px; display: flex; gap: 8px; justify-content: flex-end; }
        .bar button, .bar a { border: none; background: #0f2342; color: #fff; padding: 9px 16px; border-radius: 9px; font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: none; }
        .bar a { background: #64748b; }
        @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } dl { grid-template-columns: 110px 1fr; } body { padding: 10px; } }
        @media print { body { background: #fff; padding: 0; } .bar { display: none; } .sheet { box-shadow: none; border-radius: 0; }
            .head, .status { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <div class="bar">
        <a href="{{ route('reservations.show', $r) }}">Back</a>
        <button type="button" onclick="window.print()">Print / save as PDF</button>
    </div>
    <div class="sheet">
        <div class="head">
            <div>
                <h1>Booking confirmation</h1>
                <p>{{ config('hotel.name') }} · {{ config('hotel.tagline') }}</p>
            </div>
            <img src="{{ asset(config('hotel.logo')) }}" alt="{{ config('hotel.name') }}">
        </div>
        <div class="body">
            <div class="grid">
                <div>
                    <div style="font-size:13px;color:#64748b">Booking reference</div>
                    <div class="ref">{{ $r->reference }}</div>
                    <span class="status" style="background: {{ $r->status->color() }}">{{ $r->status->label() }}</span>
                    <dl>
                        <dt>Guest</dt><dd>{{ $g->full_name }}</dd>
                        <dt>Check-in</dt><dd>{{ $r->check_in_date->format('l j F Y') }}{{ $r->expected_arrival_time ? ' · around ' . \Carbon\Carbon::parse($r->expected_arrival_time)->format('g:i A') : '' }}</dd>
                        <dt>Check-out</dt><dd>{{ $r->check_out_date->format('l j F Y') }}</dd>
                        <dt>Length of stay</dt><dd>{{ $r->nights }} night{{ $r->nights === 1 ? '' : 's' }}</dd>
                        <dt>Guests</dt><dd>{{ $r->adults }} adult{{ $r->adults === 1 ? '' : 's' }}{{ $r->children ? ', ' . $r->children . ' child' . ($r->children === 1 ? '' : 'ren') : '' }}</dd>
                    </dl>
                </div>
                <div class="qr">
                    <div id="qr"></div>
                    Show this code at reception for a faster check-in.
                </div>
            </div>

            <table>
                <thead><tr><th>Room</th><th>Guests</th><th class="num">Amount</th></tr></thead>
                <tbody>
                    @foreach ($r->rooms as $line)
                        <tr>
                            <td>{{ $line->roomType->name }}{{ $line->room ? ' · Room ' . $line->room->number : '' }}</td>
                            <td>{{ $line->adults }}{{ $line->children ? ' + ' . $line->children : '' }}</td>
                            <td class="num">@money($line->amount)</td>
                        </tr>
                    @endforeach
                    @if ($r->discount_amount > 0)
                        <tr><td colspan="2">Discount</td><td class="num">−@money($r->discount_amount)</td></tr>
                    @endif
                    <tr class="total"><td colspan="2">Total for room charges</td><td class="num">@money($r->total_amount)</td></tr>
                </tbody>
            </table>

            @if ($r->special_requests)
                <div class="note"><strong>Your requests:</strong> {{ $r->special_requests }}</div>
            @endif
            <div class="note">Please bring a valid photo ID. This confirmation does not open room doors; your room key is issued at check-in.</div>
        </div>
        <div class="foot"><span>Issued {{ now()->format('j M Y, g:i A') }}</span><span>{{ config('hotel.name') }}</span></div>
    </div>

    <script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
    <script>
        if (window.QRCode) new QRCode(document.getElementById('qr'), { text: @json(route('reservations.qr', $r->qr_token)), width: 150, height: 150, correctLevel: QRCode.CorrectLevel.M });
    </script>
</body>
</html>
