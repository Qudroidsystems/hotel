{{-- Store buttons for one app: $app from Website::apps(); $small for the footer. --}}
<div class="app-badges {{ ! empty($small) ? 'sm' : '' }}">
    @if ($app['ios'])
        <a class="app-badge" href="{{ $app['ios'] }}" target="_blank" rel="noopener" aria-label="{{ $app['label'] }} on the App Store"><i class="ri-apple-fill"></i><span><small>Download on the</small>App Store</span></a>
    @endif
    @if ($app['play'])
        <a class="app-badge" href="{{ $app['play'] }}" target="_blank" rel="noopener" aria-label="{{ $app['label'] }} on Google Play"><i class="ri-google-play-fill"></i><span><small>Get it on</small>Google Play</span></a>
    @endif
    @if ($app['apk'])
        <a class="app-badge apk" href="{{ $app['apk'] }}" download aria-label="Download the {{ $app['label'] }} for Android"><i class="ri-android-fill"></i><span><small>Android{{ $app['size'] ? ' · ' . $app['size'] : '' }}</small>Download file</span></a>
    @endif
</div>
