<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Room QR cards · {{ config('hotel.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, Roboto, Arial, sans-serif; color: #10201c; background: #eef2f0; margin: 0; padding: 20px; }
        .bar { max-width: 900px; margin: 0 auto 16px; display: flex; gap: 10px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .bar p { margin: 0; font-size: 14px; color: #475569; max-width: 560px; }
        .bar a, .bar button { border: 0; background: #0f766e; color: #fff; padding: 10px 16px; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; text-decoration: none; }
        .bar a { background: #64748b; }
        .sheet { max-width: 900px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
        .card { background: #fff; border-radius: 14px; padding: 18px; text-align: center; border: 1px solid #d6e0dc; break-inside: avoid; }
        .card .hotel { font-size: 12px; letter-spacing: .04em; color: #64748b; }
        .card .room { font-size: 30px; font-weight: 800; margin: 2px 0 8px; }
        .qr { width: 168px; height: 168px; margin: 0 auto; }
        .qr img, .qr canvas { width: 168px !important; height: 168px !important; }
        .card .say { font-size: 14px; margin: 10px 0 0; font-weight: 600; }
        .card .url { font-size: 10.5px; color: #94a3b8; word-break: break-all; margin-top: 6px; }
        @media print { body { background: #fff; padding: 0; } .bar { display: none; } .sheet { gap: 8mm; } .card { border: 1px dashed #94a3b8; } }
    </style>
</head>
<body>
    <div class="bar">
        <p>One card per room. Guests scan it, confirm their surname, then {{ $ordering ? 'order food and drinks to the room, ' : '' }}see their bill and send requests. Each card only works for whoever is checked in to that room.</p>
        <div><a href="{{ url()->previous() }}">Back</a> <button type="button" onclick="window.print()">Print</button></div>
    </div>
    <div class="sheet">
        @foreach ($rooms as $room)
            <div class="card">
                <div class="hotel">{{ config('hotel.name') }}</div>
                <div class="room">Room {{ $room->number }}</div>
                <div class="qr" data-qr="{{ route('room.card', $room->guest_code) }}" role="img" aria-label="QR code for room {{ $room->number }}"></div>
                <p class="say">{{ $ordering ? 'Scan to order to your room' : 'Scan for your stay' }}</p>
                <div class="url">{{ route('room.card', $room->guest_code) }}</div>
            </div>
        @endforeach
    </div>
    <script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
    <script>
        document.querySelectorAll('[data-qr]').forEach(el => {
            if (window.QRCode) new QRCode(el, { text: el.dataset.qr, width: 168, height: 168, correctLevel: QRCode.CorrectLevel.M });
        });
    </script>
</body>
</html>
