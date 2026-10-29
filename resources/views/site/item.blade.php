{{-- One restaurant, experience, offer, journal post or page. $item, $more, $kind --}}
@php
    use App\Support\Website;
    $parent = ['dining' => ['Dining', 'site.dining'], 'experience' => ['Experiences', 'site.experiences'], 'offer' => ['Offers', 'site.offers'], 'post' => ['Journal', 'site.journal']][$kind] ?? null;
    $eyebrow = match ($kind) { 'post' => ($item->category ?: 'Journal') . ' · ' . ($item->published_at ?? $item->created_at)->format('j F Y'), 'offer' => $item->meta ?: 'Special offer', default => $item->category ?: $item->subtitle };
@endphp
@extends('layouts.site', ['hero' => true, 'title' => $item->title, 'description' => $item->summary, 'image' => $item->image_url])

@section('content')
@include('site.partials.page-hero', ['eyebrow' => $eyebrow, 'title' => $item->title, 'text' => $kind === 'post' ? null : ($item->kind === 'page' ? $item->subtitle : $item->summary),
    'img' => $item->image_url, 'short' => $kind === 'page' && ! $item->image_url, 'crumbs' => array_values(array_filter([$parent ? [$parent[0], route($parent[1])] : null, [$item->title, null]]))])

<section class="section">
    <div class="wrap {{ in_array($kind, ['post', 'page'], true) ? '' : 'with-aside' }}">
        <div class="{{ in_array($kind, ['post', 'page'], true) ? 'narrow' : '' }}" style="{{ in_array($kind, ['post', 'page'], true) ? 'margin:0 auto' : '' }}">
            @if ($kind === 'post' && $item->summary)<p class="lede" style="font:italic 400 clamp(22px,2vw,28px)/1.5 var(--serif);color:var(--ink);margin-bottom:34px" data-reveal="up">{{ $item->summary }}</p>@endif
            <div class="prose" data-reveal="up">
                @forelse ($item->paragraphs as $para)
                    @if (str_starts_with($para, '## '))<h2>{{ substr($para, 3) }}</h2>@else<p>{!! nl2br(e($para)) !!}</p>@endif
                @empty
                    @if ($kind !== 'post' && $item->summary)<p>{{ $item->summary }}</p>@endif
                @endforelse
            </div>
            @if ($kind === 'post' && $item->meta)<p class="muted" style="margin-top:40px;font-size:14px;letter-spacing:.2em;text-transform:uppercase">Written by {{ $item->meta }}</p>@endif
        </div>
        @unless (in_array($kind, ['post', 'page'], true))
            <aside class="aside-card" data-reveal="up">
                @if ($kind === 'offer')
                    <div class="eyebrow">Book this offer</div>
                    <h3>{{ $item->meta ?: $item->title }}</h3>
                    <dl>
                        @if ($item->promo_code)<div><dt>Promo code</dt><dd><span class="code">{{ $item->promo_code }}</span></dd></div>@endif
                        @if ($item->ends_on)<div><dt>Book by</dt><dd>{{ $item->ends_on->format('l j F Y') }}</dd></div>@endif
                    </dl>
                    @if ($item->link_url)<a href="{{ $item->link_url }}" class="btn block">{{ $item->link_label }}</a>
                    @else @include('site.partials.book-form', ['promo' => $item->promo_code, 'button' => 'Book this offer'])@endif
                @else
                    <div class="eyebrow">{{ $item->category ?: $item->kind_label }}</div>
                    <h3>{{ $item->title }}</h3>
                    <dl>
                        @if ($item->subtitle)<div><dt>{{ $kind === 'dining' ? 'Cuisine' : 'About' }}</dt><dd>{{ $item->subtitle }}</dd></div>@endif
                        @if ($item->meta)<div><dt>{{ $kind === 'dining' ? 'Opening hours' : 'When & price' }}</dt><dd>{{ $item->meta }}</dd></div>@endif
                    </dl>
                    <div style="display:grid;gap:12px">
                        @if ($kind === 'dining' && Website::get('dining.table_form'))<a href="{{ route('site.dining') }}#table" class="btn block">Book a table</a>@endif
                        @if ($item->link_url)<a href="{{ $item->link_url }}" class="btn ghost block" @if (! str_starts_with($item->link_url, '/')) target="_blank" rel="noopener" @endif>{{ $item->link_label }}</a>@endif
                        @if ($kind === 'experience')<a href="{{ route('site.contact') }}" class="btn ghost block">Ask about it</a>@endif
                    </div>
                @endif
            </aside>
        @endunless
    </div>
</section>

@if ($more->isNotEmpty())
<section class="section white">
    <div class="wrap">
        <div class="head"><div><div class="eyebrow" data-reveal="up">Keep exploring</div><h2 class="h2 split">{{ $kind === 'post' ? 'More stories' : 'You may also like' }}</h2></div>@if ($parent)<a href="{{ route($parent[1]) }}" class="link" data-reveal="up">All {{ strtolower($parent[0]) }} <i class="ri-arrow-right-line"></i></a>@endif</div>
        <div class="cards" data-stagger>@foreach ($more as $m)@include('site.partials.item-card', ['item' => $m])@endforeach</div>
    </div>
</section>
@endif
@endsection
