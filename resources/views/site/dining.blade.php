@extends('layouts.site', ['hero' => true, 'title' => \App\Support\Website::get('dining.title'), 'description' => \App\Support\Website::get('dining.text')])
@php use App\Support\Website; @endphp

@section('content')
@include('site.partials.page-hero', ['eyebrow' => 'Taste', 'title' => Website::get('dining.title'), 'text' => Website::get('dining.text'),
    'img' => Website::image('dining.image') ?? $venues->first()?->image_url, 'crumbs' => [['Dining', null]]])

<section class="section">
    <div class="wrap">
        @forelse ($venues as $i => $d)
            <article class="split-row {{ $i % 2 ? 'flip' : '' }}">
                <a href="{{ $d->url }}" class="pic" data-reveal="curtain">@include('site.partials.pic', ['src' => $d->image_url, 'alt' => $d->title, 'parallax' => .1])@if ($d->category)<span class="badge">{{ $d->category }}</span>@endif</a>
                <div>
                    <div class="num" data-reveal="up">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</div>
                    <h2 class="h2" data-reveal="up">{{ $d->title }}</h2>
                    @if ($d->subtitle)<div class="sub" data-reveal="up">{{ $d->subtitle }}</div>@endif
                    @if ($d->summary)<p class="lede" data-reveal="up">{{ $d->summary }}</p>@endif
                    @if ($d->meta)<div class="meta" data-reveal="up"><i class="ri-time-line"></i><span>{{ $d->meta }}</span></div>@endif
                    <div class="btns" data-reveal="up">
                        @if (Website::get('dining.table_form'))<a href="#table" class="btn">Book a table</a>@endif
                        <a href="{{ $d->url }}" class="link">More about {{ $d->title }} <i class="ri-arrow-right-line"></i></a>
                        @if ($d->link_url)<a href="{{ $d->link_url }}" class="link accent" target="_blank" rel="noopener">{{ $d->link_label }} <i class="ri-external-link-line"></i></a>@endif
                    </div>
                </div>
            </article>
        @empty
            <div class="empty"><i class="ri-restaurant-2-line"></i>Our restaurants and bars will be shown here soon.</div>
        @endforelse
    </div>
</section>

@if (Website::get('dining.table_form'))
<section class="section tint" id="table">
    <div class="wrap narrow">
        <div class="head center"><div class="eyebrow center" data-reveal="up">Reservations</div><h2 class="h2 split">Book a table</h2><p class="lede" data-reveal="up">Tell us when you would like to join us and we will confirm by e-mail or phone.</p></div>
        <div data-reveal="up">@include('site.partials.enquiry-form', ['kind' => 'dining', 'venues' => $venues->pluck('title'), 'anchor' => 'table'])</div>
    </div>
</section>
@endif
@endsection
