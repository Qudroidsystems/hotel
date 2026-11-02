@extends('layouts.guest', ['title' => 'Your tickets · ' . $order->event->title, 'noindex' => true])

@section('content')
@php $e = $order->event; $sym = config('hotel.currency_symbol'); @endphp
<style>
    .mt-head { margin: 26px 0 16px; }
    .mt-head h1 { font-size: clamp(26px, 5vw, 38px); margin: 6px 0; line-height: 1.1; }
    .stubs { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px; }
    .stub { background: #fff; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; text-align: center; break-inside: avoid; }
    .stub .top { background: var(--ink); color: #fff; padding: 12px 14px; text-align: left; }
    .stub .top strong { display: block; font-family: var(--serif); font-size: 18px; }
    .stub .qr { display: inline-block; margin: 16px 0 6px; padding: 8px; border: 1px solid var(--line); border-radius: 10px; }
    .stub .qr img, .stub .qr canvas { display: block; }
    .stub .code { font: 700 15px/1 ui-monospace, Menlo, monospace; letter-spacing: 2px; }
    .stub .bottom { padding: 8px 14px 14px; font-size: 14px; color: var(--muted); border-top: 2px dashed var(--line); margin-top: 12px; }
    .stub.used { opacity: .55; } .stub.void .qr { display: none; }
    @media print { .topbar, footer.site, .noprint { display: none !important; } body { background: #fff; } }
</style>
<div class="wrap">
    <div class="mt-head">
        <span class="pill">{{ $order->number }}</span>
        <h1 class="serif">{{ $e->title }}</h1>
        <div class="muted"><i class="ri-calendar-event-line"></i> {{ $e->when }}@if ($e->hall) &nbsp;·&nbsp; <i class="ri-map-pin-line"></i> {{ $e->hall->name }}@endif</div>
    </div>

    @if (session('success'))<div class="notice ok"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if (session('error'))<div class="notice bad"><i class="ri-error-warning-line"></i><div>{{ session('error') }}</div></div>@endif

    @if ($order->status === 'pending' && $order->expires_at?->isFuture())
        <div class="panel">
            <h3 class="serif" style="margin-top:0">Waiting for your payment</h3>
            <p>{{ $order->quantity }} {{ str('ticket')->plural($order->quantity) }} for {{ $sym }}{{ number_format((float) $order->total, 2) }}, held for you until {{ $order->expires_at->format('g:i A') }}.</p>
            <a class="btn" href="{{ route('tickets.pay', $order->token) }}">Pay {{ $sym }}{{ number_format((float) $order->total, 2) }}</a>
            <p class="small muted">Already paid? It can take a minute to show: <a href="{{ route('tickets.mine', $order->token) }}">refresh</a>.</p>
        </div>
    @elseif (in_array($order->status, ['pending', 'expired'], true))
        <div class="panel">
            <h3 class="serif" style="margin-top:0">This order wasn’t paid in time</h3>
            <p>The tickets were let go. If you were charged, they will appear here shortly — no need to pay again. Otherwise, <a href="{{ route('tickets.shop', $e->id) }}">start a new order</a>.</p>
        </div>
    @elseif ($order->status === 'cancelled')
        <div class="notice bad"><i class="ri-close-circle-line"></i><div>This order was cancelled{{ $order->cancel_reason ? ': ' . $order->cancel_reason : '' }}. Its tickets no longer work.</div></div>
    @else
        <p class="noprint">Show a QR code at the door — each ticket lets one {{ $order->tickets->contains(fn ($t) => $t->type->admits > 1) ? 'booking' : 'person' }} in once. Your tickets are also in your e-mail{{ $order->buyer_email ? ' (' . $order->buyer_email . ')' : '' }}.
            <a href="#" onclick="window.print();return false">Print them</a>.</p>
        <div class="stubs">
            @foreach ($order->tickets as $i => $t)
                <div class="stub {{ $t->status }}">
                    <div class="top"><strong>{{ $t->type->name }}</strong><span class="small">Ticket {{ $i + 1 }} of {{ $order->tickets->count() }}{{ $t->type->admits > 1 ? ' · admits ' . $t->type->admits : '' }}</span></div>
                    <div class="qr" data-code="{{ $t->code }}"></div>
                    <div class="code">{{ $t->code }}</div>
                    <div class="bottom">
                        @if ($t->status === 'used') Used at the door {{ $t->checked_in_at->format('g:i A, D j M') }}
                        @elseif ($t->status === 'void') Cancelled
                        @else {{ $order->buyer_name }} · <a href="{{ route('tickets.public', $t->code) }}">open alone</a>@endif
                    </div>
                </div>
            @endforeach
        </div>
        @if ($e->ticket_info)<div class="panel" style="margin-top:18px;white-space:pre-line">{{ $e->ticket_info }}</div>@endif
    @endif
</div>
<script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
<script>
    document.querySelectorAll('.stub:not(.void) .qr').forEach(function (el) {
        if (window.QRCode) new QRCode(el, { text: el.dataset.code, width: 170, height: 170, correctLevel: QRCode.CorrectLevel.M });
        else el.textContent = el.dataset.code;
    });
</script>
@endsection
