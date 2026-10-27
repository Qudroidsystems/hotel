@extends('layouts.guest', ['title' => 'Room ' . $room->number, 'noindex' => true])

@section('content')
<div class="wrap card-page">
    <div class="panel">
        <div class="plate" aria-hidden="true">{{ $room->number }}</div>
        <h1 class="serif">Welcome to room {{ $room->number }}</h1>
        @include('rooms.partials.flash-guest')
        @if ($stay)
            <p class="muted">{{ $ordering ? 'Order food and drinks to your room, see your bill and ask for anything during your stay.' : 'See your booking and bill, and ask for anything during your stay.' }}
                To keep it private, please confirm who you are.</p>
            <form method="POST" action="{{ route('room.card.verify', $room->guest_code) }}">@csrf
                <div class="field"><label for="name">Your surname, or your booking reference</label>
                    <input id="name" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="family-name" autofocus></div>
                <button class="btn wide" type="submit"><i class="ri-arrow-right-line"></i> Continue</button>
            </form>
        @else
            <p class="muted">This room’s guest page opens once you’ve checked in. If you’re staying here, please ask reception.</p>
            @if (config('hotel.booking.contact_phone'))<a class="btn quiet wide" href="tel:{{ config('hotel.booking.contact_phone') }}"><i class="ri-phone-line"></i> Call reception</a>@endif
        @endif
    </div>
</div>
@endsection

@push('head')
<style>
    .card-page { max-width: 460px; margin-top: 32px; }
    .card-page .panel { text-align: center; }
    .card-page form { text-align: left; margin-top: 14px; }
    .card-page h1 { font-size: 28px; margin: 10px 0 6px; }
    .plate { display: inline-block; min-width: 92px; padding: 10px 18px; border-radius: 14px; background: var(--brass, #b7832f); color: #fff; font-size: 34px; font-weight: 800; letter-spacing: .02em; }
    .btn.wide { width: 100%; margin-top: 8px; }
</style>
@endpush
