@extends('layouts.site', ['hero' => true, 'title' => \App\Support\Website::get('rooms.title'), 'description' => \App\Support\Website::get('rooms.text')])
@php use App\Support\Website; $sym = config('hotel.currency_symbol'); @endphp

@section('content')
@include('site.partials.page-hero', ['eyebrow' => 'Stay', 'title' => Website::get('rooms.title'), 'text' => Website::get('rooms.text'),
    'img' => Website::image('rooms.image') ?? $rooms->first()?->photos->first()?->url, 'crumbs' => [['Rooms & Suites', null]]])

<section class="section">
    <div class="wrap">
        @forelse ($rooms as $i => $room)
            @php $photo = $room->photos->first(); @endphp
            <article class="split-row {{ $i % 2 ? 'flip' : '' }}" id="room-{{ $room->id }}">
                <a href="{{ route('site.rooms.show', $room->id) }}" class="pic" data-reveal="curtain" aria-label="{{ $room->name }}">
                    @include('site.partials.pic', ['src' => $photo?->url, 'alt' => $room->name, 'parallax' => .08])
                    @if ($room->photos->count() > 1)<span class="badge"><i class="ri-image-line"></i> {{ $room->photos->count() }} photos</span>@endif
                </a>
                <div>
                    <div class="num" data-reveal="up">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</div>
                    <h2 class="h2" data-reveal="up">{{ $room->name }}</h2>
                    <div class="sub" data-reveal="up">From {{ $sym }}{{ number_format((float) $room->base_rate) }} a night</div>
                    @if ($room->description)<p class="lede" data-reveal="up">{{ \Illuminate\Support\Str::limit($room->description, 260) }}</p>@endif
                    <div class="meta" data-reveal="up" style="flex-wrap:wrap;gap:8px 26px">
                        <span><i class="ri-user-line"></i> Sleeps {{ $room->capacity_label }}</span>
                        @if ($room->bed_type)<span><i class="ri-hotel-bed-line"></i> {{ $room->bed_type }}</span>@endif
                        @if ($room->size_sqm)<span><i class="ri-ruler-2-line"></i> {{ (float) $room->size_sqm }} m²</span>@endif
                    </div>
                    @if ($room->amenities)<ul class="chips" data-reveal="up" style="margin-bottom:28px">@foreach (array_slice($room->amenities, 0, 6) as $a)<li>{{ $a }}</li>@endforeach</ul>@endif
                    <div class="btns" data-reveal="up">
                        @if (config('hotel.booking.enabled', true))<a href="{{ route('book.index') }}" class="btn" data-book>Book this room</a>@endif
                        <a href="{{ route('site.rooms.show', $room->id) }}" class="link">Room details <i class="ri-arrow-right-line"></i></a>
                    </div>
                </div>
            </article>
        @empty
            <div class="empty"><i class="ri-hotel-bed-line"></i>Our rooms will be shown here soon.</div>
        @endforelse
    </div>
</section>

@if ($highlights->isNotEmpty())
<section class="section tight dark">
    <div class="wrap">
        <div class="head"><div><div class="eyebrow" data-reveal="up">In every stay</div><h2 class="h2 split">Thoughtful touches</h2></div></div>
        <div class="highlights" data-stagger>@foreach ($highlights as $h)<div><i class="{{ $h->icon ?: 'ri-star-line' }}"></i><h3>{{ $h->title }}</h3>@if ($h->summary)<p class="muted">{{ $h->summary }}</p>@endif</div>@endforeach</div>
    </div>
</section>
@endif
@endsection
