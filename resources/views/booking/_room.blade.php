{{-- One room type as a row. $type; optional $price (per stay), $note, $action (url), $actionLabel, $disabled;
     $rates (each: key, name, inclusions, non_refundable, promo, total) with $rateUrl (fn ($key) => url) to list the rates under it. --}}
@php
    $photo = $type->photos->first();
    $sym = config('hotel.currency_symbol');
@endphp
<article class="room">
    <div class="ph" style="--tone: {{ $type->color ?: '#0f766e' }}">
        @if ($photo)<img src="{{ $photo->url }}" alt="{{ $photo->caption ?: $type->name }}" loading="lazy">@else<span class="serif">{{ mb_substr($type->name, 0, 1) }}</span>@endif
        @if ($type->photos->count() > 1)<span class="count"><i class="ri-image-line"></i> {{ $type->photos->count() }}</span>@endif
    </div>
    <div class="info">
        <h3 class="serif">{{ $type->name }}</h3>
        <p class="facts">Sleeps {{ $type->capacity_label }}{{ $type->bed_type ? ', ' . strtolower($type->bed_type) . ' bed' : '' }}{{ $type->size_sqm ? ', ' . (float) $type->size_sqm . ' m²' : '' }}</p>
        @if ($type->description)<p class="desc">{{ \Illuminate\Support\Str::limit($type->description, 220) }}</p>@endif
        @if ($type->amenities)
            <ul class="amen">@foreach (array_slice($type->amenities, 0, 7) as $a)<li>{{ $a }}</li>@endforeach @if (count($type->amenities) > 7)<li>+{{ count($type->amenities) - 7 }} more</li>@endif</ul>
        @endif
    </div>
    <div class="buy">
        @isset($price)
            <div class="price">{{ $sym }}{{ number_format($price) }}</div>
            <div class="muted small">{{ $priceNote ?? '' }}</div>
        @else
            <div class="muted small">From</div>
            <div class="price">{{ $sym }}{{ number_format((float) $type->base_rate) }}</div>
            <div class="muted small">a night</div>
        @endisset
        @if (! empty($note))<div class="note">{{ $note }}</div>@endif
        @if (! empty($action))<a href="{{ $action }}" class="btn {{ ! empty($disabled) ? 'quiet' : '' }}" @if (! empty($disabled)) aria-disabled="true" tabindex="-1" style="pointer-events:none;opacity:.5" @endif>{{ $actionLabel ?? 'Choose' }}</a>@endif
    </div>
    @if (! empty($rates) && count($rates) > 1)
        <div class="rates">
            @foreach ($rates as $rate)
                <div class="rate">
                    <div><strong>{{ $rate->name }}</strong>@if ($rate->promo) <span class="tag">Promo</span>@endif @if ($rate->non_refundable) <span class="tag warn">Non-refundable</span>@endif
                        @if ($rate->inclusions)<div class="muted small">{{ $rate->inclusions }}</div>@endif</div>
                    <div class="amt">{{ $sym }}{{ number_format($rate->total) }}</div>
                    @if (! empty($rateUrl))<a class="btn quiet small" href="{{ $rateUrl($rate->key) }}">Book</a>@endif
                </div>
            @endforeach
        </div>
    @endif
</article>
@once
@push('head')
<style>
    .room { display: grid; grid-template-columns: 260px 1fr 190px; gap: 22px; padding: 18px; background: var(--card); border: 1px solid var(--line); border-radius: var(--r); margin-bottom: 14px; }
    .room .ph { position: relative; border-radius: 10px; overflow: hidden; background: var(--tone); min-height: 170px; display: grid; place-items: center; }
    .room .ph img { width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0; }
    .room .ph span.serif { color: rgba(255,255,255,.85); font-size: 64px; }
    .room .ph .count { position: absolute; right: 8px; bottom: 8px; background: rgba(20,33,61,.75); color: #fff; font-size: 12px; padding: 2px 8px; border-radius: 99px; }
    .room h3 { font-size: 24px; margin-bottom: 4px; }
    .room .facts { margin: 0 0 8px; color: var(--ink-2); font-size: 15px; }
    .room .desc { margin: 0 0 10px; color: var(--muted); font-size: 15px; max-width: 60ch; }
    .room .amen { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 6px; }
    .room .amen li { font-size: 13px; padding: 3px 10px; border: 1px solid var(--line); border-radius: 99px; color: var(--ink-2); }
    .room .buy { display: flex; flex-direction: column; align-items: flex-end; justify-content: flex-end; text-align: right; gap: 2px; border-left: 1px dashed var(--line); padding-left: 18px; }
    .room .buy .price { font-family: var(--serif); font-size: 28px; font-weight: 700; color: var(--ink); line-height: 1.1; }
    .room .buy .note { font-size: 13.5px; color: var(--warn); margin: 6px 0 2px; }
    .room .buy .btn { margin-top: 12px; width: 100%; }
    .room .rates { grid-column: 1 / -1; border-top: 1px dashed var(--line); padding-top: 12px; display: grid; gap: 8px; }
    .room .rate { display: grid; grid-template-columns: 1fr auto auto; gap: 14px; align-items: center; }
    .room .rate .amt { font-family: var(--serif); font-weight: 700; font-size: 19px; color: var(--ink); }
    .room .rate .btn.small { padding: 8px 16px; min-height: 0; }
    .room .tag { font-size: 11.5px; padding: 1px 8px; border-radius: 99px; background: rgba(15,118,110,.1); color: var(--accent, #0f766e); font-weight: 600; vertical-align: 2px; }
    .room .tag.warn { background: rgba(183,131,47,.14); color: #8a5f1c; }
    @media (max-width: 860px) {
        .room .rate { grid-template-columns: 1fr auto; }
        .room .rate .btn { grid-column: 1 / -1; }
        .room { grid-template-columns: 1fr; gap: 14px; padding: 14px; }
        .room .ph { min-height: 190px; }
        .room .buy { border-left: 0; border-top: 1px dashed var(--line); padding: 14px 0 0; align-items: stretch; text-align: left; }
        .room .buy .btn { margin-top: 10px; }
    }
</style>
@endpush
@endonce
