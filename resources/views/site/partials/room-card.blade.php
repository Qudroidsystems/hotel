{{-- $room (RoomType with photos) --}}
@php $photo = $room->photos->first(); @endphp
<article class="room-card">
    <a href="{{ route('site.rooms.show', $room->id) }}" class="ph" aria-label="{{ $room->name }}">
        @include('site.partials.pic', ['src' => $photo?->url, 'alt' => $room->name])
        @if ($room->size_sqm)<span class="tag">{{ (float) $room->size_sqm }} m²</span>@endif
        <span class="price">From<b>{{ config('hotel.currency_symbol') }}{{ number_format((float) $room->base_rate) }}</b>a night</span>
    </a>
    <h3><a href="{{ route('site.rooms.show', $room->id) }}">{{ $room->name }}</a></h3>
    <div class="facts">
        <span><i class="ri-user-line"></i>Sleeps {{ $room->capacity_label }}</span>
        @if ($room->bed_type)<span><i class="ri-hotel-bed-line"></i>{{ $room->bed_type }}</span>@endif
    </div>
    <div class="more">
        <a href="{{ route('site.rooms.show', $room->id) }}" class="link">Discover <i class="ri-arrow-right-line"></i></a>
        @if (config('hotel.booking.enabled', true))<a href="{{ route('book.index') }}#t{{ $room->id }}" class="link accent" data-book>Book</a>@endif
    </div>
</article>
