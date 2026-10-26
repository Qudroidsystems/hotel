@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $m = fn ($v) => $sym . number_format((float) $v, 2);
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . '%';
    $period = $month->format('Y-m');
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Commission" icon="ri-percent-line" subtitle="What each hotel owes the group on stays booked through the group booking site or the guest app, month by month.">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('group.index') }}"><i class="ri-community-line"></i> Group overview</a>
            @can('Manage hotels')<a class="cb-hero-btn" href="{{ route('settings.hotels.index') }}"><i class="ri-settings-3-line"></i> Rates</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat :label="'Owed for ' . $month->format('M Y')" :value="$m($rows->sum('due'))" icon="ri-file-list-3-line" accent="violet" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Paid" :value="$m($rows->sum('paid'))" icon="ri-hand-coin-line" accent="green" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Still to come in" :value="$m(max(0, $rows->sum('balance')))" icon="ri-time-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="On bookings not stayed yet" :value="$m($rows->sum('coming_value'))" icon="ri-calendar-event-line" accent="sky" /></div>
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <label class="small text-muted mb-0">Stays that ended in</label>
            <input type="month" name="month" value="{{ $period }}" max="{{ today()->format('Y-m') }}" class="cb-select" onchange="this.form.submit()" aria-label="Month">
        </form>
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Hotel</th><th class="num">Rate</th><th class="num">Stays</th><th class="num">Booking value</th><th class="num">Commission</th><th class="num">Paid</th><th class="num">Balance</th><th class="num">Booked, not stayed</th><th></th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td data-label="Hotel" class="fw-semibold">{{ $r['hotel']->name }}</td>
                            <td data-label="Rate" class="num">{{ $pct($r['rate']) }}</td>
                            <td data-label="Stays" class="num">{{ $r['stays'] }}</td>
                            <td data-label="Booking value" class="num">{{ $m($r['value']) }}</td>
                            <td data-label="Commission" class="num fw-semibold">{{ $m($r['due']) }}</td>
                            <td data-label="Paid" class="num">{{ $m($r['paid']) }}</td>
                            <td data-label="Balance" class="num {{ $r['balance'] > 0.004 ? 'fw-semibold' : 'text-muted' }}">{{ $r['balance'] > 0.004 ? $m($r['balance']) : 'Settled' }}</td>
                            <td data-label="Booked, not stayed" class="num small">{{ $r['coming'] }} · {{ $m($r['coming_value']) }}</td>
                            <td class="text-end text-nowrap">
                                <a class="action-btn btn-more" href="{{ route('group.statement', ['hotel' => $r['hotel'], 'month' => $period]) }}" title="Statement"><i class="ri-printer-line"></i></a>
                                @if ($r['balance'] > 0.004)
                                    <button type="button" class="action-btn btn-open" data-bs-toggle="collapse" data-bs-target="#pay-{{ $r['hotel']->id }}">Record payment</button>
                                @endif
                            </td>
                        </tr>
                        @if ($r['balance'] > 0.004)
                            <tr class="collapse" id="pay-{{ $r['hotel']->id }}"><td colspan="9">
                                <form method="POST" action="{{ route('group.commission.pay') }}" class="row g-2 align-items-end">
                                    @csrf <input type="hidden" name="hotel_id" value="{{ $r['hotel']->id }}"><input type="hidden" name="period" value="{{ $period }}">
                                    <div class="col-md-2"><label class="form-label small">Amount</label><input name="amount" type="number" step="0.01" min="0.01" class="form-control form-control-sm" value="{{ $r['balance'] }}" required></div>
                                    <div class="col-md-2"><label class="form-label small">Received</label><input name="received_on" type="date" class="form-control form-control-sm" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required></div>
                                    <div class="col-md-3"><label class="form-label small">Reference</label><input name="reference" class="form-control form-control-sm" maxlength="100" placeholder="Transfer reference"></div>
                                    <div class="col-md-3"><label class="form-label small">Note</label><input name="note" class="form-control form-control-sm" maxlength="255"></div>
                                    <div class="col-md-2"><button class="action-btn btn-primary-cb w-100">Save</button></div>
                                </form>
                            </td></tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($payments->isNotEmpty())
        <x-cb.card title="Payments for {{ $month->format('F Y') }}" icon="ri-hand-coin-line" :flush="true">
            <table class="cb-table">
                <thead><tr><th>Received</th><th>Hotel</th><th class="num">Amount</th><th>Reference</th><th>Recorded by</th></tr></thead>
                <tbody>@foreach ($payments as $p)<tr><td>{{ $p->received_on->format('j M Y') }}</td><td>{{ $p->hotel?->name }}</td><td class="num">{{ $m($p->amount) }}</td><td class="small">{{ $p->reference }}{{ $p->note ? ' · ' . $p->note : '' }}</td><td class="small">{{ $p->recorder?->name }}</td></tr>@endforeach</tbody>
            </table>
        </x-cb.card>
    @endif
    <p class="small text-muted mt-2">A stay counts in the month the guest checked out. Cancellations and no-shows carry no commission. Each hotel's guests pay the hotel directly, so the hotel pays the group its commission.</p>

</div>
</div>
</div>
@endsection
