{{-- Top of an inner page: $title, optional $eyebrow, $text, $img, $short, $crumbs ([[label, url], …]) --}}
<section class="page-hero {{ ! empty($short) ? 'short' : '' }}">
    <div class="bg-wrap" data-parallax=".25"><div class="bg {{ empty($img) ? 'art' : '' }}" @if (! empty($img)) style="background-image:url('{{ $img }}')" @else data-initial="" @endif></div></div>
    <div class="wrap copy">
        @if (! empty($crumbs))
            <nav class="crumbs" aria-label="You are here"><a href="{{ route('home') }}">Home</a>@foreach ($crumbs as [$label, $url])<span>/</span>@if ($url)<a href="{{ $url }}">{{ $label }}</a>@else<span>{{ $label }}</span>@endif @endforeach</nav>
        @endif
        @if (! empty($eyebrow))<div class="eyebrow">{{ $eyebrow }}</div>@endif
        <h1 class="h1">{{ $title }}</h1>
        @if (! empty($text))<p>{{ $text }}</p>@endif
    </div>
</section>
