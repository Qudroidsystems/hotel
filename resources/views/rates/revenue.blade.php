@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $r = $grid['rules'];
    $types = $grid['types'];
    $totRooms = $pickup->sum('rooms');
    $totSold = $pickup->sum('sold');
    $changes = collect($grid['nights'])->flatten(1)->where('differs', true)->count();
    $maxPick = max(1, $pickup->max('sold'));
@endphp
<style>
    .rv-grid { border-collapse: separate; border-spacing: 0; width: 100%; font-size: 13px; }
    .rv-grid th, .rv-grid td { padding: 6px 8px; border-bottom: 1px solid var(--cb-border); white-space: nowrap; }
    .rv-grid thead th { position: sticky; top: 0; background: var(--cb-surface, #fff); z-index: 2; font-size: 12px; color: var(--cb-muted); font-weight: 600; }
    .rv-grid tbody th { position: sticky; left: 0; background: var(--cb-surface, #fff); z-index: 1; text-align: left; font-weight: 600; }
    .rv-grid tr.weekend th { color: #b45309; }
    .rv-cell { display: flex; align-items: center; gap: 8px; min-width: 150px; }
    .rv-occ { width: 38px; height: 26px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 11.5px; color: #0f172a; flex: 0 0 auto; }
    .rv-price { line-height: 1.15; }
    .rv-price .now { font-weight: 600; }
    .rv-price .sug { font-size: 11.5px; font-weight: 700; }
    .rv-price .sug.up { color: #15803d; } .rv-price .sug.down { color: #b91c1c; }
    .rv-price .man { font-size: 11px; color: var(--cb-muted); }
    .rv-pick { font-size: 11px; color: #4f46e5; font-weight: 700; }
    .rv-bars { display: flex; align-items: flex-end; gap: 3px; height: 90px; padding: 6px 2px 0; }
    .rv-bars div { flex: 1; background: var(--cb-teal, #0d9488); border-radius: 3px 3px 0 0; min-height: 2px; position: relative; opacity: .85; }
    .rv-bars div span { position: absolute; left: 0; right: 0; bottom: 0; background: #4f46e5; border-radius: 3px 3px 0 0; }
    .rv-legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; color: var(--cb-muted); }
    .rv-legend i { display: inline-block; width: 12px; height: 12px; border-radius: 3px; vertical-align: -2px; margin-right: 4px; }
    .rv-bar { position: sticky; bottom: 0; z-index: 3; background: var(--cb-surface, #fff); border-top: 1px solid var(--cb-border); padding: 10px 14px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Revenue & Pricing" icon="ri-line-chart-line" subtitle="What's booked for each night, what came in this week, and a suggested price from your rules. Applying a suggestion sets that night's price in the rate calendar; nights you priced by hand are never touched.">
        <x-slot:actions>
            @foreach ([14, 30, 60, 90] as $n)
                <a class="cb-hero-btn" href="{{ route('rates.revenue', ['days' => $n]) }}" @if ($n === $days) style="background:#fff;color:#0f2342" @endif>{{ $n }} nights</a>
            @endforeach
            <a class="cb-hero-btn" href="{{ route('settings.hotel.index', ['tab' => 'revenue']) }}"><i class="ri-settings-3-line"></i> Rules</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Booked so far" :value="($totRooms ? round($totSold / $totRooms * 100, 1) : 0) . '%'" icon="ri-hotel-bed-line" accent="teal" :hint="$totSold . ' of ' . $totRooms . ' room nights'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Picked up this week" :value="$pickup->sum('pickup')" icon="ri-arrow-up-circle-line" accent="violet" hint="room nights booked in the last 7 days" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Room revenue on the books" :value="$sym . number_format($pickup->sum('revenue'), 0)" icon="ri-money-dollar-circle-line" accent="green" hint="at tonight's prices" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Prices to review" :value="$changes" icon="ri-price-tag-3-line" accent="amber" :hint="$r['auto'] ? 'applied automatically at 2:15 AM' : 'apply below'" /></div>
    </div>

    <x-cb.card title="Night by night" icon="ri-bar-chart-2-line">
        <div class="rv-bars" aria-hidden="true">
            @foreach ($pickup as $p)
                <div style="height: {{ round($p['sold'] / $maxPick * 100) }}%" title="{{ $p['date']->format('D j M') }}: {{ $p['sold'] }} booked, {{ $p['pickup'] }} this week"><span style="height: {{ $p['sold'] ? round($p['pickup'] / $p['sold'] * 100) : 0 }}%"></span></div>
            @endforeach
        </div>
        <div class="d-flex justify-content-between small text-muted mt-1"><span>{{ $grid['from']->format('D j M') }}</span><span>{{ $grid['to']->format('D j M') }}</span></div>
        <div class="rv-legend mt-2"><span><i style="background:#0d9488"></i>Rooms booked</span><span><i style="background:#4f46e5"></i>…of which booked in the last 7 days</span></div>
    </x-cb.card>

    <form method="POST" action="{{ route('rates.revenue.apply') }}" id="rvForm">
        @csrf
        <x-cb.card title="Suggested prices" icon="ri-price-tag-3-line" :flush="true">
            <x-slot:tools>
                <div class="rv-legend">
                    <span><i style="background:#bbf7d0"></i>{{ rtrim(rtrim(number_format($r['high_occupancy'], 1), '0'), '.') }}%+ booked: +{{ rtrim(rtrim(number_format($r['high_change'], 1), '0'), '.') }}%</span>
                    <span><i style="background:#fef08a"></i>{{ rtrim(rtrim(number_format($r['mid_occupancy'], 1), '0'), '.') }}%+: +{{ rtrim(rtrim(number_format($r['mid_change'], 1), '0'), '.') }}%</span>
                    <span><i style="background:#fecaca"></i>within {{ $r['low_days'] }} days and under {{ rtrim(rtrim(number_format($r['low_occupancy'], 1), '0'), '.') }}%: −{{ rtrim(rtrim(number_format($r['low_change'], 1), '0'), '.') }}%</span>
                </div>
            </x-slot:tools>
            <div class="table-responsive" style="max-height: 70vh">
                <table class="rv-grid">
                    <thead><tr><th>Night</th>@foreach ($types as $t)<th>{{ $t->name }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($grid['nights'] as $date => $cells)
                            @php $d = \Illuminate\Support\Carbon::parse($date); @endphp
                            <tr class="{{ $d->isWeekend() ? 'weekend' : '' }}">
                                <th>{{ $d->format('D j M') }}</th>
                                @foreach ($types as $t)
                                    @php
                                        $c = $cells[$t->id];
                                        $bg = $c['occupancy'] >= $r['high_occupancy'] ? '#bbf7d0' : ($c['occupancy'] >= $r['mid_occupancy'] ? '#fef08a' : ($c['change'] < 0 ? '#fecaca' : '#e2e8f0'));
                                    @endphp
                                    <td>
                                        <label class="rv-cell mb-0">
                                            <span class="rv-occ" style="background: {{ $bg }}" title="{{ $c['sold'] }} of {{ $c['rooms'] }} rooms booked">{{ round($c['occupancy']) }}%</span>
                                            <span class="rv-price">
                                                <span class="now">{{ $sym }}{{ number_format($c['price'], 0) }}</span>
                                                @if ($c['manual'])<span class="man d-block">set by hand</span>
                                                @elseif ($c['differs'])<span class="sug d-block {{ $c['suggested'] > $c['price'] ? 'up' : 'down' }}">→ {{ $sym }}{{ number_format($c['suggested'], 0) }}</span>@endif
                                                @if ($c['pickup'])<span class="rv-pick d-block">+{{ $c['pickup'] }} this week</span>@endif
                                            </span>
                                            @if ($c['differs'])<input type="checkbox" class="form-check-input ms-auto" name="cells[]" value="{{ $t->id }}|{{ $date }}" aria-label="Use the suggested price for {{ $t->name }} on {{ $d->format('D j M') }}" title="{{ $c['why'] }}">@endif
                                        </label>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="rv-bar">
                <button class="action-btn btn-primary-cb" type="submit"><i class="ri-check-line"></i> Use ticked suggestions</button>
                <button class="action-btn btn-more" type="submit" name="all" value="1" onclick="return confirm('Set every suggested price shown ({{ $changes }})? Nights you priced by hand stay as they are.')"><i class="ri-check-double-line"></i> Use all {{ $changes }}</button>
                <button class="action-btn btn-more" type="button" id="rvTick">Tick all</button>
                <span class="small text-muted">Undo any night in Rates &amp; Seasons → Calendar (clear its price).</span>
            </div>
        </x-cb.card>
    </form>

</div>
</div>
</div>
<script>
document.getElementById('rvTick')?.addEventListener('click', () => {
    const boxes = [...document.querySelectorAll('#rvForm input[name="cells[]"]')];
    const on = boxes.some(b => !b.checked);
    boxes.forEach(b => b.checked = on);
});
</script>
@endsection
