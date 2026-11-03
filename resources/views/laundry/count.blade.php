@extends('layouts.master')

@section('content')
@php
    $sym = config('hotel.currency_symbol');
    $services = \App\Models\Laundry\LaundryItem::SERVICES;
    $express = old('express', $order?->express ?? false);
    $pct = $svc->expressPercent();
@endphp
<style>
    .ld-sheet td, .ld-sheet th { vertical-align: middle; }
    .ld-sheet .grp td { background: var(--cb-bg-soft, #f8fafc); font-weight: 700; font-size: 12.5px; color: #475569; }
    .ld-sheet input[type=number] { width: 78px; text-align: center; font-variant-numeric: tabular-nums; }
    .ld-sheet input[type=number].on { border-color: #0e7490; background: #ecfeff; font-weight: 700; }
    .ld-sheet .price { font-size: 11.5px; color: #64748b; display: block; }
    .ld-total { position: sticky; bottom: 0; background: var(--cb-surface, #fff); border-top: 1px solid var(--cb-border); padding: 12px 16px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; z-index: 2; }
    .ld-total .big { font-size: 22px; font-weight: 800; font-variant-numeric: tabular-nums; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-list-check-2" :subtitle="$order ? 'Count what is in the bag. What you count, at these prices, goes on the room bill when you save.' : 'A guest handed in a bag. Choose their room and count it: it goes on their bill when you save.'">
        <x-slot:actions><a class="cb-hero-btn" href="{{ route('laundry.index') }}"><i class="ri-arrow-left-line"></i> Laundry</a></x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div>@endif

    <form method="POST" action="{{ $order ? route('laundry.collect', $order) : route('laundry.store') }}" id="ldSheet">@csrf
        <x-cb.card :title="$order ? 'Room ' . $order->room_number . ' · ' . $order->guest_name : 'Whose laundry'" icon="ri-user-line">
            @if ($order)
                <div class="small">{{ $order->number }} · asked {{ $order->created_at->format('D g:i A') }}@if ($order->express) · <strong>express</strong>@endif</div>
                @if ($order->lines)<div class="small text-muted mt-1">The guest listed: {{ $order->summary }}. Change the numbers if the bag holds something else — the guest is told.</div>
                @else<div class="small text-muted mt-1">The guest didn't list what is in the bag.</div>@endif
                @if ($order->note)<div class="small mt-1" style="color:#b45309;font-weight:600">“{{ $order->note }}”</div>@endif
            @else
                @if ($inHouse->isEmpty())
                    <p class="small text-muted mb-0">No guests are checked in.</p>
                @else
                    <select name="reservation_id" class="form-select" required style="max-width:420px">
                        <option value="">Room and guest…</option>
                        @foreach ($inHouse as $r)<option value="{{ $r['id'] }}" @selected(old('reservation_id', $reservationId) == $r['id'])>{{ $r['label'] }}</option>@endforeach
                    </select>
                @endif
            @endif
        </x-cb.card>

        <x-cb.card title="The count" icon="ri-t-shirt-line" :flush="true" class="mt-3">
            @if ($groups->isEmpty())
                <p class="small text-muted p-3 mb-0">There is no price list yet. <a href="{{ route('laundry.prices') }}">Set it up first.</a></p>
            @else
            <div class="table-responsive">
                <table class="cb-table ld-sheet">
                    <thead><tr><th>Item</th>@foreach ($services as $k => $sv)<th class="text-center">{{ $sv[0] }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($groups as $group => $items)
                            <tr class="grp"><td colspan="{{ count($services) + 1 }}">{{ $group }}</td></tr>
                            @foreach ($items as $item)
                                <tr>
                                    <td>{{ $item->name }}</td>
                                    @foreach ($services as $k => $sv)
                                        @php $p = $item->priceFor($k); $v = old("qty.{$item->id}.{$k}", $counts[$item->id][$k] ?? ''); @endphp
                                        <td class="text-center">
                                            @if ($p !== null)
                                                <input type="number" min="0" max="50" inputmode="numeric" class="form-control form-control-sm d-inline-block {{ (int) $v > 0 ? 'on' : '' }}" name="qty[{{ $item->id }}][{{ $k }}]" value="{{ $v }}" data-price="{{ $p }}" aria-label="{{ $item->name }}, {{ $sv[0] }}">
                                                <span class="price">{{ $sym }}{{ number_format($p, 0) }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ld-total">
                <div><div class="small text-muted"><span id="ldPieces">0</span> pieces</div><div class="big" id="ldTotal">{{ $sym }}0.00</div></div>
                <input type="hidden" name="express" value="0">
                <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="express" value="1" @checked($express)> Express (+{{ rtrim(rtrim(number_format($pct, 1), '0'), '.') }}%, back in {{ $svc->turnaroundHours(true) }} h instead of {{ $svc->turnaroundHours(false) }} h)</label>
                <input type="text" name="note" maxlength="255" class="form-control" style="flex:1;min-width:200px" placeholder="Note to the guest (optional)" value="{{ old('note') }}">
                <button class="btn btn-primary"><i class="ri-check-line"></i> {{ $order ? 'Collected — add to bill' : 'Save — add to bill' }}</button>
            </div>
            @endif
        </x-cb.card>
    </form>
</div>
</div>
</div>
<script>
(function () {
    const f = document.getElementById('ldSheet'); if (!f) return;
    const sym = @json($sym), pct = @json($pct);
    const fmt = v => sym + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const sum = () => {
        let t = 0, n = 0;
        f.querySelectorAll('input[data-price]').forEach(i => { const q = parseInt(i.value) || 0; i.classList.toggle('on', q > 0); n += q; t += q * parseFloat(i.dataset.price); });
        if (f.querySelector('input[name=express][type=checkbox]')?.checked) t += t * pct / 100;
        document.getElementById('ldPieces').textContent = n; document.getElementById('ldTotal').textContent = fmt(t);
    };
    f.addEventListener('input', sum); f.addEventListener('change', sum); sum();
    f.addEventListener('submit', e => { if (e.submitter) e.submitter.disabled = true; });
})();
</script>
@endsection
