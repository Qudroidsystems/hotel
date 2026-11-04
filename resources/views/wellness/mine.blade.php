@extends('layouts.guest', ['title' => 'Your booking · ' . $b->venue->name, 'noindex' => true])

@section('content')
@php $sym = config('hotel.currency_symbol'); @endphp
<div class="wrap" style="max-width:640px">
    <div style="margin:26px 0 14px">
        <span class="pill">{{ $b->number }}</span>
        <h1 class="serif" style="font-size:clamp(26px,5vw,38px);margin:8px 0 4px;line-height:1.1">{{ $b->what }}</h1>
        <div class="muted"><i class="ri-map-pin-line"></i> {{ $b->venue->name }}, {{ config('hotel.name') }}</div>
    </div>
    @if (session('success'))<div class="notice ok"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if (session('error'))<div class="notice bad"><i class="ri-error-warning-line"></i><div>{{ session('error') }}</div></div>@endif

    @if ($b->status === 'pending' && $b->expires_at?->isFuture())
        <div class="panel">
            <h3 class="serif" style="margin-top:0">Waiting for your payment</h3>
            <p>Your time is held until {{ $b->expires_at->format('g:i A') }}.</p>
            <a class="btn" href="{{ route('wellness.pay', $b->token) }}">Pay {{ $sym }}{{ number_format((float) $b->total, 2) }}</a>
            <p class="small muted">Already paid? It can take a minute to show: <a href="{{ route('wellness.mine', $b->token) }}">refresh</a>.</p>
        </div>
    @elseif (in_array($b->status, ['pending', 'expired'], true))
        <div class="panel"><h3 class="serif" style="margin-top:0">This booking wasn’t paid in time</h3>
            <p>The time was let go. If you were charged it will show here shortly — no need to pay again. Otherwise <a href="{{ route('wellness.public') }}">book again</a>.</p></div>
    @endif

    <div class="panel">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap">
            <div>
                <div style="font-size:20px;font-weight:700">{{ $svc->when($b->starts_at) }} – {{ $b->ends_at->format('g:i A') }}</div>
                <div class="muted small">{{ $b->people }} {{ str('person')->plural($b->people) }}{{ $b->therapist ? ' · with ' . $b->therapist->name : '' }}</div>
            </div>
            <span class="pill {{ in_array($b->status, ['cancelled', 'expired', 'no_show'], true) ? 'grey' : '' }}">{{ $b->status_label }}</span>
        </div>
        <p style="margin:12px 0 0">{{ (float) $b->total > 0 ? $sym . number_format((float) $b->total, 2) . ' · ' . ($b->paid_at ? 'paid online' : 'pay when you arrive') : 'Free' }}</p>
        @if ($b->staff_note)<p class="small">{{ $b->staff_note }}</p>@endif
        <p class="small muted">Please arrive 10 minutes early.{{ config('hotel.booking.contact_phone') ? ' To change your booking call ' . config('hotel.booking.contact_phone') . '.' : '' }}</p>
        @if ($svc->guestCanCancel($b))
            <form method="POST" action="{{ route('wellness.cancel', $b->token) }}" onsubmit="return confirm('Cancel this booking?')">@csrf<button class="btn ghost small">Cancel booking</button></form>
        @endif
    </div>
</div>
@endsection
