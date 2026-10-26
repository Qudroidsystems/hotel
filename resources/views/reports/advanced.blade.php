@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => ((float) $v < -0.004 ? '−' : '') . $sym . number_format(abs((float) $v), 2);
    $pct = fn ($v, $dp = 1) => $v === null ? '—' : number_format((float) $v, $dp) . '%';
    $qty = fn ($v) => \App\Models\Inventory\StockItem::qty($v);
    [$title, $icon, $area, $desc] = $reports[$tab];
    $dated = in_array($tab, ['profit', 'tax', 'exceptions', 'variance', 'purchasing', 'daily', 'rates', 'companies', 'events'], true);
    $base = array_filter(['report' => $tab, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'location' => $locationId, 'category' => $categoryId]);
    $subtitle = match (true) {
        $tab === 'trend'  => '12 months to ' . $to->format('F Y'),
        $tab === 'ageing' => 'As at ' . today()->format('j F Y'),
        $tab === 'stock'  => 'Right now · usage over the last ' . $data['settings']['usageDays'] . ' days',
        default           => ($from->isSameDay($to) ? $from->format('l j F Y') : $from->format('j M') . ' – ' . $to->format('j M Y')),
    };
@endphp
<style>
    .ar-nav { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; margin-bottom: 16px; }
    .ar-nav a { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--cb-border, #e9ebec); background: var(--cb-card-bg, #fff); color: inherit; text-decoration: none; }
    .ar-nav a i { font-size: 20px; color: var(--cb-muted, #64748b); }
    .ar-nav a .t { font-weight: 600; color: var(--cb-heading); font-size: 13.5px; line-height: 1.2; }
    .ar-nav a .d { font-size: 11.5px; color: var(--bs-secondary-color, #6c757d); line-height: 1.3; }
    .ar-nav a.active { border-color: #0d9488; box-shadow: 0 0 0 1px #0d9488 inset; }
    .ar-nav a.active i { color: #0d9488; }
    .ar-area { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--bs-secondary-color, #6c757d); margin: 4px 0 6px; font-weight: 600; }
    .ar-bar { height: 8px; border-radius: 4px; background: #0d9488; min-width: 2px; }
    .ar-bar.alt { background: #f59e0b; } .ar-bar.alt2 { background: #6366f1; }
    .ar-stack { display: flex; height: 10px; border-radius: 5px; overflow: hidden; background: var(--cb-border, #e9ebec); }
    .ar-line { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--cb-border, #e9ebec); }
    .ar-line.total { font-weight: 700; border-bottom: 2px solid var(--cb-heading, #333); }
    .ar-line .sub { font-size: 12px; color: var(--bs-secondary-color, #6c757d); }
    .ar-note { font-size: 12.5px; color: var(--bs-secondary-color, #6c757d); }
    @media (max-width: 767.98px) {
        .ar-nav { display: flex; overflow-x: auto; gap: 8px; padding-bottom: 4px; scrollbar-width: thin; }
        .ar-nav a { flex: 0 0 auto; align-items: center; padding: 8px 12px; }
        .ar-nav a .d { display: none; }
        .ar-nav a i { font-size: 17px; }
    }
    @media print { .ar-nav, .cb-toolbar, .cb-hero-actions, .no-print { display: none !important; } }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$title" :icon="$icon" :subtitle="$subtitle . ' · ' . $desc" :back="route('reports.index')" back-label="Management reports">
        <x-slot:actions>
            @if ($tab === 'ageing')
                <a class="cb-hero-btn" href="{{ route('reports.advanced', $base + ['export' => 'csv', 'part' => 'guests']) }}"><i class="ri-file-excel-2-line"></i> Guests CSV</a>
                <a class="cb-hero-btn" href="{{ route('reports.advanced', $base + ['export' => 'csv', 'part' => 'suppliers']) }}"><i class="ri-file-excel-2-line"></i> Suppliers CSV</a>
            @elseif ($tab === 'purchasing')
                <a class="cb-hero-btn" href="{{ route('reports.advanced', $base + ['export' => 'csv']) }}"><i class="ri-file-excel-2-line"></i> Suppliers CSV</a>
                <a class="cb-hero-btn" href="{{ route('reports.advanced', $base + ['export' => 'csv', 'part' => 'prices']) }}"><i class="ri-file-excel-2-line"></i> Price changes CSV</a>
            @else
                <a class="cb-hero-btn" href="{{ route('reports.advanced', $base + ['export' => 'csv']) }}"><i class="ri-file-excel-2-line"></i> Download CSV</a>
            @endif
            <button type="button" class="cb-hero-btn" onclick="window.print()"><i class="ri-printer-line"></i> Print / PDF</button>
        </x-slot:actions>
    </x-cb.hero>

    @foreach (['finance' => 'Financial', 'sales' => 'Sales', 'stock' => 'Inventory'] as $a => $label)
        @php $group = $reports->filter(fn ($r) => $r[2] === $a); @endphp
        @if ($group->isNotEmpty())
            <div class="ar-area">{{ $label }}</div>
            <div class="ar-nav">
                @foreach ($group as $key => [$t, $i, $ar, $d])
                    <a href="{{ route('reports.advanced', array_filter(['report' => $key, 'from' => request('from'), 'to' => request('to')])) }}" class="{{ $tab === $key ? 'active' : '' }}"><i class="{{ $i }}"></i><span><span class="t d-block">{{ $t }}</span><span class="d">{{ $d }}</span></span></a>
                @endforeach
            </div>
        @endif
    @endforeach

    @if ($dated || $tab === 'trend' || $tab === 'stock')
        <div class="cb-card mb-3">
            <form method="GET" class="cb-toolbar">
                <input type="hidden" name="report" value="{{ $tab }}">
                @if ($tab === 'trend')
                    <label class="small text-muted" for="arTo">Ending</label>
                    <input type="month" id="arTo" class="cb-select" value="{{ $to->format('Y-m') }}" onchange="this.form.to.value = this.value + '-01'">
                    <input type="hidden" name="to" value="{{ $to->toDateString() }}">
                @elseif ($dated)
                    <input type="date" name="from" class="cb-select" value="{{ $from->toDateString() }}" aria-label="From">
                    <input type="date" name="to" class="cb-select" value="{{ $to->toDateString() }}" aria-label="To">
                @endif
                @if ($locations->isNotEmpty())
                    <select name="location" class="cb-select" aria-label="Store"><option value="">All stores</option>@foreach ($locations as $l)<option value="{{ $l->id }}" @selected($locationId === $l->id)>{{ $l->name }}</option>@endforeach</select>
                @endif
                @if ($categories->isNotEmpty())
                    <select name="category" class="cb-select" aria-label="Category"><option value="">All categories</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected($categoryId === $c->id)>{{ $c->name }}</option>@endforeach</select>
                @endif
                <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Show</button>
                @if ($dated)
                    @foreach (['week' => 'This week', 'month' => 'This month', 'last_month' => 'Last month', 'quarter' => 'This quarter', 'year' => 'This year'] as $key => $label)
                        <a href="{{ route('reports.advanced', array_filter(['report' => $tab, 'period' => $key, 'location' => $locationId])) }}" class="action-btn btn-more">{{ $label }}</a>
                    @endforeach
                @endif
            </form>
        </div>
    @endif

    @include('reports.advanced.' . $tab)

</div>
</div>
</div>
@endsection
