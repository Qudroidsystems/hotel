@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<style>
    .ld-cols { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; align-items: start; }
    @media (max-width: 1100px) { .ld-cols { grid-template-columns: 1fr; } }
    .ld-col h6 { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-weight: 700; }
    .ld-col h6 .n { margin-left: auto; font-size: 12px; font-weight: 700; background: var(--cb-border); border-radius: 999px; padding: 1px 9px; }
    .ld-bag { background: var(--cb-surface, #fff); border: 1px solid var(--cb-border); border-left: 5px solid var(--ld, #94a3b8); border-radius: 12px; padding: 12px 14px; margin-bottom: 10px; }
    .ld-bag.st-requested { --ld: #d97706; }
    .ld-bag.st-collected { --ld: #0891b2; }
    .ld-bag.st-ready { --ld: #4f46e5; }
    .ld-bag.late { box-shadow: 0 0 0 3px rgba(220,38,38,.3); }
    .ld-bag header { display: flex; gap: 10px; align-items: flex-start; }
    .ld-room { min-width: 52px; padding: 4px 8px; border-radius: 9px; background: #0e7490; color: #fff; font-weight: 800; font-size: 19px; text-align: center; font-variant-numeric: tabular-nums; }
    .ld-who { flex: 1; min-width: 0; }
    .ld-what { font-size: 13.5px; margin: 8px 0 6px; }
    .ld-note { font-size: 12.5px; color: #b45309; font-weight: 600; }
    .ld-issue { font-size: 12.5px; color: #b91c1c; font-weight: 600; }
    .ld-due { font-size: 12.5px; font-weight: 700; }
    .ld-due.late { color: #b91c1c; }
    .ld-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .ld-actions form { display: flex; gap: 6px; flex: 1 1 auto; }
    .ld-actions input { flex: 1; min-width: 0; }
    .ld-empty { border: 1px dashed var(--cb-border); border-radius: 12px; padding: 18px; text-align: center; color: #64748b; font-size: 13px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-t-shirt-line" subtitle="Guests' laundry: bags to collect, bags at the laundry with the time each is due back, and clean laundry to take up. Counting a bag puts it on the room bill.">
        <x-slot:actions>
            @if ($canHandle && $hasPrices)<a class="cb-hero-btn" href="{{ route('laundry.create') }}"><i class="ri-add-line"></i> Bag handed in</a>@endif
            <a class="cb-hero-btn" href="{{ route('laundry.prices') }}"><i class="ri-price-tag-3-line"></i> Price list</a>
            @can('Manage hotel settings')<a class="cb-hero-btn" href="{{ route('settings.hotel.index', ['tab' => 'laundry']) }}"><i class="ri-settings-3-line"></i> Hours &amp; times</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if (! config('hotel.laundry.enabled', true))
        <div class="cb-banner warning"><i class="ri-information-line"></i><div>Guests can't send laundry from the app: it is switched off in Hotel Settings → Laundry.</div></div>
    @elseif (! $hasPrices)
        <div class="cb-banner info"><i class="ri-information-line"></i><div>There is no price list yet, so guests can't send laundry. <a href="{{ route('laundry.prices') }}">Set up the price list</a> — you can start from a typical one.</div></div>
    @endif

    <div id="ld-live">@include('laundry.partials.board')</div>

</div>
</div>
</div>
<script>
(() => {
    const box = document.getElementById('ld-live');
    const url = @json(route('laundry.index', ['partial' => 1]));
    setInterval(async () => {
        if (document.activeElement && box.contains(document.activeElement) && document.activeElement.tagName === 'INPUT') return;
        try {
            const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (res.ok && !res.redirected) box.innerHTML = await res.text();
        } catch (e) {}
    }, 30000);
})();
</script>
@endsection
