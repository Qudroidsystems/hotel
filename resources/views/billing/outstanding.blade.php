@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $tabs = ['all' => ['All', 'ri-list-check-2'], 'departed' => ['Checked out owing', 'ri-alarm-warning-line'], 'inhouse' => ['In house', 'ri-hotel-bed-line'], 'credit' => ['Credits to refund', 'ri-refund-2-line']];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Outstanding Bills" icon="ri-error-warning-line" subtitle="Open bills with money owed — by guests, or to them">
        <x-slot:actions>
            @canany(['View finance reports', 'Receive payments'])<a class="cb-hero-btn" href="{{ route('billing.payments') }}"><i class="ri-hand-coin-line"></i> Collections</a>@endcanany
        </x-slot:actions>
    </x-cb.hero>

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Total owed to the hotel" :value="$sym . number_format($stats['owed'], 2)" icon="ri-funds-line" accent="rose" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Checked out owing" :value="$sym . number_format($stats['departed'], 2)" icon="ri-alarm-warning-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="In-house balances" :value="$sym . number_format($stats['inhouse'], 2)" icon="ri-hotel-bed-line" accent="violet" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Guest credits" :value="$sym . number_format($stats['credit'], 2)" icon="ri-refund-2-line" accent="green" /></div>
    </div>

    <div class="rs-tabs" role="tablist">
        @foreach ($tabs as $key => [$label, $icon])
            <a href="{{ route('billing.outstanding', ['tab' => $key]) }}" class="rs-tab {{ $tab === $key ? 'active' : '' }}" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"><i class="{{ $icon }}"></i> {{ $label }} <span class="n">{{ $counts[$key] }}</span></a>
        @endforeach
    </div>

    <div class="cb-card">
        @if ($list->isEmpty())
            <div class="empty-state py-5"><i class="ri-checkbox-circle-line"></i><h6>Nothing outstanding here</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Guest</th><th>Stay</th><th>Status</th><th class="num">Charges</th><th class="num">Paid</th><th class="num">Balance</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($list as $f)
                            @php $r = $f->reservation; $b = $f->totals['balance']; @endphp
                            <tr>
                                <td data-label="Guest"><a href="{{ route('folios.show', $f) }}" class="fw-bold text-reset">{{ $f->guest->full_name }}</a>
                                    <div class="small text-muted">{{ $f->number }}{{ $f->guest->phone ? ' · ' . $f->guest->phone : '' }}</div></td>
                                <td data-label="Stay" class="small">@if ($r){{ $r->check_in_date->format('j M') }} → {{ $r->check_out_date->format('j M') }}
                                    <div class="text-muted">{{ $r->reference }}{{ $r->rooms->pluck('room.number')->filter()->isNotEmpty() ? ' · Rm ' . $r->rooms->pluck('room.number')->filter()->join(', ') : '' }}</div>@endif</td>
                                <td data-label="Status">@if ($r)@include('reservations.partials.status-pill', ['status' => $r->status])@endif</td>
                                <td data-label="Charges" class="num">@money($f->totals['charges'])</td>
                                <td data-label="Paid" class="num">@money($f->totals['paid'])</td>
                                <td data-label="Balance" class="num fw-bold" style="color: {{ $b > 0 ? '#b91c1c' : '#15803d' }}">{{ $b < 0 ? 'Credit ' : '' }}@money(abs($b))</td>
                                <td class="text-end"><a href="{{ route('folios.show', $f) }}" class="action-btn btn-open">Open bill</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
