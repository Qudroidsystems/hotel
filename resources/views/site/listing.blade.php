{{-- Experiences, offers and the journal. $kind, $items, $key (settings prefix) --}}
@php use App\Support\Website; $title = Website::get($key . '.title'); $text = Website::get($key . '.text'); @endphp
@extends('layouts.site', ['hero' => true, 'title' => $title, 'description' => $text])

@section('content')
@include('site.partials.page-hero', ['eyebrow' => ['experience' => 'Moments beyond your stay', 'offer' => 'Book direct', 'post' => 'News & stories'][$kind],
    'title' => $title, 'text' => $text, 'img' => Website::image($key . '.image') ?? $items->first()?->image_url, 'crumbs' => [[$title, null]]])

<section class="section">
    <div class="wrap">
        @if ($items->isEmpty())
            <div class="empty"><i class="{{ \App\Models\Website\WebsiteItem::KINDS[$kind][2] }}"></i>Nothing here yet. Please check back soon.</div>
        @elseif ($kind === 'experience')
            <div class="mosaic" data-stagger="zoom">
                @foreach ($items as $e)
                    <a class="tile" href="{{ $e->url }}">@include('site.partials.pic', ['src' => $e->image_url, 'alt' => $e->title])
                        <div class="t"><small>{{ $e->category ?: 'Experience' }}</small><h3>{{ $e->title }}</h3>@if ($e->summary)<p>{{ \Illuminate\Support\Str::limit($e->summary, 130) }}</p>@endif</div></a>
                @endforeach
            </div>
        @else
            <div class="cards" data-stagger>@foreach ($items as $i)@include('site.partials.item-card', ['item' => $i])@endforeach</div>
            @if ($items instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $items->lastPage() > 1)
                <nav class="pager" aria-label="Pages">
                    @for ($p = 1; $p <= $items->lastPage(); $p++)
                        @if ($p === $items->currentPage())<span class="on">{{ $p }}</span>@else<a href="{{ $items->url($p) }}">{{ $p }}</a>@endif
                    @endfor
                </nav>
            @endif
        @endif
    </div>
</section>
@endsection
