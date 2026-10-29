@php use App\Support\Website; @endphp
@extends('layouts.site', ['hero' => true, 'title' => Website::get('gallery.title')])

@section('content')
@include('site.partials.page-hero', ['eyebrow' => 'A closer look', 'title' => Website::get('gallery.title'), 'img' => Website::image('gallery.image') ?? $photos->first()?->image_url, 'short' => true, 'crumbs' => [['Gallery', null]]])

<section class="section">
    <div class="wrap">
        @if ($photos->isEmpty())
            <div class="empty"><i class="ri-gallery-line"></i>Photos are on their way.</div>
        @else
            @if ($categories->count() > 1)
                <div class="filters" data-filters="#grid" data-reveal="up">
                    <button type="button" class="on" data-cat="">All</button>
                    @foreach ($categories as $c)<button type="button" data-cat="{{ $c }}">{{ $c }}</button>@endforeach
                </div>
            @endif
            <div class="masonry" id="grid" data-lightbox>
                @foreach ($photos as $p)
                    <figure data-cat="{{ $p->category }}" data-caption="{{ $p->summary ?: $p->title }}" data-reveal="up" style="--d:{{ ($loop->index % 4) * .08 }}s">
                        <img src="{{ $p->image_url }}" alt="{{ $p->summary ?: $p->title }}" loading="lazy">
                        <figcaption>{{ $p->summary ?: $p->title }}</figcaption>
                    </figure>
                @endforeach
            </div>
        @endif
    </div>
</section>
@endsection
