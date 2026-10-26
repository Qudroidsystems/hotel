@extends('layouts.guest')

@php
    $who = \App\Support\Tenancy\CurrentHotel::multi() ? (config('hotel.group_name') ?: config('hotel.name')) : config('hotel.name');
    $email = config('hotel.booking.contact_email');
    $phone = config('hotel.booking.contact_phone');
    $updated = '26 October 2026';
@endphp

@section('content')
<article class="wrap legal">
    <h1 class="serif">Privacy policy</h1>
    <p class="muted small">Last updated {{ $updated }}</p>

    <p>{{ $who }} (“we”) runs this website, the {{ $who }} guest app and the staff app. This page explains what personal information we collect, why, how long we keep it and what you can ask us to do with it. We follow the Nigeria Data Protection Act 2023.</p>

    <h2 class="serif">What we collect</h2>
    <ul>
        <li><strong>When you book or stay:</strong> your name, email, phone number, nationality, the dates and room you booked, special requests and arrival time. At check-in the front desk may record an ID type and number, as the law requires of hotels.</li>
        <li><strong>Payments:</strong> deposits and bills paid online go through Paystack. We never see or store your card number; we keep the amount, date and Paystack's reference.</li>
        <li><strong>During your stay:</strong> requests you send (towels, a repair), charges to your room, and messages between you and the hotel.</li>
        <li><strong>Loyalty:</strong> if the hotel runs a loyalty programme, your member number, points and stays.</li>
        <li><strong>Visitors:</strong> the name, phone, ID number and vehicle plate of people visiting guests or staff, at the security desk.</li>
        <li><strong>Staff:</strong> account details, sign-in times, attendance, the work they do in the system, and photos attached to fault reports.</li>
        <li><strong>The apps:</strong> the guest app keeps your bookings on your phone; it has no account or password. The staff app keeps a sign-in token and a notification token on the phone. Neither app tracks your location or shows adverts.</li>
    </ul>

    <h2 class="serif">Why we use it</h2>
    <ul>
        <li>To take and manage your booking, check you in and out, and bill you correctly.</li>
        <li>To send you your confirmation, a payment link, an arrival reminder or a reply to your request by email, SMS or WhatsApp. You can reply STOP to any text to stop them.</li>
        <li>To keep guests, staff and the building safe (visitor book, incident reports).</li>
        <li>To meet our legal duties: tax records, receipts and the guest register.</li>
    </ul>
    <p>We do not sell your information or use it for advertising. We send marketing only if you agreed to it, and you can withdraw at any time.</p>

    <h2 class="serif">Who we share it with</h2>
    <p>Only the services that carry out the work above: Paystack (payments), our email provider, Termii or Twilio (texts and WhatsApp), Google Firebase (staff app notifications) and our hosting provider. Each handles your data only to provide that service. We share information with the authorities only when the law requires it.</p>

    <h2 class="serif">How long we keep it</h2>
    <ul>
        <li>Visitor phone numbers, ID numbers and plates: {{ (int) config('hotel.security.retention_days', 180) }} days after the visit, then cleared automatically.</li>
        <li>Bookings, bills and payments: as long as tax and accounting law requires.</li>
        <li>Guest profiles: until you ask us to delete them, unless a bill is still open.</li>
    </ul>

    <h2 class="serif">Your rights</h2>
    <p>You can ask to see the information we hold about you, have it corrected, have it deleted where we are not required to keep it, or stop receiving messages. Contact us below; we answer within 30 days. If you are not satisfied, you can complain to the Nigeria Data Protection Commission.</p>

    <h2 class="serif">Security</h2>
    <p>The website and apps only connect over HTTPS. Staff see only what their role needs, every change to money or bookings is logged, and stored passwords and service keys are encrypted.</p>

    <h2 class="serif">Contact</h2>
    <p>{{ $who }}@if (config('hotel.booking.address')), {{ config('hotel.booking.address') }}@endif.
        @if ($email) Email <a href="mailto:{{ $email }}">{{ $email }}</a>.@endif
        @if ($phone) Phone {{ $phone }}.@endif</p>
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
