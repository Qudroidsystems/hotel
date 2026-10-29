{{-- A small booking search (side cards). Optional $fragment (e.g. "t3" to land on one room), $promo, $button --}}
@php $slug = \App\Support\Tenancy\CurrentHotel::multi() ? \App\Support\Tenancy\CurrentHotel::get()->hotel()?->slug : null; $uid = 'bf' . substr(md5(($fragment ?? '') . ($promo ?? '')), 0, 5); @endphp
@if (config('hotel.booking.enabled', true))
<form method="GET" action="{{ route('book.search') }}{{ ! empty($fragment) ? '#' . $fragment : '' }}" class="mini-form" data-booking>
    @if ($slug)<input type="hidden" name="hotel" value="{{ $slug }}">@endif
    <div class="row2">
        <div class="f"><label for="{{ $uid }}i">Check-in</label><input id="{{ $uid }}i" type="date" name="check_in" value="{{ today()->toDateString() }}" min="{{ today()->toDateString() }}" required></div>
        <div class="f"><label for="{{ $uid }}o">Check-out</label><input id="{{ $uid }}o" type="date" name="check_out" value="{{ today()->addDay()->toDateString() }}" min="{{ today()->addDay()->toDateString() }}" required></div>
    </div>
    <div class="row2">
        <div class="f"><label for="{{ $uid }}a">Adults</label><select id="{{ $uid }}a" name="adults">@for ($i = 1; $i <= 12; $i++)<option value="{{ $i }}" @selected($i === 2)>{{ $i }}</option>@endfor</select></div>
        <div class="f"><label for="{{ $uid }}c">Children</label><select id="{{ $uid }}c" name="children">@for ($i = 0; $i <= 8; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor</select></div>
    </div>
    <input type="hidden" name="rooms" value="1">
    @if (! empty($promo))
        <input type="hidden" name="promo" value="{{ $promo }}">
        <p class="muted" style="font-size:14px;margin:0">Promo code <span class="code">{{ $promo }}</span> is applied.</p>
    @endif
    <button class="btn block" type="submit">{{ $button ?? 'Check availability' }} <i class="ri-arrow-right-line"></i></button>
</form>
@else
    <p class="muted">We aren’t taking online bookings right now. Call or e-mail us and we’ll find you a room.</p>
@endif
