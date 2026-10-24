@extends('layouts.guest', ['title' => 'Find my booking', 'noindex' => true])

@section('content')
<div class="wrap narrow">
    <h1 class="serif">Find my booking</h1>
    <p class="muted">Enter the booking reference from your confirmation, and the email or phone number you booked with.</p>
    @include('rooms.partials.flash-guest')
    <form method="POST" action="{{ route('stay.lookup') }}" class="panel">@csrf
        <div class="field"><label for="reference">Booking reference</label><input id="reference" name="reference" value="{{ old('reference') }}" required maxlength="20" autocapitalize="characters" placeholder="e.g. SH-7K3M9Q"></div>
        <div class="field"><label for="contact">Email or phone</label><input id="contact" name="contact" value="{{ old('contact') }}" required maxlength="150" autocomplete="email"></div>
        <button class="btn" type="submit"><i class="ri-key-2-line"></i> Open my booking</button>
    </form>
    <p class="small muted">No booking yet? <a href="{{ route('book.index') }}">See rooms and prices</a>.</p>
</div>
@endsection

@push('head')
<style>
    .narrow { max-width: 520px; padding-top: 36px; }
    .narrow h1 { font-size: 34px; margin-bottom: 8px; }
    .narrow .panel { display: grid; gap: 14px; margin: 20px 0; }
</style>
@endpush
