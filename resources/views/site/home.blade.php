@extends('layouts.site', ['hero' => true])
@php
    use App\Support\Website;
    $sym = config('hotel.currency_symbol');
    $stats = Website::stats();
    $hotelSlug = \App\Support\Tenancy\CurrentHotel::multi() ? \App\Support\Tenancy\CurrentHotel::get()->hotel()?->slug : null;
@endphp

@section('content')
{{-- ── Hero: slides from Website → Hero slides ── --}}
<section class="hero {{ $slides->isEmpty() ? 'plain' : '' }}" data-slider aria-roledescription="carousel" aria-label="Welcome">
    @forelse ($slides as $i => $s)
        <div class="slide {{ $i === 0 ? 'on' : '' }}" aria-roledescription="slide" aria-label="{{ $i + 1 }} of {{ $slides->count() }}">
            <div class="bg {{ $s->image_url ? '' : 'art' }}" @if ($s->image_url) style="background-image:url('{{ $s->image_url }}')" @endif></div>
            <div class="wrap copy">
                <div class="eyebrow">{{ $s->subtitle ?: config('hotel.name') }}</div>
                @if ($i === 0)<h1 class="display split">{{ $s->title }}</h1>@else<h2 class="display split">{{ $s->title }}</h2>@endif
                @if ($s->summary)<p>{{ $s->summary }}</p>@endif
                <div style="display:flex;gap:16px;flex-wrap:wrap">
                    @if ($s->link_url)<a href="{{ $s->link_url }}" class="btn light">{{ $s->link_label ?: 'Discover' }}</a>
                    @elseif (config('hotel.booking.enabled', true))<a href="{{ route('book.index') }}" class="btn light" data-book>Book your stay</a>@endif
                </div>
            </div>
        </div>
    @empty
        <div class="slide on">
            <div class="bg"></div>
            <div class="wrap copy">
                <div class="eyebrow">Welcome to</div>
                <h1 class="display split">{{ config('hotel.name') }}</h1>
                <p>{{ Website::get('tagline') }}</p>
                @if (config('hotel.booking.enabled', true))<div><a href="{{ route('book.index') }}" class="btn light" data-book>Book your stay</a></div>@endif
            </div>
        </div>
    @endforelse
    <div class="shade"></div>
    @if ($slides->count() > 1)
        <div class="dots" role="tablist">@foreach ($slides as $i => $s)<button type="button" aria-label="Slide {{ $i + 1 }}"><span></span></button>@endforeach</div>
        <div class="controls">
            <div class="count"><b>01</b> / {{ str_pad($slides->count(), 2, '0', STR_PAD_LEFT) }}</div>
            <div class="arrows"><button type="button" data-dir="-1" aria-label="Previous"><i class="ri-arrow-left-line"></i></button> <button type="button" data-dir="1" aria-label="Next"><i class="ri-arrow-right-line"></i></button></div>
        </div>
    @endif
    <div class="scroll-cue" aria-hidden="true">Scroll<span></span></div>
</section>

