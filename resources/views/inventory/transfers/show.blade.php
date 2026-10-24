@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Inventory\StockItem; $t = $transfer; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    <x-cb.hero :title="$t->number" icon="ri-arrow-left-right-line" :subtitle="$t->from->name . ' → ' . $t->to->name . ' · ' . $t->created_at->format('j M Y, g:i A')" :back="route('inventory.transfers.index')" back-label="Transfers">
        <x-slot:actions><a class="cb-hero-btn" href="{{ route('inventory.transfers.show', [$t, 'print' => 1]) }}" target="_blank" rel="noopener"><i class="ri-printer-line"></i> Print</a></x-slot:actions>
    </x-cb.hero>
    @include('rooms.partials.flash')
    <x-cb.card title="Items moved" icon="ri-archive-line" :flush="true">
        <table class="cb-table stack">
            <thead><tr><th>Item</th><th class="num">Quantity</th></tr></thead>
            <tbody>@foreach ($t->lines as $l)<tr><td data-label="Item"><a href="{{ route('inventory.items.show', $l->item) }}" class="fw-semibold text-reset">{{ $l->item->name }}</a></td><td data-label="Quantity" class="num">{{ StockItem::qty($l->quantity) }} {{ $l->item->unit }}</td></tr>@endforeach</tbody>
        </table>
        <div class="p-3 small text-muted">By {{ $t->createdBy?->name }}{{ $t->notes ? ' · ' . $t->notes : '' }}</div>
    </x-cb.card>
</div>
</div>
</div>
@endsection
