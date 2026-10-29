@extends('layouts.site', ['hero' => true, 'title' => \App\Support\Website::get('events.title'), 'description' => \App\Support\Website::get('events.text')])
@php use App\Support\Website; $sym = config('hotel.currency_symbol'); @endphp

@section('content')
@include('site.partials.page-hero', ['eyebrow' => 'Celebrate & meet', 'title' => Website::get('events.title'), 'text' => Website::get('events.text'),
    'img' => Website::image('events.image') ?? $gallery->first()?->image_url, 'crumbs' => [['Meetings & Events', null]]])

@if ($halls->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="head"><div><div class="eyebrow" data-reveal="up">Our venues</div><h2 class="h2 split">Spaces for every occasion</h2></div><a href="#enquire" class="btn" data-reveal="up">Plan your event</a></div>
        <div class="cards" data-stagger>
            @foreach ($halls as $h)
                <article class="card">
                    <div class="ph"><div class="art" data-initial="{{ mb_substr($h->name, 0, 1) }}" style="background-color: {{ $h->color }}"></div></div>
                    <div class="body">
                        <div class="kicker">Hall</div>
                        <h3>{{ $h->name }}</h3>
                        @if ($h->description)<p>{{ \Illuminate\Support\Str::limit($h->description, 200) }}</p>@endif
                        <div class="hall-list" style="margin:6px 0 0">
                            @foreach (\App\Models\Events\Hall::SETUPS as $key => [$label, $col])
                                @if ($key !== 'cocktail' && (int) $h->{$col} > 0)<div style="padding:10px 0"><span style="font-size:15px">{{ \Illuminate\Support\Str::before($label, ' (') }}</span><span>{{ number_format($h->{$col}) }} guests</span></div>@endif
                            @endforeach
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>
@endif

@if ($events->isNotEmpty())
<section class="section dark">
    <div class="wrap">
        <div class="head"><div><div class="eyebrow" data-reveal="up">What's on</div><h2 class="h2 split">Upcoming events</h2><p class="lede" data-reveal="up">Tickets are sold at reception, and staying guests can buy them in the guest app and add them to their room bill.</p></div></div>
        <div class="ev-list" data-stagger>
            @foreach ($events as $ev)
                <div class="ev">
                    <div class="d"><b>{{ $ev->starts_at->format('d') }}</b><span>{{ $ev->starts_at->format('M') }}</span></div>
                    <div><h3>{{ $ev->title }}</h3><p>{{ $ev->when }}{{ $ev->hall ? ' · ' . $ev->hall->name : '' }}</p>@if ($ev->ticket_info)<p style="margin-top:6px">{{ \Illuminate\Support\Str::limit($ev->ticket_info, 160) }}</p>@endif</div>
                    @if ($ev->ticketTypes->isNotEmpty())<div class="from"><small>Tickets from</small>{{ (float) $ev->ticketTypes->first()->price > 0 ? $sym . number_format((float) $ev->ticketTypes->first()->price) : 'Free' }}</div>@endif
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

@if ($gallery->count() >= 3)
<section class="section tight white">
    <div class="wrap"><div class="strip" data-lightbox data-stagger="zoom">@foreach ($gallery as $g)<figure data-caption="{{ $g->summary }}"><img src="{{ $g->image_url }}" alt="{{ $g->summary ?: $g->title }}" loading="lazy"></figure>@endforeach</div></div>
</section>
@endif

<section class="section tint" id="enquire">
    <div class="wrap narrow">
        <div class="head center"><div class="eyebrow center" data-reveal="up">Enquire</div><h2 class="h2 split">Plan your event with us</h2><p class="lede" data-reveal="up">Share a few details and our events team will send ideas, menus and a quote within one working day.</p></div>
        <div data-reveal="up">@include('site.partials.enquiry-form', ['kind' => 'event', 'halls' => $halls->pluck('name'), 'anchor' => 'enquire'])</div>
    </div>
</section>
@endsection
