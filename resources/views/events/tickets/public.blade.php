@extends('billing.print-layout')
@section('title', 'Ticket · ' . $ticket->event->title)
@section('heading', $ticket->event->title)
@section('width', '420px')
@section('back', url('/'))
@section('styles')
    .pass { text-align: center; }
    .pass h2 { font-size: 22px; margin: 4px 0 2px; }
    .pass .qr { display: inline-block; padding: 10px; border: 1px solid #e2e8f0; border-radius: 12px; margin: 14px 0 6px; }
    .pass .qr img, .pass .qr canvas { display: block; }
    .pass .void { color: #b91c1c; font-weight: 800; letter-spacing: 1px; margin-top: 8px; }
    .pass .cols { justify-content: center; text-align: left; margin-top: 14px; }
@endsection

@section('body')
    @php $e = $ticket->event; @endphp
    <div class="pass">
        <div class="muted">{{ $e->type_label }}</div>
        <h2>{{ $ticket->type->name }}{{ $ticket->type->admits > 1 ? ' · admits ' . $ticket->type->admits : '' }}</h2>
        @if ($ticket->status === 'void' || $e->status === 'cancelled')
            <div class="void">{{ $e->status === 'cancelled' ? 'THIS EVENT WAS CANCELLED' : 'THIS TICKET WAS CANCELLED' }}</div>
        @else
            <div class="qr" id="tkQr" data-code="{{ $ticket->code }}"></div>
        @endif
        <div class="ref">{{ $ticket->code }}</div>
        @if ($ticket->status === 'used')<div class="muted">Used at the door {{ $ticket->checked_in_at->format('g:i A, D j M') }}</div>@endif
        <div class="cols">
            <div><div class="lbl">When</div><strong>{{ $e->when }}</strong></div>
            <div><div class="lbl">Where</div><strong>{{ $e->hall?->name }}</strong></div>
            <div><div class="lbl">Name</div><strong>{{ $ticket->order->buyer_name }}</strong></div>
        </div>
        @if ($e->ticket_info)<div class="note" style="white-space:pre-line;text-align:left">{{ $e->ticket_info }}</div>@endif
        <div class="note">Show this QR code at the door. Each ticket lets you in once.</div>
    </div>
    <script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
    <script>
        (function () {
            var el = document.getElementById('tkQr');
            if (el && window.QRCode) new QRCode(el, { text: el.dataset.code, width: 180, height: 180, correctLevel: QRCode.CorrectLevel.M });
        })();
    </script>
@endsection
