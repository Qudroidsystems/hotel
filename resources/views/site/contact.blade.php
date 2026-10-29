@php use App\Support\Website; @endphp
@extends('layouts.site', ['hero' => true, 'title' => Website::get('contact.title'), 'description' => Website::get('contact.text')])

@section('content')
@include('site.partials.page-hero', ['eyebrow' => 'We are here to help', 'title' => Website::get('contact.title'), 'text' => Website::get('contact.text'),
    'img' => Website::image('contact.image'), 'short' => true, 'crumbs' => [['Contact', null]]])

<section class="section tight tint">
    <div class="wrap info-cards" data-stagger>
        <div><i class="ri-phone-line"></i><h3>Call us</h3><p>@if ($contact['phone'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a>@else—@endif<br><span class="muted">{{ $contact['hours'] }}</span></p></div>
        <div><i class="ri-mail-line"></i><h3>Write to us</h3><p>@if ($contact['email'])<a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>@else Use the form below.@endif @if ($contact['whatsapp'])<br><a href="{{ $contact['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp {{ $contact['whatsapp_number'] }}</a>@endif</p></div>
        <div><i class="ri-map-pin-line"></i><h3>Visit us</h3><p>{{ $contact['address'] ?: config('hotel.name') }}@if ($contact['lat'])<br><a href="https://www.google.com/maps/dir/?api=1&destination={{ $contact['lat'] }},{{ $contact['lng'] }}" target="_blank" rel="noopener" style="border-bottom:1px solid var(--accent)">Get directions</a>@endif</p></div>
    </div>
</section>

<section class="section" id="form">
    <div class="wrap with-aside" style="grid-template-columns:minmax(0,1.3fr) minmax(0,1fr)">
        <div>
            <div class="eyebrow" data-reveal="up">Send a message</div>
            <h2 class="h2 split" style="margin-bottom:34px">How can we help?</h2>
            <div data-reveal="up">@include('site.partials.enquiry-form', ['kind' => 'contact'])</div>
        </div>
        <div>
            @if (Website::get('contact.directions'))
                <div class="eyebrow" data-reveal="up">Getting here</div>
                <div class="prose" data-reveal="up" style="font-size:16.5px">@foreach (preg_split('/\R{2,}/', Website::get('contact.directions')) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach</div>
            @endif
            @if ($faqs->isNotEmpty())
                <div class="eyebrow" data-reveal="up" style="margin-top:20px">Good to know</div>
                <div class="faq" data-stagger>@foreach ($faqs as $f)<details><summary>{{ $f->title }}</summary><div class="a">{!! nl2br(e($f->body)) !!}</div></details>@endforeach</div>
            @endif
        </div>
    </div>
</section>

@if (Website::get('contact.map') && $contact['lat'])
    @php $d = 0.012; @endphp
    <iframe class="map" title="Map" loading="lazy" referrerpolicy="no-referrer"
        src="https://www.openstreetmap.org/export/embed.html?bbox={{ $contact['lng'] - $d }}%2C{{ $contact['lat'] - $d * .6 }}%2C{{ $contact['lng'] + $d }}%2C{{ $contact['lat'] + $d * .6 }}&amp;layer=mapnik&amp;marker={{ $contact['lat'] }}%2C{{ $contact['lng'] }}"></iframe>
@endif
@endsection
