{{-- The hotel's public website. Pages set: $title (page name), optional $description, $image (sharing picture), $hero (true when the page opens on a dark picture). --}}
@php
    use App\Support\Website;
    $t = Website::theme();
    $contact = Website::contact();
    $socials = Website::socials();
    $hotelName = config('hotel.name');
    $metaTitle = isset($title) ? $title . ' · ' . $hotelName : (Website::get('seo.title') ?: $hotelName . ' · ' . Website::get('tagline'));
    $metaDesc = $description ?? (Website::get('seo.description') ?: Website::get('home.intro_text'));
    $metaImg = $image ?? Website::image('seo.image');
    $hero = $hero ?? false;
    $nav = [
        ['site.rooms', 'Rooms & Suites'], ['site.dining', 'Dining'], ['site.experiences', 'Experiences'],
        ['site.events', 'Meetings & Events'], ['site.offers', 'Offers'], ['site.gallery', 'Gallery'],
    ];
    $multi = \App\Support\Tenancy\CurrentHotel::multi();
    $hotelSlug = $multi ? \App\Support\Tenancy\CurrentHotel::get()->hotel()?->slug : null;
    $footerPages = \App\Models\Website\WebsiteItem::ofKind('page')->live()->ordered()->limit(8)->get(['id', 'title', 'slug', 'kind']);
    $logo = config('hotel.logo') ? asset(config('hotel.logo')) : null;
    $logoLight = config('hotel.logo_light') ? asset(config('hotel.logo_light')) : $logo;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $metaTitle }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) $metaDesc), 160) }}">
    <meta name="theme-color" content="{{ $t['ink'] }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $hotelName }}">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) $metaDesc), 200) }}">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($metaImg)<meta property="og:image" content="{{ $metaImg }}"><meta name="twitter:card" content="summary_large_image">@endif
    <link rel="icon" href="{{ asset(config('hotel.favicon')) }}">
    <link rel="preload" href="{{ asset('site/fonts/cormorant-garamond-latin-300-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('site/fonts/jost-latin-300-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('site/site.css') }}?v={{ @filemtime(public_path('site/site.css')) }}" rel="stylesheet">
    <style>:root { --accent: {{ $t['accent'] }}; --accent-d: {{ $t['accent_dark'] }}; --ink: {{ $t['ink'] }}; --paper: {{ $t['paper'] }}; }</style>
    <script type="application/ld+json">{!! json_encode(array_filter([
        '@context' => 'https://schema.org', '@type' => 'Hotel', 'name' => $hotelName, 'url' => route('home'),
        'telephone' => $contact['phone'], 'email' => $contact['email'], 'image' => $metaImg,
        'address' => $contact['address'] ? ['@type' => 'PostalAddress', 'streetAddress' => $contact['address']] : null,
        'geo' => $contact['lat'] ? ['@type' => 'GeoCoordinates', 'latitude' => $contact['lat'], 'longitude' => $contact['lng']] : null,
        'checkinTime' => $contact['check_in'], 'checkoutTime' => $contact['check_out'],
        'sameAs' => array_values(array_map(fn ($s) => $s[1], $socials)) ?: null,
    ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @stack('head')
</head>
<body class="{{ $hero ? 'has-hero' : '' }} {{ Website::get('announcement') ? 'with-announce' : '' }} {{ $contact['whatsapp'] ? '' : 'no-wa' }} has-mobile-book">
    <div class="curtain" aria-hidden="true"><span>{{ \Illuminate\Support\Str::limit($hotelName, 24, '') }}</span></div>
    <a class="skip" href="#main">Skip to content</a>

    @if (Website::get('announcement'))
        <div class="announce">@if (Website::get('announcement_url'))<a href="{{ Website::get('announcement_url') }}">{{ Website::get('announcement') }}</a>@else{{ Website::get('announcement') }}@endif</div>
    @endif

    <header class="site-header">
        <div class="wrap bar">
            <a href="{{ route('home') }}" class="logo" aria-label="{{ $hotelName }}, home">
                @if ($logo)
                    <img class="on-light" src="{{ $logo }}" alt="{{ $hotelName }}">
                    <img class="on-dark" src="{{ $logoLight }}" alt="">
                @else
                    <span class="word">{{ $hotelName }}<small>Hotel</small></span>
                @endif
            </a>
            <nav class="nav" aria-label="Main">
                @foreach ($nav as [$r, $label])
                    <a href="{{ route($r) }}" class="{{ request()->routeIs($r, $r . '.*') ? 'on' : '' }}">{{ $label }}</a>
                @endforeach
            </nav>
            <div class="head-tools">
                @if ($contact['phone'])<a class="tool hide-sm" href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}"><i class="ri-phone-line"></i><span>{{ $contact['phone'] }}</span></a>@endif
                <a class="tool hide-sm" href="{{ route('stay.find') }}" title="My booking"><i class="ri-key-2-line"></i><span>My booking</span></a>
                @if (config('hotel.booking.enabled', true))<a href="{{ route('book.index') }}" class="btn sm" data-book>Book now</a>@endif
                <button class="burger" type="button" aria-label="Menu" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span></button>
            </div>
        </div>
    </header>

    <div class="menu" id="menu">
        <nav aria-label="Menu">
            <a href="{{ route('home') }}">Home</a>
            @foreach ($nav as [$r, $label])<a href="{{ route($r) }}">{{ $label }}</a>@endforeach
            <a href="{{ route('site.about') }}">Our Story</a>
            <a href="{{ route('site.contact') }}">Contact</a>
        </nav>
        <div class="foot">
            @if (config('hotel.booking.enabled', true))<a href="{{ route('book.index') }}" data-book><i class="ri-calendar-check-line"></i> Book a stay</a>@endif
            <a href="{{ route('stay.find') }}"><i class="ri-key-2-line"></i> My booking</a>
            @if ($contact['phone'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}"><i class="ri-phone-line"></i> {{ $contact['phone'] }}</a>@endif
            @if ($multi)<a href="{{ route('hotels.public') }}"><i class="ri-hotel-line"></i> All our hotels</a>@endif
        </div>
    </div>

    <main id="main">
        @yield('content')
    </main>

    @if (Website::get('home.show_newsletter'))
    <section class="section tight dark" id="newsletter" style="background: color-mix(in srgb, var(--ink) 96%, var(--accent));">
        <div class="wrap newsletter">
            <div data-reveal="up">
                <div class="eyebrow">Join our community</div>
                <h2 class="h2">Stories, offers and <em>first looks</em></h2>
            </div>
            <div data-reveal="up" style="--d:.15s">
                @if (session('subscribed'))
                    <p class="ok"><i class="ri-checkbox-circle-line"></i> Thank you, you are on the list.</p>
                @else
                    <form method="POST" action="{{ route('site.subscribe') }}">
                        @csrf
                        <label class="sr" for="nl_email">E-mail address</label>
                        <input id="nl_email" type="email" name="email" placeholder="Your e-mail address" required maxlength="150" autocomplete="email">
                        <span class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></span>
                        <button type="submit">Subscribe <i class="ri-arrow-right-line"></i></button>
                    </form>
                    @error('email')<p class="err" style="color:#ffb4a8;margin-top:10px">{{ $message }}</p>@enderror
                    <p class="muted" style="font-size:14px;margin-top:14px">A few e-mails a year. Leave the list any time.</p>
                @endif
            </div>
        </div>
    </section>
    @endif

    <footer class="site-footer">
        <div class="wrap">
            <div class="cols">
                <div class="brand">
                    <a href="{{ route('home') }}" class="logo">@if ($logoLight)<img src="{{ $logoLight }}" alt="{{ $hotelName }}">@else<span class="word" style="color:#fff">{{ $hotelName }}<small>Hotel</small></span>@endif</a>
                    <p>{{ Website::get('footer.text') ?: Website::get('tagline') }}</p>
                    @if ($socials)
                        <div class="socials">@foreach ($socials as [$label, $url, $icon])<a href="{{ $url }}" target="_blank" rel="noopener" aria-label="{{ $label }}"><i class="{{ $icon }}"></i></a>@endforeach</div>
                    @endif
                    @if (Website::hasApps())
                        <div class="footer-app"><span>Get our app</span>@include('site.partials.app-badges', ['app' => Website::apps(false)['guest'], 'small' => true])</div>
                    @endif
                </div>
                <div>
                    <h4>Stay</h4>
                    <ul>
                        <li><a href="{{ route('site.rooms') }}">Rooms & Suites</a></li>
                        <li><a href="{{ route('site.offers') }}">Special Offers</a></li>
                        @if (config('hotel.booking.enabled', true))<li><a href="{{ route('book.index') }}">Book a stay</a></li>@endif
                        <li><a href="{{ route('stay.find') }}">Manage my booking</a></li>
                        @if (Website::hasApps())<li><a href="{{ route('site.app') }}">Our app</a></li>@endif
                        @if ($multi)<li><a href="{{ route('hotels.public') }}">All our hotels</a></li>@endif
                    </ul>
                </div>
                <div>
                    <h4>Discover</h4>
                    <ul>
                        <li><a href="{{ route('site.dining') }}">Dining</a></li>
                        <li><a href="{{ route('site.experiences') }}">Experiences</a></li>
                        <li><a href="{{ route('site.events') }}">Meetings & Events</a></li>
                        <li><a href="{{ route('site.gallery') }}">Gallery</a></li>
                        <li><a href="{{ route('site.journal') }}">Journal</a></li>
                        <li><a href="{{ route('site.about') }}">Our Story</a></li>
                        @foreach ($footerPages as $p)<li><a href="{{ $p->url }}">{{ $p->title }}</a></li>@endforeach
                    </ul>
                </div>
                <div>
                    <h4>Contact</h4>
                    <ul class="contact">
                        @if ($contact['address'])<li><i class="ri-map-pin-line"></i><span>{{ $contact['address'] }}</span></li>@endif
                        @if ($contact['phone'])<li><i class="ri-phone-line"></i><a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></li>@endif
                        @if ($contact['email'])<li><i class="ri-mail-line"></i><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></li>@endif
                        @if ($contact['whatsapp'])<li><i class="ri-whatsapp-line"></i><a href="{{ $contact['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp us</a></li>@endif
                        <li><i class="ri-time-line"></i><span>Check-in from {{ \Illuminate\Support\Carbon::createFromFormat('H:i', $contact['check_in'])->format('g:i A') }}, check-out by {{ \Illuminate\Support\Carbon::createFromFormat('H:i', $contact['check_out'])->format('g:i A') }}</span></li>
                    </ul>
                </div>
            </div>
            <div class="legal">
                <span>&copy; {{ date('Y') }} {{ $hotelName }}. All rights reserved.</span>
                <nav><a href="{{ route('site.contact') }}">Contact</a><a href="{{ route('legal.terms') }}">Booking terms</a><a href="{{ route('legal.privacy') }}">Privacy</a>@auth<a href="{{ route('dashboard') }}">Staff dashboard</a>@else<a href="{{ route('login') }}">Staff sign-in</a>@endauth</nav>
            </div>
            <div class="credit">@include('partials.qlouds-credit')</div>
        </div>
    </footer>

    {{-- Booking drawer: the same search as /book --}}
    @if (config('hotel.booking.enabled', true))
    <div class="drawer-back" data-close-drawer></div>
    <aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-h" aria-hidden="true">
        <div class="top"><h2 id="drawer-h">Book your stay</h2><button class="x" type="button" data-close-drawer aria-label="Close"><i class="ri-close-line"></i></button></div>
        <form method="GET" action="{{ route('book.search') }}" data-booking>
            @if ($hotelSlug)<input type="hidden" name="hotel" value="{{ $hotelSlug }}">@endif
            <div class="two">
                <div class="f"><label for="d_in">Check-in</label><input id="d_in" type="date" name="check_in" value="{{ today()->toDateString() }}" min="{{ today()->toDateString() }}" max="{{ today()->addDays((int) config('hotel.booking.advance_days', 365))->toDateString() }}" required></div>
                <div class="f"><label for="d_out">Check-out</label><input id="d_out" type="date" name="check_out" value="{{ today()->addDay()->toDateString() }}" min="{{ today()->addDay()->toDateString() }}" required></div>
            </div>
            <div class="two">
                <div class="f"><label for="d_ad">Adults</label><select id="d_ad" name="adults">@for ($i = 1; $i <= 12; $i++)<option value="{{ $i }}" @selected($i === 2)>{{ $i }}</option>@endfor</select></div>
                <div class="f"><label for="d_ch">Children</label><select id="d_ch" name="children">@for ($i = 0; $i <= 8; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor</select></div>
            </div>
            <div class="two">
                <div class="f"><label for="d_rm">Rooms</label><select id="d_rm" name="rooms">@for ($i = 1; $i <= (int) config('hotel.booking.max_rooms', 4); $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor</select></div>
                <div class="f"><label for="d_pr">Promo code</label><input id="d_pr" type="text" name="promo" maxlength="30" autocomplete="off" placeholder="Optional" style="text-transform:uppercase"></div>
            </div>
            <button class="btn block" type="submit">Check availability <i class="ri-arrow-right-line"></i></button>
        </form>
        <ul class="perks">
            <li><i class="ri-medal-line"></i>Best rate when you book with us</li>
            @if ((float) config('hotel.booking.deposit_percent') > 0)<li><i class="ri-secure-payment-line"></i>{{ rtrim(rtrim(number_format(config('hotel.booking.deposit_percent'), 1), '0'), '.') }}% deposit to confirm, the rest when you stay</li>@else<li><i class="ri-secure-payment-line"></i>Nothing to pay now, pay at the hotel</li>@endif
            @if ((int) config('hotel.booking.cancel_hours') > 0)<li><i class="ri-calendar-close-line"></i>Free cancellation until {{ config('hotel.booking.cancel_hours') }} hours before arrival</li>@endif
            <li><i class="ri-qr-code-line"></i>Check in fast with your booking's QR code</li>
        </ul>
        <p class="find">Already booked? <a href="{{ route('stay.find') }}">Open your booking</a></p>
    </aside>
    <div class="mobile-book">
        @if ($contact['phone'])<a class="call" href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}" aria-label="Call us"><i class="ri-phone-line"></i></a>@endif
        <a href="{{ route('book.index') }}" class="btn" data-book>Book your stay</a>
    </div>
    @endif

    <div class="lightbox" role="dialog" aria-modal="true" aria-label="Photo">
        <img src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="">
        <div class="cap"></div>
        <button class="close" type="button" aria-label="Close"><i class="ri-close-line"></i></button>
        <button class="prev" type="button" aria-label="Previous photo"><i class="ri-arrow-left-line"></i></button>
        <button class="next" type="button" aria-label="Next photo"><i class="ri-arrow-right-line"></i></button>
    </div>

    @if ($contact['whatsapp'])<a class="wa" href="{{ $contact['whatsapp'] }}" target="_blank" rel="noopener" aria-label="Chat with us on WhatsApp"><i class="ri-whatsapp-line"></i></a>@endif
    <button class="to-top" type="button" aria-label="Back to top"><svg viewBox="0 0 52 52" width="52" height="52" aria-hidden="true"><circle cx="26" cy="26" r="24"/></svg><i class="ri-arrow-up-line"></i></button>

    <script src="{{ asset('site/site.js') }}?v={{ @filemtime(public_path('site/site.js')) }}" defer></script>
    @stack('scripts')
</body>
</html>
