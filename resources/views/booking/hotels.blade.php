@extends('layouts.guest')

@section('content')
@php $nightsLabel = fn ($n) => $n . ' ' . ($n == 1 ? 'night' : 'nights'); @endphp
<section class="gh-hero">
    <div class="wrap">
        <h1 class="serif">Where are you staying?</h1>
        <p class="lede">{{ $rows->count() }} {{ $rows->count() === 1 ? 'hotel' : 'hotels' }}{{ $cities->count() ? ' in ' . $cities->take(4)->implode(', ') . ($cities->count() > 4 ? ' and more' : '') : '' }}. Book directly, at each hotel's best rate.</p>
        <form method="GET" action="{{ route('hotels.public') }}" class="panel gh-search" aria-label="Find a hotel">
            <div class="field gh-where"><label for="g_where">City or hotel</label>
                <input type="text" id="g_where" name="where" value="{{ $where }}" list="g_cities" placeholder="Anywhere" maxlength="60">
                <datalist id="g_cities">@foreach ($cities as $c)<option value="{{ $c }}">@endforeach</datalist></div>
            <div class="field"><label for="g_in">Check-in</label><input type="date" id="g_in" name="check_in" value="{{ $q['check_in'] ?? '' }}" min="{{ today()->toDateString() }}"></div>
            <div class="field"><label for="g_out">Check-out</label><input type="date" id="g_out" name="check_out" value="{{ $q['check_out'] ?? '' }}" min="{{ today()->addDay()->toDateString() }}"></div>
            <div class="field"><label for="g_ad">Guests</label><select id="g_ad" name="adults">@for ($i = 1; $i <= 12; $i++)<option value="{{ $i }}" @selected(($q['adults'] ?? 2) == $i)>{{ $i }} {{ $i == 1 ? 'adult' : 'adults' }}</option>@endfor</select></div>
            <div class="field"><label for="g_rm">Rooms</label><select id="g_rm" name="rooms">@for ($i = 1; $i <= 6; $i++)<option value="{{ $i }}" @selected(($q['rooms'] ?? 1) == $i)>{{ $i }}</option>@endfor</select></div>
            <button class="btn" type="submit"><i class="ri-search-line"></i> Search</button>
        </form>
    </div>
</section>

<section class="wrap" aria-label="Hotels">
    @forelse ($rows as $r)
        @php
            $h = $r['hotel'];
            $link = route('hotels.public.show', ['hotelSlug' => $h->slug] + ($dated ? $q : []));
            $none = $dated && $r['bookable'] === 0;
        @endphp
        <article class="gh-card {{ $none ? 'full' : '' }}">
            <a href="{{ $link }}" class="gh-photo" tabindex="-1" aria-hidden="true">
                @if ($h->cover_url)<img src="{{ $h->cover_url }}" alt="" loading="lazy">@else<span class="gh-mark serif">{{ mb_substr($h->name, 0, 1) }}</span>@endif
            </a>
            <div class="gh-body">
                <div class="gh-top">
                    <div>
                        <h2 class="serif"><a href="{{ $link }}">{{ $h->name }}</a></h2>
                        <div class="muted small">@if ($h->stars){{ str_repeat('★', $h->stars) }} · @endif{{ $h->location ?: $h->address }}</div>
                    </div>
                    <div class="gh-price">
                        @if ($dated && $r['from'] !== null)
                            <span class="small muted">{{ $nightsLabel($r['nights']) }} from</span>
                            <strong>{{ $r['currency'] }}{{ number_format($r['from'], 0) }}</strong>
                        @elseif (! $dated && $r['nightly_from'])
                            <span class="small muted">From</span>
                            <strong>{{ $r['currency'] }}{{ number_format($r['nightly_from'], 0) }}</strong><span class="small muted">a night</span>
                        @endif
                    </div>
                </div>
                @if ($h->description)<p class="gh-desc">{{ \Illuminate\Support\Str::limit($h->description, 220) }}</p>@endif
                <div class="gh-foot">
                    @if (! $r['open'])
                        <span class="pill grey">Not taking online bookings</span>
                    @elseif ($r['problem'])
                        <span class="pill warn">{{ $r['problem'] }}</span>
                    @elseif ($none)
                        <span class="pill bad">Full for these dates</span>
                    @elseif ($dated)
                        <span class="pill">{{ $r['bookable'] }} {{ $r['bookable'] == 1 ? 'room type' : 'room types' }} free</span>
                    @else
                        <span class="pill grey">{{ $r['room_types'] }} {{ $r['room_types'] == 1 ? 'room type' : 'room types' }}</span>
                    @endif
                    <a href="{{ $link }}" class="btn {{ $none ? 'quiet' : '' }}">{{ $dated && ! $none ? 'Choose a room' : 'See the hotel' }}</a>
                </div>
            </div>
        </article>
    @empty
        <div class="panel" style="margin-top:24px"><strong>No hotels match “{{ $where }}”.</strong> <a href="{{ route('hotels.public') }}">See every hotel</a></div>
    @endforelse
</section>
@endsection

@push('head')
<style>
    .gh-hero { background: linear-gradient(180deg, var(--ink) 0, var(--ink) 66%, var(--paper) 66%); color: #fff; padding: 44px 0 6px; }
    .gh-hero h1 { font-size: clamp(34px, 6vw, 58px); font-weight: 700; max-width: 18ch; }
    .gh-hero .lede { color: #c9d4e6; font-size: 18px; margin: 10px 0 24px; max-width: 60ch; }
    .gh-search { display: grid; grid-template-columns: 1.6fr 1.2fr 1.2fr .9fr .6fr auto; gap: 12px; align-items: end; color: var(--ink); }
    .gh-search > * { min-width: 0; }
    .gh-search input[type=date] { width: 100%; -webkit-appearance: none; appearance: none; }
    .gh-card { display: grid; grid-template-columns: 280px 1fr; background: var(--card); border: 1px solid var(--line); border-radius: var(--r); overflow: hidden; margin-top: 18px; }
    .gh-card.full { opacity: .7; }
    .gh-photo { display: block; background: var(--lagoon-l); min-height: 190px; position: relative; }
    .gh-photo img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .gh-mark { position: absolute; inset: 0; display: grid; place-items: center; font-size: 72px; color: var(--lagoon); }
    .gh-body { padding: 20px 22px; display: flex; flex-direction: column; gap: 10px; }
    .gh-top { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
    .gh-top h2 { font-size: 26px; } .gh-top h2 a { color: var(--ink); text-decoration: none; }
    .gh-price { text-align: right; display: flex; flex-direction: column; flex-shrink: 0; }
    .gh-price strong { font-size: 24px; font-variant-numeric: tabular-nums; }
    .gh-desc { margin: 0; color: var(--ink-2); max-width: 70ch; }
    .gh-foot { margin-top: auto; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
    @media (max-width: 860px) {
        .gh-search { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .gh-search .gh-where, .gh-search .btn { grid-column: 1 / -1; }
        .gh-card { grid-template-columns: 1fr; }
        .gh-photo { min-height: 170px; }
        .gh-top { flex-direction: column; } .gh-price { text-align: left; }
    }
</style>
@endpush
