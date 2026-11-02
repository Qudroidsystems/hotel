@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => $sym . number_format((float) $v, 2);
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Event Tickets" icon="ri-coupon-3-line" :back="route('events.index')" back-label="Events & Halls" subtitle="Every ticket sale: find a buyer, reprint tickets, or cancel an order.">
        <x-slot:actions>
            @canany(['Check event tickets', 'Sell event tickets'])<a class="cb-hero-btn" href="{{ route('events.tickets.door') }}"><i class="ri-qr-scan-2-line"></i> Check tickets</a>@endcanany
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Order, buyer, phone or ticket code…" aria-label="Search orders"></div>
            <select name="event" class="cb-select" onchange="this.form.submit()" aria-label="Event"><option value="">All events</option>@foreach ($events as $ev)<option value="{{ $ev->id }}" @selected((string) request('event') === (string) $ev->id)>{{ $ev->title }} · {{ $ev->starts_at->format('j M Y') }}</option>@endforeach</select>
            <button class="action-btn btn-open" type="submit"><i class="ri-search-line"></i> Search</button>
        </form>
        @if ($orders->isEmpty())
            <div class="empty-state"><i class="ri-coupon-3-line"></i><h6>No ticket sales yet</h6><p class="small text-muted">Turn tickets on from an event's page, add ticket types and open sales.</p></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Order</th><th>Event</th><th>Buyer</th><th class="num">Tickets</th><th>Paid</th><th class="num">Total</th></tr></thead>
                    <tbody>
                        @foreach ($orders as $o)
                            <tr style="{{ $o->isCancelled() ? 'opacity:.55' : '' }}">
                                <td data-label="Order" class="small"><a href="{{ route('events.tickets.order', $o) }}" class="fw-semibold">{{ $o->number }}</a><div class="text-muted">{{ $o->paid_at?->format('D j M, g:i A') ?? ($o->status === 'pending' ? 'Waiting for payment' : 'Not paid in time') }}</div></td>
                                <td data-label="Event" class="small"><a href="{{ route('events.show', $o->event) }}">{{ $o->event->title }}</a><div class="text-muted">{{ $o->event->starts_at->format('D j M Y') }}</div></td>
                                <td data-label="Buyer" class="small">{{ $o->buyer_name }}<div class="text-muted">{{ $o->buyer_phone }}{{ $o->channel === 'app' ? ' · guest app' : '' }}</div></td>
                                <td data-label="Tickets" class="num">{{ $o->quantity }}</td>
                                <td data-label="Paid" class="small">{{ $o->method_label }}{{ $o->reservation ? ' · ' . $o->reservation->reference : '' }}@if ($o->isCancelled())<div class="text-danger">Cancelled</div>@endif</td>
                                <td data-label="Total" class="num fw-semibold">{{ $fmt($o->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($orders->hasPages())<div class="p-3">{{ $orders->links() }}</div>@endif
        @endif
    </div>

</div>
</div>
</div>
@endsection
