@php use App\Support\Website; $paras = array_filter(array_map('trim', preg_split('/\R{2,}/', (string) Website::get('about.text')))); $stats = Website::stats(); @endphp
@extends('layouts.site', ['hero' => true, 'title' => Website::get('about.title')])

@section('content')
@include('site.partials.page-hero', ['eyebrow' => config('hotel.name'), 'title' => Website::get('about.title'), 'text' => Website::get('tagline'),
    'img' => Website::image('about.image') ?? $gallery->first()?->image_url, 'crumbs' => [['Our Story', null]]])

<section class="section">
    <div class="wrap intro">
        <div>
            <div class="eyebrow" data-reveal="up">Who we are</div>
            <h2 class="h2 split">{{ Website::get('home.intro_title') }}</h2>
            <div class="prose" style="margin-top:30px" data-reveal="up">@foreach ($paras as $p)<p>{{ $p }}</p>@endforeach</div>
        </div>
        <div class="pics">
            <div class="a" data-reveal="curtain">@include('site.partials.pic', ['src' => Website::image('home.intro_image') ?? $gallery->get(1)?->image_url, 'alt' => config('hotel.name'), 'parallax' => .08])</div>
            <div class="b" data-reveal="curtain" style="--d:.2s">@include('site.partials.pic', ['src' => $gallery->get(2)?->image_url, 'alt' => '', 'parallax' => .16])</div>
        </div>
    </div>
    @if ($stats)<div class="wrap"><div class="stats" data-stagger>@foreach ($stats as [$n, $label])<div><b data-count>{{ $n }}</b><span>{{ $label }}</span></div>@endforeach</div></div>@endif
</section>

@if ($highlights->isNotEmpty())
<section class="section tight dark">
    <div class="wrap"><div class="head"><div><div class="eyebrow" data-reveal="up">What to expect</div><h2 class="h2 split">The little things</h2></div></div>
        <div class="highlights" data-stagger>@foreach ($highlights as $h)<div><i class="{{ $h->icon ?: 'ri-star-line' }}"></i><h3>{{ $h->title }}</h3>@if ($h->summary)<p class="muted">{{ $h->summary }}</p>@endif</div>@endforeach</div></div>
</section>
@endif

@if ($reviews->isNotEmpty())
<section class="section">
    <div class="wrap">
        <span class="big-quote-mark center" aria-hidden="true">“</span>
        <div class="quotes" data-quotes>@foreach ($reviews as $r)<figure class="quote">@if ($r->rating)<div class="stars">{{ str_repeat('★', $r->rating) }}</div>@endif<blockquote>{{ $r->summary }}</blockquote><cite>{{ $r->title }}@if ($r->meta)<small>{{ $r->meta }}</small>@endif</cite></figure>@endforeach</div>
        @if ($reviews->count() > 1)<div class="quote-dots">@foreach ($reviews as $i => $r)<button type="button" aria-label="Review {{ $i + 1 }}"></button>@endforeach</div>@endif
    </div>
</section>
@endif
@endsection
