<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff badges | {{ config('hotel.name') }}</title>
    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #e2e8f0; font-family: 'Segoe UI', system-ui, -apple-system, Roboto, Arial, sans-serif; color: #0f172a; }
        .bar { display: flex; gap: 8px; justify-content: center; align-items: center; padding: 14px; flex-wrap: wrap; font-size: 13px; }
        .bar button, .bar a { border: 0; background: #0f2342; color: #fff; padding: 9px 16px; border-radius: 9px; font-weight: 600; font-size: 13px; cursor: pointer; text-decoration: none; }
        .bar a { background: #64748b; }
        .sheet { display: flex; flex-wrap: wrap; gap: 6mm; justify-content: center; padding: 6mm; max-width: 210mm; margin: 0 auto; background: #fff; }
        /* Standard ID card size (CR80): 85.6 × 54 mm */
        .card { width: 85.6mm; height: 54mm; border-radius: 3.2mm; overflow: hidden; position: relative; border: 0.3mm solid #cbd5e1; display: flex; break-inside: avoid; page-break-inside: avoid; }
        .side { width: 26mm; background: linear-gradient(165deg, #0f2342, #1e4a7e 60%, #0d9488); color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: space-between; padding: 3mm 2mm; }
        .side img.logo { width: 18mm; max-height: 10mm; object-fit: contain; background: #fff; border-radius: 1.5mm; padding: 0.8mm; }
        .side img.ph { width: 19mm; height: 19mm; border-radius: 50%; object-fit: cover; border: 0.6mm solid #fff; background: #fff; }
        .side .no { font: 800 7.5pt ui-monospace, Menlo, monospace; letter-spacing: .3mm; }
        .main { flex: 1; padding: 3mm 3mm 2.5mm; display: flex; flex-direction: column; min-width: 0; }
        .hotel { font-size: 6.5pt; text-transform: uppercase; letter-spacing: .4mm; color: #0d9488; font-weight: 800; }
        .name { font-size: 11pt; font-weight: 800; line-height: 1.1; margin-top: 1mm; color: #0f2342; }
        .title { font-size: 7.5pt; color: #475569; margin-top: .6mm; }
        .dept { display: inline-block; margin-top: 1.2mm; font-size: 6.5pt; font-weight: 700; color: #fff; border-radius: 1.2mm; padding: .5mm 1.6mm; align-self: flex-start; }
        .foot { margin-top: auto; display: flex; align-items: flex-end; justify-content: space-between; gap: 2mm; }
        .foot .hint { font-size: 5.8pt; color: #64748b; line-height: 1.25; }
        .qr { width: 21mm; height: 21mm; flex: none; }
        .qr img, .qr canvas { width: 21mm !important; height: 21mm !important; }
        @media print {
            body { background: #fff; } .bar { display: none; } .sheet { padding: 0; gap: 4mm; max-width: none; }
            .card, .side, .dept { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            @page { size: A4; margin: 10mm; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <a href="{{ url()->previous() }}">Back</a>
        <span>{{ $profiles->count() }} badge{{ $profiles->count() === 1 ? '' : 's' }} — print on card stock or PVC sheets, cut and laminate.</span>
        <button type="button" onclick="window.print()">Print</button>
    </div>
    <div class="sheet">
        @foreach ($profiles as $p)
            <div class="card">
                <div class="side">
                    <img class="logo" src="{{ asset(config('hotel.logo')) }}" alt="">
                    <img class="ph" src="{{ $p->user->avatar_url }}" alt="">
                    <div class="no">{{ $p->staff_no }}</div>
                </div>
                <div class="main">
                    <div class="hotel">{{ config('hotel.name') }} · Staff</div>
                    <div class="name">{{ $p->user->name }}</div>
                    <div class="title">{{ $p->job_title ?: ' ' }}</div>
                    @if ($p->department)<span class="dept" style="background: {{ $p->department->color }}">{{ $p->department->name }}</span>@endif
                    <div class="foot">
                        <div class="hint">Scan at the staff entrance to clock in and out.<br>If found, please return to the front desk.<br>Issued {{ $p->badge_issued_at?->format('M Y') }}</div>
                        <div class="qr" data-qr="{{ $p->badge_qr }}"></div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
    <script>
        document.querySelectorAll('[data-qr]').forEach(function (el) {
            new QRCode(el, { text: el.dataset.qr, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M });
        });
    </script>
</body>
</html>
