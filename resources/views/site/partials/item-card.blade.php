{{-- An offer, experience, journal post or restaurant as a card. $item --}}
<article class="card stretch">
    <div class="ph">
        @include('site.partials.pic', ['src' => $item->image_url, 'alt' => $item->title])
        @if ($item->kind === 'offer' && $item->meta)<span class="ribbon">{{ $item->meta }}</span>@endif
    </div>
    <div class="body">
        <div class="kicker">
            @if ($item->kind === 'post'){{ $item->category ?: 'Journal' }} · {{ ($item->published_at ?? $item->created_at)->format('j M Y') }}
            @elseif ($item->kind === 'offer'){{ $item->ends_on ? 'Book by ' . $item->ends_on->format('j M Y') : 'Special offer' }}
            @else{{ $item->category ?: $item->subtitle ?: $item->kind_label }}@endif
        </div>
        <h3>{{ $item->title }}</h3>
        @if ($item->summary)<p>{{ \Illuminate\Support\Str::limit($item->summary, 170) }}</p>@endif
        <div class="foot">
            @if ($item->kind === 'offer' && $item->promo_code)<span class="code"><i class="ri-coupon-3-line"></i>{{ $item->promo_code }}</span>
            @elseif ($item->kind !== 'offer' && $item->meta)<span class="muted" style="font-size:14px">{{ $item->meta }}</span>@else<span></span>@endif
            @if ($item->kind === 'offer' && config('hotel.booking.enabled', true) && ! $item->link_url)
                <a href="{{ $item->url }}" class="link accent" data-book data-promo="{{ $item->promo_code }}">Book offer <i class="ri-arrow-right-line"></i></a>
            @else
                <span class="link">{{ $item->kind === 'post' ? 'Read' : 'Discover' }} <i class="ri-arrow-right-line"></i></span>
            @endif
        </div>
    </div>
    @if ($item->url)<a class="cover" href="{{ $item->url }}" aria-label="{{ $item->title }}"></a>@endif
</article>
