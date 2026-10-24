@extends('billing.print-layout')
@section('title', 'Visitor pass · ' . $visitor->full_name)
@section('heading', 'Visitor pass')
@section('width', '420px')
@section('back', route('visitors.index'))
@section('styles')
    .pass { text-align: center; }
    .pass h2 { font-size: 22px; margin: 4px 0 2px; }
    .pass .qr { display: inline-block; padding: 10px; border: 1px solid #e2e8f0; border-radius: 12px; margin: 14px 0 6px; }
    .pass .qr img, .pass .qr canvas { display: block; }
    .pass .until { font-size: 15px; margin-top: 8px; }
    .pass .void { color: #b91c1c; font-weight: 800; letter-spacing: 1px; }
    .pass .cols { justify-content: center; text-align: left; margin-top: 14px; }
@endsection

@section('body')
    <div class="pass">
        <div class="muted">{{ $visitor->purpose_label }}</div>
        <h2>{{ $visitor->full_name }}</h2>
        @if ($visitor->company)<div class="muted">{{ $visitor->company }}</div>@endif
        <div class="qr" id="passQr" data-code="{{ $visitor->pass_code }}"></div>
        <div class="ref">{{ $visitor->pass_code }}</div>
        @if ($visitor->isOnSite())
            <div class="until">Valid until <strong>{{ $visitor->expected_out_at?->format('g:i A, D j M') ?? 'signed out' }}</strong></div>
        @else
            <div class="until void">SIGNED OUT {{ $visitor->signed_out_at->format('g:i A, j M') }} · NOT VALID</div>
        @endif
        <div class="cols">
            <div><div class="lbl">Visiting</div><strong>{{ $visitor->visiting }}</strong></div>
            <div><div class="lbl">Signed in</div><strong>{{ $visitor->signed_in_at->format('g:i A') }}</strong></div>
            @if ($visitor->party_size > 1)<div><div class="lbl">People</div><strong>{{ $visitor->party_size }}</strong></div>@endif
            @if ($visitor->vehicle_plate)<div><div class="lbl">Vehicle</div><strong>{{ $visitor->vehicle_plate }}</strong></div>@endif
        </div>
        <div class="note">Keep this pass with you and hand it back at the gate or front desk when you leave.</div>
    </div>
    <script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
    <script>
        (function () {
            var el = document.getElementById('passQr');
            if (window.QRCode) new QRCode(el, { text: el.dataset.code, width: 150, height: 150, correctLevel: QRCode.CorrectLevel.M });
            else el.textContent = el.dataset.code;
        })();
    </script>
@endsection