{{-- ── Booking bar ── --}}
@if (config('hotel.booking.enabled', true))
<div class="wrap bookbar" id="book-bar">
    <form method="GET" action="{{ route('book.search') }}" data-booking aria-label="Check availability" data-reveal="up">
        @if ($hotelSlug)<input type="hidden" name="hotel" value="{{ $hotelSlug }}">@endif
        <div class="f"><label for="b_in">Arrive</label><input id="b_in" type="date" name="check_in" value="{{ today()->toDateString() }}" min="{{ today()->toDateString() }}" max="{{ today()->addDays((int) config('hotel.booking.advance_days', 365))->toDateString() }}" required></div>
        <div class="f"><label for="b_out">Depart</label><input id="b_out" type="date" name="check_out" value="{{ today()->addDay()->toDateString() }}" min="{{ today()->addDay()->toDateString() }}" required></div>
        <div class="f" data-guests>
            <label id="b_g">Guests</label>
            <button type="button" class="val" aria-labelledby="b_g" aria-expanded="false" style="text-align:left">2 adults</button>
            <div class="guests-pop">
                <div class="stepper"><div class="lbl">Adults<small>13 and over</small></div><div class="ctl"><button type="button" data-step="-1" aria-label="Fewer adults">−</button><output>2</output><button type="button" data-step="1" aria-label="More adults">+</button></div><input type="hidden" name="adults" value="2" min="1" max="12"></div>
                <div class="stepper"><div class="lbl">Children<small>Under 13</small></div><div class="ctl"><button type="button" data-step="-1" aria-label="Fewer children">−</button><output>0</output><button type="button" data-step="1" aria-label="More children">+</button></div><input type="hidden" name="children" value="0" min="0" max="8"></div>
                <div class="stepper"><div class="lbl">Rooms</div><div class="ctl"><button type="button" data-step="-1" aria-label="Fewer rooms">−</button><output>1</output><button type="button" data-step="1" aria-label="More rooms">+</button></div><input type="hidden" name="rooms" value="1" min="1" max="{{ (int) config('hotel.booking.max_rooms', 4) }}"></div>
            </div>
        </div>
        <div class="f"><label for="b_promo">Promo code</label><input id="b_promo" type="text" name="promo" maxlength="30" placeholder="Optional" autocomplete="off" style="text-transform:uppercase"></div>
        <button class="btn" type="submit">Check availability</button>
    </form>
    <div class="note"><span><i class="ri-medal-line"></i> Best rate guaranteed when you book with us</span><a href="{{ route('stay.find') }}" class="link" style="padding:0">Manage my booking</a></div>
</div>
@endif

{{-- ── Welcome ── --}}
<section class="section">
    <div class="wrap">
        <div class="intro">
            <div>
                <div class="eyebrow" data-reveal="up">{{ Website::get('home.intro_eyebrow') }}</div>
                <h2 class="h2 split">{{ Website::get('home.intro_title') }}</h2>
                <p class="lede" data-reveal="up" style="--d:.2s;margin-top:26px">{{ Website::get('home.intro_text') }}</p>
                <div class="sign" data-reveal="up" style="--d:.3s">
                    <a href="{{ route('site.about') }}" class="link">Our story <i class="ri-arrow-right-line"></i></a>
                    <a href="{{ route('site.rooms') }}" class="link accent">Rooms & suites <i class="ri-arrow-right-line"></i></a>
                </div>
            </div>
            @php $introPics = [Website::image('home.intro_image') ?? $slides->get(1)?->image_url ?? $slides->first()?->image_url, $gallery->first()?->image_url ?? $rooms->first()?->photos->first()?->url]; @endphp
            <div class="pics">
                <div class="a" data-reveal="curtain">@include('site.partials.pic', ['src' => $introPics[0], 'alt' => config('hotel.name'), 'parallax' => .08])</div>
                <div class="b" data-reveal="curtain" style="--d:.25s">@include('site.partials.pic', ['src' => $introPics[1], 'alt' => '', 'parallax' => .16])</div>
            </div>
        </div>
        @if ($stats)
            <div class="stats" data-stagger>
                @foreach ($stats as [$n, $label])<div><b data-count>{{ $n }}</b><span>{{ $label }}</span></div>@endforeach
            </div>
        @endif
    </div>
</section>

{{-- ── Rooms ── --}}
@if ($rooms->isNotEmpty())
<section class="section white" data-rail>
    <div class="wrap">
        <div class="head">
            <div>
                <div class="eyebrow" data-reveal="up">Stay</div>
                <h2 class="h2 split">{{ Website::get('rooms.title') }}</h2>
                <p class="lede" data-reveal="up">{{ Website::get('rooms.text') }}</p>
            </div>
            <div class="rail-nav" data-reveal="up">
                <a href="{{ route('site.rooms') }}" class="link" style="margin-right:18px">All rooms <i class="ri-arrow-right-line"></i></a>
                <button type="button" data-rail-prev aria-label="Previous rooms"><i class="ri-arrow-left-line"></i></button>
                <button type="button" data-rail-next aria-label="More rooms"><i class="ri-arrow-right-line"></i></button>
            </div>
        </div>
        <div class="rail" data-stagger>
            @foreach ($rooms as $room)@include('site.partials.room-card', ['room' => $room])@endforeach
        </div>
        <div class="rail-bar"><span></span></div>
    </div>
</section>
@endif

