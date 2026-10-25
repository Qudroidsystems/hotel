{{-- "Powered by Qlouds · Developed by Qudroid Systems", in page footers. Needs public/qlouds/qlouds.css. --}}
<span class="qlouds-credit{{ ($onDark ?? false) ? ' on-dark' : '' }}">
    <span>Powered by @include('partials.qlouds-logo')</span>
    <span class="sep" aria-hidden="true">·</span>
    <span>Developed by <a href="{{ config('hotel.vendor_url') }}" target="_blank" rel="noopener noreferrer"><strong>{{ config('hotel.vendor', 'Qudroid Systems') }}</strong></a></span>
</span>
