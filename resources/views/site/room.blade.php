@extends('layouts.site', ['hero' => true, 'title' => $room->name, 'description' => $room->description, 'image' => $room->photos->first()?->url])
@php $sym = config('hotel.currency_symbol'); $photos = $room->photos; @endphp

@section('content')
@include('site.partials.page-hero', ['eyebrow' => 'From ' . $sym . number_format((float) $room->base_rate) . ' a night', 'title' => $room->name,
    'img' => $photos->first()?->url, 'crumbs' => [['Rooms & Suites', route('site.rooms')], [$room->name, null]]])

<section class="section" style="padding-top:clamp(60px,7vw,90px)">
    <div class="wrap with-aside">
        <div>
            @if ($photos->count() > 1)
                <div class="room-gallery" data-lightbox style="margin:0 0 50px">
                    @foreach ($photos->take(3) as $p)
                        <figure data-caption="{{ $p->caption }}" data-reveal="zoom" style="--d:{{ $loop->index * .1 }}s"><img src="{{ $p->url }}" alt="{{ $p->caption ?: $room->name }}" loading="lazy">
                            @if ($loop->last && $photos->count() > 3)<span class="all btn sm light"><i class="ri-image-line"></i> {{ $photos->count() }} photos</span>@endif</figure>
                    @endforeach
                    @foreach ($photos->slice(3) as $p)<figure data-caption="{{ $p->caption }}" style="display:none"><img src="{{ $p->url }}" alt="{{ $p->caption ?: $room->name }}" loading="lazy"></figure>@endforeach
                </div>
            @endif
            <div class="eyebrow" data-reveal="up">The room</div>
            <h2 class="h2 split">{{ $room->name }}</h2>
            <div class="facts-grid" data-stagger>
                <div><i class="ri-user-line"></i><b>{{ $room->capacity_label }}</b><span>Sleeps</span></div>
                <div><i class="ri-hotel-bed-line"></i><b>{{ $room->bed_type ?: '—' }}</b><span>Bed</span></div>
                <div><i class="ri-ruler-2-line"></i><b>{{ $room->size_sqm ? (float) $room->size_sqm . ' m²' : '—' }}</b><span>Size</span></div>
                <div><i class="ri-price-tag-3-line"></i><b>{{ $sym }}{{ number_format((float) $room->base_rate) }}</b><span>From, a night</span></div>
            </div>
            @if ($room->description)<div class="prose" data-reveal="up">@foreach (preg_split('/\R{2,}/', $room->description) as $para)<p>{{ $para }}</p>@endforeach</div>@endif
            @if ($room->amenities)
                <h3 class="h3" style="margin:50px 0 20px" data-reveal="up">In the room</h3>
                <ul class="amen-list" data-stagger>@foreach ($room->amenities as $a)<li><i class="ri-check-line"></i>{{ $a }}</li>@endforeach</ul>
            @endif
        </div>
        <aside class="aside-card" data-reveal="up">
            <div class="eyebrow">Reserve</div>
            <h3>{{ $sym }}{{ number_format((float) $room->base_rate) }} <span class="muted" style="font:300 16px var(--sans)">a night, from</span></h3>
            <p class="muted" style="font-size:15px">Choose your dates to see the price for your stay and every rate.</p>
            @include('site.partials.book-form', ['fragment' => 't' . $room->id, 'button' => 'See prices'])
        </aside>
    </div>
</section>

@if ($others->isNotEmpty())
<section class="section white">
    <div class="wrap">
        <div class="head"><div><div class="eyebrow" data-reveal="up">You may also like</div><h2 class="h2 split">Other rooms</h2></div><a href="{{ route('site.rooms') }}" class="link" data-reveal="up">All rooms <i class="ri-arrow-right-line"></i></a></div>
        <div class="cards" data-stagger>@foreach ($others as $o)@include('site.partials.room-card', ['room' => $o])@endforeach</div>
    </div>
</section>
@endif
@endsection