{{-- ── Highlights ── --}}
@if ($highlights->isNotEmpty())
<section class="section tight">
    <div class="wrap">
        <div class="highlights" data-stagger>
            @foreach ($highlights as $h)
                <div><i class="{{ $h->icon ?: 'ri-star-line' }}"></i><h3>{{ $h->title }}</h3>@if ($h->summary)<p>{{ $h->summary }}</p>@endif</div>
            @endforeach
        </div>
    </div>
</section>
@endif

<div class="marquee" aria-hidden="true">
    <div class="track">@for ($i = 0; $i < 2; $i++)<span>Stay <em>·</em> Dine <em>·</em> Celebrate <em>·</em> Unwind <em>·</em> Discover <em>·</em>&nbsp;</span>@endfor</div>
</div>

{{-- ── Dining ── --}}
@if ($dining->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="head center">
            <div class="eyebrow center" data-reveal="up">Taste</div>
            <h2 class="h2 split">{{ Website::get('dining.title') }}</h2>
            <p class="lede" data-reveal="up">{{ Website::get('dining.text') }}</p>
        </div>
        @foreach ($dining->take(2) as $i => $d)
            <div class="split-row {{ $i % 2 ? 'flip' : '' }}">
                <div class="pic" data-reveal="curtain">@include('site.partials.pic', ['src' => $d->image_url, 'alt' => $d->title, 'parallax' => .1])@if ($d->category)<span class="badge">{{ $d->category }}</span>@endif</div>
                <div>
                    <div class="num" data-reveal="up">0{{ $i + 1 }}</div>
                    <h3 class="h2" data-reveal="up">{{ $d->title }}</h3>
                    @if ($d->subtitle)<div class="sub" data-reveal="up">{{ $d->subtitle }}</div>@endif
                    @if ($d->summary)<p class="lede" data-reveal="up">{{ $d->summary }}</p>@endif
                    @if ($d->meta)<div class="meta" data-reveal="up"><i class="ri-time-line"></i><span>{{ $d->meta }}</span></div>@endif
                    <div class="btns" data-reveal="up"><a href="{{ $d->url }}" class="btn ghost">Discover</a><a href="{{ route('site.dining') }}#table" class="link">Book a table <i class="ri-arrow-right-line"></i></a></div>
                </div>
            </div>
        @endforeach
        @if ($dining->count() > 2)<div class="center" style="margin-top:60px" data-reveal="up"><a href="{{ route('site.dining') }}" class="link">All restaurants & bars <i class="ri-arrow-right-line"></i></a></div>@endif
    </div>
</section>
@endif

{{-- ── Offers ── --}}
@if ($offers->isNotEmpty())
<section class="section tint">
    <div class="wrap">
        <div class="head">
            <div><div class="eyebrow" data-reveal="up">Book direct</div><h2 class="h2 split">Exclusive Offers</h2></div>
            <a href="{{ route('site.offers') }}" class="link" data-reveal="up">See all our deals <i class="ri-arrow-right-line"></i></a>
        </div>
        <div class="cards" data-stagger>@foreach ($offers->take(3) as $o)@include('site.partials.item-card', ['item' => $o])@endforeach</div>
    </div>
</section>
@endif

{{-- ── Experiences ── --}}
@if ($experiences->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="head">
            <div><div class="eyebrow" data-reveal="up">Moments beyond your stay</div><h2 class="h2 split">{{ Website::get('experiences.title') }}</h2></div>
            <a href="{{ route('site.experiences') }}" class="link" data-reveal="up">All our experiences <i class="ri-arrow-right-line"></i></a>
        </div>
        <div class="mosaic" data-stagger="zoom">
            @foreach ($experiences as $e)
                <a class="tile" href="{{ $e->url }}">
                    @include('site.partials.pic', ['src' => $e->image_url, 'alt' => $e->title])
                    <div class="t"><small>{{ $e->category ?: 'Experience' }}</small><h3>{{ $e->title }}</h3>@if ($e->summary)<p>{{ \Illuminate\Support\Str::limit($e->summary, 120) }}</p>@endif</div>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ── Meetings & events ── --}}
