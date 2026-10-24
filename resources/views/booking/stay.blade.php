@extends('layouts.guest', ['title' => 'Booking ' . $r->reference, 'noindex' => true])

@section('content')
@php
    use App\Enums\ReservationStatus as S;
    use App\Models\Booking\GuestRequest;
    $sym = config('hotel.currency_symbol');
    $m = fn ($v) => $sym . number_format((float) $v, 2);
    $types = $r->rooms->groupBy('room_type_id')->map(fn ($g) => ($g->count() > 1 ? $g->count() . ' × ' : '') . $g->first()->roomType?->name)->implode(', ');
    $roomNos = $r->rooms->pluck('room.number')->filter()->implode(', ');
    $state = match ($r->status) {
        S::Pending    => ['warn', 'Waiting for your deposit', 'ri-time-line'],
        S::Confirmed  => ['ok', 'Confirmed', 'ri-checkbox-circle-line'],
        S::CheckedIn  => ['ok', 'You’re staying with us', 'ri-hotel-bed-line'],
        S::CheckedOut => ['grey', 'Checked out — thank you for staying', 'ri-door-open-line'],
        S::Cancelled  => ['bad', 'Cancelled', 'ri-close-circle-line'],
        S::NoShow     => ['bad', 'Marked as no-show', 'ri-close-circle-line'],
    };
    $showQr = in_array($r->status, [S::Pending, S::Confirmed], true);
