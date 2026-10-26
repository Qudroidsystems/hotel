@extends('layouts.guest')

@php
    $who = \App\Support\Tenancy\CurrentHotel::multi() ? (config('hotel.group_name') ?: config('hotel.name')) : config('hotel.name');
    $email = config('hotel.booking.contact_email');
    $deposit = (float) config('hotel.booking.deposit_percent', 30);
    $hold = (int) config('hotel.booking.hold_hours', 24);
    $cancel = (int) config('hotel.booking.cancel_hours', 48);
@endphp

@section('content')
<article class="wrap legal">
    <h1 class="serif">Booking terms</h1>
    <p class="muted small">Last updated 26 October 2026</p>

    <p>These terms apply when you book a room with {{ $who }} on this website or in our app.@if (\App\Support\Tenancy\CurrentHotel::multi()) Each hotel in the group may show its own deposit, cancellation and check-in times on its booking page; those apply to that hotel.@endif</p>

    <h2 class="serif">Prices and payment</h2>
    <ul>
        <li>Prices are in {{ config('hotel.currency', 'NGN') }} and are shown before you book, night by night.@if (config('hotel.prices_include_tax')) They include VAT and other taxes.@endif</li>
        @if ($deposit > 0)
            <li>A {{ rtrim(rtrim(number_format($deposit, 1), '0'), '.') }}% deposit confirms the booking. Unpaid bookings are held for {{ $hold }} hours, then released.</li>
            <li>The rest is paid at the hotel, at check-in or check-out.</li>
        @else
            <li>Nothing is paid online; you pay at the hotel.</li>
        @endif
        <li>Card payments go through Paystack; we never see your card details.</li>
        <li>Promo codes and special rates apply only on the dates and rooms they were offered for.</li>
    </ul>

    <h2 class="serif">Changes and cancellations</h2>
    <ul>
        <li>You can cancel online free of charge until {{ $cancel }} hours before check-in, from the link in your confirmation or in the app.</li>
        <li>Rates marked non-refundable cannot be refunded once paid.</li>
        <li>Refunds of a deposit, where due, go back to the card or account it came from.</li>
        <li>If you do not arrive and have not cancelled, the booking may be marked as a no-show; the hotel's policies below say whether any deposit is returned.</li>
    </ul>

    <h2 class="serif">Your stay</h2>
    <ul>
        <li>Check-in from {{ \Illuminate\Support\Carbon::createFromFormat('H:i', config('hotel.booking.check_in_time', '14:00'))->format('g:i A') }}, check-out by {{ \Illuminate\Support\Carbon::createFromFormat('H:i', config('hotel.booking.check_out_time', '12:00'))->format('g:i A') }}.</li>
        <li>Please bring a valid ID; the hotel must record it at check-in.</li>
        @foreach ((array) config('hotel.booking.policies', []) as $p)<li>{{ $p }}</li>@endforeach
    </ul>

    <h2 class="serif">Questions</h2>
    <p>@if ($email)Email <a href="mailto:{{ $email }}">{{ $email }}</a>. @endif See also our <a href="{{ route('legal.privacy') }}">privacy policy</a>.</p>
</article>
@endsection

@push('head')
<style>
    .legal { max-width: 760px; padding-top: 36px; }
    .legal h1 { font-size: 40px; margin-bottom: 6px; }
    .legal h2 { font-size: 24px; margin: 30px 0 8px; }
    .legal p, .legal li { color: var(--ink-2); }
    .legal ul { padding-left: 20px; } .legal li { margin-bottom: 8px; }
</style>
@endpush