@if ($halls->isNotEmpty() || $events->isNotEmpty())
<section class="section dark">
    <div class="wrap events-band">
        <div class="pic" data-reveal="curtain">@include('site.partials.pic', ['src' => Website::image('events.image') ?? $gallery->firstWhere('category', 'Events')?->image_url, 'alt' => 'Meetings and events', 'parallax' => .1])</div>
        <div>
            <div class="eyebrow" data-reveal="up">Celebrate & meet</div>
            <h2 class="h2 split">{{ Website::get('events.title') }}</h2>
            <p class="lede" data-reveal="up" style="margin-top:20px">{{ Website::get('events.text') }}</p>
            @if ($halls->isNotEmpty())
                <div class="hall-list" data-stagger>
                    @foreach ($halls->take(4) as $h)
                        @php $cap = max((int) $h->capacity_theatre, (int) $h->capacity_banquet, (int) $h->capacity_classroom, (int) $h->capacity_boardroom); @endphp
                        <div><b>{{ $h->name }}</b>@if ($cap)<span>Up to {{ number_format($cap) }} guests</span>@endif</div>
                    @endforeach
                </div>
            @endif
            @if ($events->isNotEmpty())
                <p class="muted" style="margin:26px 0 10px;font-size:14px;letter-spacing:.2em;text-transform:uppercase">Coming up</p>
                <div class="ev-list" data-stagger>
                    @foreach ($events as $ev)
                        <div class="ev"><div class="d"><b>{{ $ev->starts_at->format('d') }}</b><span>{{ $ev->starts_at->format('M') }}</span></div><div><h3>{{ $ev->title }}</h3><p>{{ $ev->starts_at->format('D, g:i A') }}{{ $ev->hall ? ' · ' . $ev->hall->name : '' }}</p></div></div>
                    @endforeach
                </div>
            @endif
            <div class="btns" style="display:flex;gap:18px;margin-top:34px;flex-wrap:wrap" data-reveal="up"><a href="{{ route('site.events') }}#enquire" class="btn">Plan your event</a><a href="{{ route('site.events') }}" class="link light">Our venues <i class="ri-arrow-right-line"></i></a></div>
        </div>
    </div>
</section>
@endif

{{-- ── Reviews ── --}}
@if ($reviews->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="head center"><div class="eyebrow center" data-reveal="up">Guest stories</div></div>
        <span class="big-quote-mark center" aria-hidden="true">“</span>
        <div class="quotes" data-quotes>
            @foreach ($reviews as $r)
                <figure class="quote">
                    @if ($r->rating)<div class="stars" aria-label="{{ $r->rating }} out of 5">{{ str_repeat('★', $r->rating) }}{{ str_repeat('☆', 5 - $r->rating) }}</div>@endif
                    <blockquote>{{ $r->summary }}</blockquote>
                    <cite>{{ $r->title }}@if ($r->meta)<small>{{ $r->meta }}</small>@endif</cite>
                </figure>
            @endforeach
        </div>
        @if ($reviews->count() > 1)<div class="quote-dots">@foreach ($reviews as $i => $r)<button type="button" aria-label="Review {{ $i + 1 }}"></button>@endforeach</div>@endif
    </div>
</section>
@endif

{{-- ── Gallery ── --}}
@if ($gallery->isNotEmpty())
<section class="section tight white" style="padding-bottom:clamp(100px,12vw,160px)">
    <div class="wrap">
        <div class="head">
            <div><div class="eyebrow" data-reveal="up">A closer look</div><h2 class="h2 split">Gallery</h2></div>
            <a href="{{ route('site.gallery') }}" class="link" data-reveal="up">See every photo <i class="ri-arrow-right-line"></i></a>
        </div>
        <div class="strip" data-lightbox data-stagger="zoom">
            @foreach ($gallery->take(10) as $g)
                <figure data-caption="{{ $g->summary ?: $g->title }}"><img src="{{ $g->image_url }}" alt="{{ $g->summary ?: $g->title }}" loading="lazy"></figure>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ── Journal ── --}}
@if ($posts->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="head">
            <div><div class="eyebrow" data-reveal="up">Journal</div><h2 class="h2 split">Our latest news</h2></div>
            <a href="{{ route('site.journal') }}" class="link" data-reveal="up">Read the journal <i class="ri-arrow-right-line"></i></a>
        </div>
        <div class="cards" data-stagger>@foreach ($posts as $p)@include('site.partials.item-card', ['item' => $p])@endforeach</div>
    </div>
</section>
@endif
@endsection