@endphp
<div class="wrap stay">
    <div class="head">
        <h1 class="serif">Hello {{ $r->guest?->first_name }}</h1>
        <span class="pill {{ $state[0] === 'ok' ? '' : $state[0] }}"><i class="{{ $state[2] }}"></i> {{ $state[1] }}</span>
    </div>
    @include('rooms.partials.flash-guest')

    @if ($r->status === S::Pending && $r->hold_expires_at)
        <div class="notice warn"><i class="ri-time-line"></i><div>We’re holding your room until <strong>{{ $r->hold_expires_at->format('D j M, g:i A') }}</strong>. Pay the {{ $m($depositDue) }} deposit before then to confirm it — otherwise the room is released.</div></div>
    @endif

    <div class="grid">
        <div class="col">
            @include('booking._keytag', ['in' => $r->check_in_date, 'out' => $r->check_out_date, 'ref' => $r->reference, 'title' => $types,
                'sub' => ($roomNos ? 'Room ' . $roomNos : $r->adults . ' adult' . ($r->adults == 1 ? '' : 's') . ($r->children ? ', ' . $r->children . ' child' . ($r->children == 1 ? '' : 'ren') : ''))])

            @if ($showQr)
                <div class="panel qrbox">
                    <div id="qr" aria-label="Check-in QR code"></div>
                    <div><h2>Your check-in code</h2><p class="muted small">Show this at reception when you arrive — on your phone or printed. It only works for this booking.</p>
                        <button type="button" class="btn quiet" onclick="window.print()"><i class="ri-printer-line"></i> Print</button></div>
                </div>
            @endif

            @if ($canRequest)
                <div class="panel">
                    <h2>Need something?</h2>
                    <p class="muted small">Send a request straight to the right team. For anything urgent, call reception{{ config('hotel.booking.contact_phone') ? ' on ' . config('hotel.booking.contact_phone') : '' }}.</p>
                    <form method="POST" action="{{ route('stay.request', $r->guest_token) }}" class="req">@csrf
                        <div class="chips">
                            @foreach (GuestRequest::TYPES as $k => [$label, $icon])
                                <label class="chip"><input type="radio" name="type" value="{{ $k }}" @checked(old('type') === $k) required><span><i class="{{ $icon }}"></i> {{ $label }}</span></label>
                            @endforeach
                        </div>
                        <div class="field"><label for="details">Details (optional)</label><input id="details" name="details" maxlength="500" value="{{ old('details') }}" placeholder="e.g. 2 extra towels, the AC is dripping"></div>
                        <button class="btn" type="submit"><i class="ri-send-plane-line"></i> Send request</button>
                    </form>
                </div>
            @endif

            @if ($r->guestRequests->isNotEmpty())
                <div class="panel">
                    <h2>Your requests</h2>
                    @foreach ($r->guestRequests as $q)
                        <div class="reqrow">
                            <i class="{{ $q->type_icon }}"></i>
                            <div class="grow"><strong>{{ $q->type_label }}</strong>@if ($q->details)<div class="small muted">{{ $q->details }}</div>@endif
                                @if ($q->staff_note)<div class="small">Reception: {{ $q->staff_note }}</div>@endif
                                <div class="small muted">{{ $q->created_at->format('D j M, g:i A') }}</div></div>
                            <span class="pill {{ ['open' => 'warn', 'in_progress' => '', 'done' => '', 'cancelled' => 'grey'][$q->status] ?? 'grey' }}">{{ $q->status_label }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="col">
            <div class="panel money">
                <h2>Payment</h2>
                <div class="row"><span>{{ in_array($r->status, [S::CheckedIn, S::CheckedOut], true) ? 'Your bill so far' : 'Stay total' }}</span><span>{{ $m(max((float) $r->total_amount, (float) ($r->folio?->charges->sum('total') ?? 0))) }}</span></div>
                <div class="row"><span>Paid</span><span>{{ $m($paid) }}</span></div>
                <div class="row total"><span>{{ $r->status === S::Pending ? 'Deposit due now' : 'Still to pay' }}</span><span>{{ $m($r->status === S::Pending ? $depositDue : $balanceDue) }}</span></div>

                @if ($canPay && ($r->status === S::Pending ? $depositDue : $balanceDue) >= 1)
                    <a href="{{ route('book.pay', $r->guest_token) }}" class="btn wide"><i class="ri-secure-payment-line"></i> Pay {{ $m($r->status === S::Pending ? $depositDue : $balanceDue) }} now</a>
                    <p class="small muted center">Card, bank transfer or USSD on Paystack’s secure page.@if ($testMode) <strong>Test mode — no real money is taken.</strong>@endif</p>
                @endif

                @if ($accounts->isNotEmpty() && in_array($r->status, [S::Pending, S::Confirmed], true) && ($r->status === S::Pending ? $depositDue : $balanceDue) >= 1)
                    <details class="bank" @if (! $canPay) open @endif>
                        <summary>Pay by bank transfer instead</summary>
                        @foreach ($accounts as $a)
                            <div class="acct"><strong>{{ $a->bank_name }}</strong><div class="num">{{ $a->account_number }}</div><div class="small">{{ $a->account_name }}</div></div>
                        @endforeach
                        <p class="small">Use <strong>{{ $r->reference }}</strong> as the transfer reference, then send the receipt to {{ config('hotel.booking.contact_email') ?: 'reception' }}. We confirm your booking once the money arrives.</p>
                    </details>
                @endif

                @if ($r->folio && $r->folio->payments->isNotEmpty())
                    <div class="paid">
                        @foreach ($r->folio->payments as $p)
                            <div class="row small"><span>{{ $p->received_at->format('j M') }} · {{ $p->method->label() }}{{ $p->kind->value === 'refund' ? ' (refund)' : '' }}</span><span>{{ $p->kind->value === 'refund' ? '−' : '' }}{{ $m($p->amount) }}</span></div>
                        @endforeach
                    </div>
                @endif
            </div>

            @if (in_array($r->status, [S::CheckedIn, S::CheckedOut], true) && $r->folio && $r->folio->charges->isNotEmpty())
                <details class="panel bill">
                    <summary><h2 style="display:inline">Bill details</h2></summary>
                    @foreach ($r->folio->charges as $c)
                        <div class="row small"><span>{{ $c->service_date->format('j M') }} · {{ $c->description }}</span><span>{{ $m($c->total) }}</span></div>
                    @endforeach
                </details>
            @endif

            <div class="panel">
                <h2>Your stay</h2>
                <dl class="facts">
                    <dt>Guest</dt><dd>{{ $r->guest?->full_name }}</dd>
                    <dt>Check-in</dt><dd>{{ $r->check_in_date->format('l j F Y') }}, from {{ \Illuminate\Support\Carbon::createFromFormat('H:i', config('hotel.booking.check_in_time', '14:00'))->format('g:i A') }}</dd>
                    <dt>Check-out</dt><dd>{{ $r->check_out_date->format('l j F Y') }}, by {{ \Illuminate\Support\Carbon::createFromFormat('H:i', config('hotel.booking.check_out_time', '12:00'))->format('g:i A') }}</dd>
                    <dt>Guests</dt><dd>{{ $r->adults }} adult{{ $r->adults == 1 ? '' : 's' }}{{ $r->children ? ', ' . $r->children . ' child' . ($r->children == 1 ? '' : 'ren') : '' }}</dd>
                    @if ($r->special_requests)<dt>Your note</dt><dd>{{ $r->special_requests }}</dd>@endif
                </dl>
                @if ($canCancel)
                    <form method="POST" action="{{ route('stay.cancel', $r->guest_token) }}" onsubmit="return confirm('Cancel booking {{ $r->reference }}? This can’t be undone.');">@csrf
                        <button class="btn danger" type="submit">Cancel booking</button>
                        <p class="small muted">Free until {{ $cancelBy->format('D j M, g:i A') }}.@if ($paid > 0) The hotel will refund your payment according to its policy.@endif</p>
                    </form>
                @elseif (in_array($r->status, [S::Pending, S::Confirmed], true))
                    <p class="small muted">To change or cancel now, please contact the hotel.</p>
                @endif
            </div>
        </div>
    </div>
    <p class="small muted keep">Keep this page’s link private — anyone with it can see and change this booking.</p>
</div>
@endsection

@push('head')
<style>
    .stay .head { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 18px; margin: 28px 0 4px; }
    .stay h1 { font-size: 34px; }
    .stay h2 { font-size: 18px; margin-bottom: 10px; }
    .grid { display: grid; grid-template-columns: 1.15fr 1fr; gap: 20px; margin-top: 18px; align-items: start; }
    .col { display: grid; gap: 16px; }
    .qrbox { display: flex; gap: 18px; align-items: center; }
    #qr { background: #fff; padding: 8px; border: 1px solid var(--line); border-radius: 10px; flex: 0 0 auto; }
    #qr img, #qr canvas { width: 150px; height: 150px; }
    .money .row, .bill .row, .paid .row { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; }
    .money .row.total { border-top: 1px solid var(--line); margin-top: 4px; padding-top: 10px; font-weight: 700; font-size: 18px; }
    .btn.wide { width: 100%; margin-top: 12px; }
    .center { text-align: center; }
    .bank { margin-top: 14px; border-top: 1px solid var(--line); padding-top: 12px; }
    .bank summary { cursor: pointer; font-weight: 600; color: var(--lagoon); }
    .acct { margin: 10px 0; padding: 10px 12px; background: var(--paper); border-radius: 10px; }
    .acct .num { font-family: var(--serif); font-size: 24px; letter-spacing: .04em; }
    .paid { margin-top: 12px; border-top: 1px dashed var(--line); padding-top: 6px; color: var(--ink-2); }
    .facts { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; margin: 0 0 16px; font-size: 15px; }
    .facts dt { color: var(--muted); } .facts dd { margin: 0; }
    .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .chip input { position: absolute; opacity: 0; }
    .chip span { display: inline-flex; align-items: center; gap: 6px; padding: 9px 13px; border: 1.5px solid var(--line); border-radius: 99px; font-size: 14.5px; cursor: pointer; background: #fff; }
    .chip input:checked + span { border-color: var(--lagoon); background: var(--lagoon-l); color: var(--lagoon-d); font-weight: 600; }
    .chip input:focus-visible + span { outline: 3px solid #f2b84b; outline-offset: 2px; }
    .req .field { margin-bottom: 12px; }
    .reqrow { display: flex; gap: 12px; align-items: flex-start; padding: 10px 0; border-top: 1px solid var(--line); }
    .reqrow:first-of-type { border-top: 0; }
    .reqrow > i { font-size: 20px; color: var(--lagoon); margin-top: 2px; } .grow { flex: 1; }
    .bill summary { cursor: pointer; list-style: none; }
    .keep { margin-top: 26px; }
    @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } .stay h1 { font-size: 28px; } }
    @media (max-width: 480px) { .qrbox { flex-direction: column; text-align: center; } }
    @media print { .topbar, footer.site, .money, .btn, .req, .notice, .keep, .bank { display: none !important; } body { background: #fff; } }
</style>
@endpush

@if ($showQr)
@push('scripts')
<script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
<script>
    if (window.QRCode) new QRCode(document.getElementById('qr'), { text: @json(route('reservations.qr', $r->qr_token)), width: 150, height: 150, correctLevel: QRCode.CorrectLevel.M });
    else document.getElementById('qr').innerHTML = '<div style="width:150px;padding:20px 6px;text-align:center;font-weight:700">{{ $r->reference }}</div>';
</script>
@endpush
@endif
