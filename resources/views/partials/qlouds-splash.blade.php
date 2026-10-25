{{--
    The opening splash, once per browser session: "Powered by Qlouds" plays in the middle and moves down to the
    footer as the hotel's logo and name rise, then "Developed by Qudroid Systems". Include right after <body>, with
    partials.qlouds-head in <head>. $key separates the sign-in page from the signed-in pages.
--}}
@php($splashKey = 'qlouds-splash-' . ($key ?? 'app'))
<div id="qlouds-splash" class="qlouds-splash" data-key="{{ $splashKey }}" aria-hidden="true">
    <div class="hotel">
        <div class="name">{{ config('hotel.name') }}</div>
    </div>
    <div class="stage">
        <span class="powered">Powered by</span>
        @include('partials.qlouds-logo', ['play' => true])
    </div>
    <div class="bar"></div>
    <div class="dev">Developed by <strong>{{ config('hotel.vendor', 'Qudroid Systems') }}</strong></div>
</div>
<script src="{{ asset('qlouds/qlouds.js') }}"></script>
