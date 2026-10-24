@extends('layouts.guest')

@section('content')
<section class="hero">
    <div class="wrap">
        <h1 class="serif">{{ config('hotel.name') }}</h1>
        <p class="lede">{{ config('hotel.booking.welcome') ?: 'Book your room directly with us.' }}{{ config('hotel.booking.address') ? ' ' . config('hotel.booking.address') . '.' : '' }}</p>
        @include('rooms.partials.flash-guest')
        @if (config('hotel.booking.enabled', true))
            <div class="panel searchpanel">@include('booking._search', ['q' => $q])</div>
            <p class="small hero-note">Best rate when you book here. @if ((float) config('hotel.booking.deposit_percent') > 0){{ rtrim(rtrim(number_format(config('hotel.booking.deposit_percent'), 1), '0'), '.') }}% deposit to confirm, the rest when you stay. @else Nothing to pay now — you pay at the hotel.@endif</p>
        @else
            <div class="notice warn"><i class="ri-information-line"></i><div>We aren’t taking online bookings right now. Call or email us below and we’ll find you a room.</div></div>
        @endif
    </div>
</section>

<section class="wrap" aria-labelledby="rooms-h">
    <h2 id="rooms-h" class="serif section-h">Rooms</h2>
    @forelse ($types as $type)
        @include('booking._room', ['type' => $type, 'action' => route('book.search', $q) . '#t' . $type->id, 'actionLabel' => 'Check dates'])
    @empty
        <p class="muted">Rooms will be listed here soon.</p>
    @endforelse
</section>

@if (config('hotel.booking.policies'))
    <section class="wrap" aria-labelledby="pol-h">
        <h2 id="pol-h" class="serif section-h">Good to know</h2>
        <ul class="policies">@foreach (config('hotel.booking.policies') as $p)<li>{{ $p }}</li>@endforeach
            @if ((int) config('hotel.booking.cancel_hours') > 0)<li>Free cancellation online until {{ config('hotel.booking.cancel_hours') }} hours before check-in.</li>@endif</ul>
    </section>
@endif
@endsection

@push('head')
<style>
    .hero { background: linear-gradient(180deg, var(--ink) 0, var(--ink) 62%, var(--paper) 62%); color: #fff; padding: 44px 0 10px; }
    .hero h1 { font-size: clamp(38px, 7vw, 68px); font-weight: 700; letter-spacing: -.01em; max-width: 16ch; }
    .hero .lede { color: #c9d4e6; font-size: 18px; margin: 10px 0 26px; max-width: 56ch; }
    .searchpanel { box-shadow: 0 14px 40px rgba(20,33,61,.18); border: 0; color: var(--ink); }
    .hero-note { color: var(--muted); margin: 12px 2px 0; }
    .section-h { font-size: 30px; margin: 44px 0 18px; }
    .policies { margin: 0; padding-left: 20px; color: var(--ink-2); max-width: 70ch; }
    .policies li { margin: 4px 0; }
    @media (max-width: 640px) { .hero { padding-top: 30px; background: linear-gradient(180deg, var(--ink) 0, var(--ink) 45%, var(--paper) 45%); } .hero .lede { font-size: 16px; } }
</style>
@endpush
