@extends('layouts.guest', ['title' => 'Available rooms', 'noindex' => true])

@section('content')
@php
    $nights = $in->diffInDays($out);
    $party = $adults . ' adult' . ($adults == 1 ? '' : 's') . ($children ? ', ' . $children . ' child' . ($children == 1 ? '' : 'ren') : '') . ($rooms > 1 ? ', ' . $rooms . ' rooms' : '');
    $bookable = $results->where('bookable', true);
@endphp
<div class="bar">
    <div class="wrap">
        <div class="panel">@include('booking._search', ['q' => $q, 'compact' => true])</div>
    </div>
</div>
<div class="wrap">
    @include('rooms.partials.flash-guest')
    <h1 class="serif page-h">{{ $bookable->count() ? $bookable->count() . ' room type' . ($bookable->count() == 1 ? '' : 's') . ' free' : 'Fully booked' }}</h1>
    <p class="muted">{{ $in->format('D j M') }} to {{ $out->format('D j M Y') }}, {{ $nights }} night{{ $nights == 1 ? '' : 's' }} for {{ $party }}. Prices are for the whole stay{{ config('hotel.prices_include_tax') ? ', taxes included' : '' }}.</p>

    @if ($bookable->isEmpty())
        <div class="notice warn"><i class="ri-calendar-close-line"></i><div>Nothing is free for these dates and guests. Try other dates or fewer guests per room{{ config('hotel.booking.contact_phone') ? ', or call us on ' . config('hotel.booking.contact_phone') : '' }}.</div></div>
    @endif

    @foreach ($results as $r)
        <div id="t{{ $r->type->id }}">
        @include('booking._room', [
            'type' => $r->type,
            'price' => $r->total,
            'priceNote' => $rooms > 1 ? $rooms . ' rooms, ' . $nights . ' night' . ($nights == 1 ? '' : 's') : 'for ' . $nights . ' night' . ($nights == 1 ? '' : 's') . ' (' . config('hotel.currency_symbol') . number_format($r->per_room / max(1, $nights)) . ' a night)',
            'note' => ! $r->fits ? 'Too many guests for one room — add a room' : (! $r->bookable ? 'Sold out for these dates' : ($r->available <= 2 ? 'Only ' . $r->available . ' left' : null)),
            'action' => $r->bookable ? route('book.details', $q + ['type' => $r->type->id]) : null,
            'actionLabel' => 'Book this room',
        ])
        </div>
    @endforeach
</div>
@endsection

@push('head')
<style>
    .bar { background: var(--ink); padding: 18px 0; }
    .bar .panel { padding: 14px 16px; }
    .page-h { font-size: 32px; margin: 30px 0 6px; }
    .page-h + .muted { margin: 0 0 18px; }
</style>
@endpush
