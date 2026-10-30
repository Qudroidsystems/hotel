@php use App\Support\Website; $guest = $apps['guest'] ?? null; @endphp
@extends('layouts.site', ['hero' => true, 'title' => 'Our app', 'description' => Website::get('apps.text')])

@section('content')
@include('site.partials.page-hero', ['eyebrow' => 'Get our app', 'title' => Website::get('apps.title'), 'text' => Website::get('apps.text'),
    'img' => null, 'short' => true, 'crumbs' => [['Our app', null]]])

@foreach ($apps as $app)
<section class="section {{ $loop->odd ? '' : 'tint' }}" id="{{ $app['key'] }}">
    <div class="wrap app-band">
        <div>
            <div class="eyebrow" data-reveal="up">{{ $app['label'] }}@if ($app['version']) · version {{ $app['version'] }}@endif</div>
            <h2 class="h2 split">{{ $app['key'] === 'guest' ? 'Everything for your stay' : 'For our team' }}</h2>
            <p class="lede" data-reveal="up" style="margin:20px 0 30px">{{ $app['text'] }}</p>
            @if ($app['key'] === 'guest')
                <ul class="app-feats" data-stagger>
                    <li><i class="ri-calendar-check-line"></i><span><b>Book and manage</b>See your stays and change your plans.</span></li>
                    <li><i class="ri-qr-code-line"></i><span><b>Check in with your phone</b>Skip the queue at the front desk.</span></li>
                    <li><i class="ri-restaurant-2-line"></i><span><b>Order to your room</b>Food, drinks and services.</span></li>
                    <li><i class="ri-chat-smile-3-line"></i><span><b>Chat with us</b>The front desk, any time.</span></li>
                </ul>
            @endif
            <div data-reveal="up">@include('site.partials.app-badges', ['app' => $app])</div>
        </div>
        <div class="app-side" data-reveal="up">
            @include('site.partials.app-phone')
            <div class="app-qr"><div data-qr="{{ route('site.app') }}#{{ $app['key'] }}"></div><span>Scan with your phone's camera to open this page there.</span></div>
        </div>
    </div>
</section>
@endforeach

<section class="section tight white">
    <div class="wrap">
        <div class="head"><div><div class="eyebrow" data-reveal="up">Good to know</div><h2 class="h2 split">Installing the app</h2></div></div>
        <div class="faq" data-stagger>
            <details open><summary>iPhone and iPad</summary><div class="a">Open the App Store button above, or search for {{ config('hotel.name') }} in the App Store. Apple only lets iPhones install apps from the App Store.</div></details>
            <details><summary>Android, from Google Play</summary><div class="a">Open the Google Play button above and tap Install. The app updates by itself.</div></details>
            @if (collect($apps)->contains(fn ($a) => $a['apk']))
            <details><summary>Android, from the file on this page</summary><div class="a">Open this page on the phone and tap "Download file". When it has downloaded, open it from your notifications or the Downloads folder. If the phone asks, allow your browser to install unknown apps, then tap Install. Download the file only from this website, and download it again here when there is a new version.</div></details>
            @endif
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script src="{{ asset('vendor/qrcodejs/qrcode.min.js') }}"></script>
<script>
document.querySelectorAll('[data-qr]').forEach(function (el) {
    if (window.QRCode) new QRCode(el, { text: el.dataset.qr, width: 132, height: 132, colorDark: '#0e1726', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
    else el.textContent = el.dataset.qr;
});
</script>
@endpush
