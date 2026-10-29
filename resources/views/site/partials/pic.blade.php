{{-- A picture, or the accent "art" when there is none yet. $src, $alt, optional $initial, $parallax (speed), $eager --}}
@if (! empty($src))
    <img src="{{ $src }}" alt="{{ $alt ?? '' }}" @if (empty($eager)) loading="lazy" @endif decoding="async" @isset($parallax) data-parallax="{{ $parallax }}" @endisset>
@else
    <div class="art" data-initial="{{ mb_strtoupper(mb_substr($initial ?? ($alt ?? config('hotel.name')), 0, 1)) }}" @isset($parallax) data-parallax="{{ $parallax }}" @endisset></div>
@endif
