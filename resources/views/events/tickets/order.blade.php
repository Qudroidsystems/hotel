@extends('billing.print-layout')
@section('title', 'Tickets ' . $order->number)
@section('heading', 'Tickets · ' . $order->event->title)
@section('width', '760px')
@section('back', route('events.show', $order->event))
@section('styles')
    .tk { display: flex; gap: 18px; align-items: center; border: 1px dashed #94a3b8; border-radius: 14px; padding: 14px 16px; margin-top: 14px; page-break-inside: avoid; }
    .tk .qr { flex: 0 0 auto; }
    .tk .qr img, .tk .qr canvas { display: block; }
    .tk h3 { margin: 0 0 4px; font-size: 17px; }
    .void { color: #b91c1c; font-weight: 800; letter-spacing: 1px; }
    .acts { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
    .acts form { display: inline; }
    .acts button, .acts a { border: 1px solid #cbd5e1; background: #fff; color: #0f172a; padding: 7px 12px; border-radius: 8px; font-size: 13px; cursor: pointer; text-decoration: none; }
    @media print { .acts { display: none; } }
@endsection

@section('body')
    @php
        $e = $order->event;
        $sym = config('hotel.currency_symbol');
    @endphp
    @if (session('success'))<div class="note" style="background:#ecfdf5;color:#065f46">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="note" style="background:#fef2f2;color:#991b1b">{{ session('error') }}</div>@endif
    <div class="cols">
        <div><div class="lbl">Order</div><strong class="ref">{{ $order->number }}</strong></div>
        <div><div class="lbl">When</div><strong>{{ $e->when }}</strong></div>
        <div><div class="lbl">Where</div><strong>{{ $e->hall?->name }}</strong></div>
        <div><div class="lbl">Buyer</div><strong>{{ $order->buyer_name }}</strong></div>
        <div><div class="lbl">Paid</div><strong>{{ $sym . number_format((float) $order->total, 2) }}</strong> <span class="muted">{{ $order->method_label }}</span></div>
    </div>
    @if ($order->isCancelled())<p class="void">CANCELLED {{ $order->cancelled_at->format('j M Y, g:i A') }}: {{ $order->cancel_reason }}. THESE TICKETS DON'T WORK.</p>@endif
    @if ($e->ticket_info)<div class="note" style="white-space:pre-line">{{ $e->ticket_info }}</div>@endif

    @foreach ($order->tickets as $i => $t)
        <div class="tk">
            <div class="qr" data-code="{{ $t->code }}"></div>
            <div>
                <div class="muted">Ticket {{ $i + 1 }} of {{ $order->tickets->count() }}</div>
                <h3>{{ $t->type->name }}{{ $t->type->admits > 1 ? ' · admits ' . $t->type->admits : '' }}</h3>
                <div class="ref">{{ $t->code }}</div>
                <div class="muted">{{ $e->title }} · {{ $e->when }}</div>
                @if ($t->status === 'used')<div class="muted">Used {{ $t->checked_in_at->format('g:i A, j M') }}</div>@elseif ($t->status === 'void')<div class="void">VOID</div>@endif
                <div class="muted" style="word-break:break-all">{{ $t->url }}</div>
            </div>
        </div>
    @endforeach

    @auth
        @if ($order->emailed_at)<p class="muted">E-mailed to {{ $order->buyer_email }} {{ $order->emailed_at->diffForHumans() }}.</p>@endif
        <div class="acts">
            @if ($order->buyer_phone)<a href="https://wa.me/{{ preg_replace('/\D/', '', $order->buyer_phone) }}?text={{ rawurlencode('Your tickets for ' . $e->title . ' (' . $e->when . '): ' . $order->tickets->pluck('url')->implode(' ')) }}" target="_blank" rel="noopener">Send on WhatsApp</a>@endif
            @can('Sell event tickets')
                @if ($order->status === 'paid')
                    <form method="POST" action="{{ route('events.tickets.email', $order) }}" @unless ($order->buyer_email) onsubmit="var m = prompt('E-mail address for the tickets:'); if (!m) return false; this.email.value = m;" @endunless>@csrf<input type="hidden" name="email"><button type="submit">{{ $order->emailed_at ? 'E-mail again' : 'E-mail the tickets' }}</button></form>
                @endif
                @if ($order->status === 'paid')
                    <form method="POST" action="{{ route('events.tickets.cancel', $order) }}" onsubmit="var r = prompt('Why is this order cancelled?'); if (!r) return false; this.reason.value = r;">@csrf<input type="hidden" name="reason"><button type="submit">Cancel order{{ $order->isRoomCharge() ? ' (voids the room charge)' : ' and refund' }}</button></form>
                @endif
            @endcan
        </div>
        <p class="muted">Sold by {{ $order->seller?->name ?? ($order->channel === 'app' ? 'the guest, in the app' : ($order->channel === 'web' ? 'online (Paystack)' : '—')) }}, {{ $order->paid_at?->format('j M Y, g:i A') ?? ($order->status === 'pending' ? 'waiting for payment' : 'not paid in time') }}{{ (float) $order->discount > 0 ? ' · promo ' . ($order->promo?->code ?? '') . ' saved ' . config('hotel.currency_symbol') . number_format((float) $order->discount, 2) : '' }}{{ $order->reservation ? ' · charged to booking ' . $order->reservation->reference : '' }}</p>
    @endauth

    <script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
    <script>
        document.querySelectorAll('.qr[data-code]').forEach(function (el) {
            if (window.QRCode) new QRCode(el, { text: el.dataset.code, width: 120, height: 120, correctLevel: QRCode.CorrectLevel.M });
        });
    </script>
@endsection
