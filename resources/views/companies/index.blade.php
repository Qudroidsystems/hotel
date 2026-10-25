@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $sym = config('hotel.currency_symbol'); $fmt = fn ($v) => $sym . number_format((float) $v, 2); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Company Accounts" icon="ri-building-2-line" subtitle="Companies whose staff stay or hold events here and are billed later (city ledger): what each owes, what is overdue, and statements.">
        @can('Manage company accounts')
            <x-slot:actions><a class="cb-hero-btn" href="{{ route('companies.create') }}"><i class="ri-add-line"></i> New company</a></x-slot:actions>
        @endcan
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Owed by companies" :value="$fmt($totals['owed'])" icon="ri-hand-coin-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Overdue" :value="$fmt($totals['overdue'])" icon="ri-time-line" accent="rose" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Accounts" :value="$totals['count']" icon="ri-building-2-line" accent="sky" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Over their credit limit" :value="$totals['over']" icon="ri-alert-line" accent="amber" /></div>
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $q }}" placeholder="Company, account number, contact…" aria-label="Search companies"></div>
            <select name="show" class="cb-select" onchange="this.form.submit()" aria-label="Show">
                @foreach (['all' => 'All open accounts', 'owing' => 'Owing money', 'overdue' => 'Overdue', 'closed' => 'Closed accounts'] as $k => $l)<option value="{{ $k }}" @selected($show === $k)>{{ $l }}</option>@endforeach
            </select>
            <button class="action-btn btn-open" type="submit"><i class="ri-search-line"></i> Search</button>
        </form>

        @if ($companies->isEmpty())
            <div class="empty-state"><i class="ri-building-2-line"></i><h6>No companies here</h6>@can('Manage company accounts')<p class="small text-muted">Open an account for a company that sends guests or holds events and pays later.</p>@endcan</div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Company</th><th>Contact</th><th class="num">Owes</th><th class="num">Overdue</th><th class="num">Credit limit</th><th class="num">Stays</th></tr></thead>
                    <tbody>
                        @foreach ($companies as $c)
                            @php $over = (float) $c->credit_limit > 0 && $c->balance > (float) $c->credit_limit; @endphp
                            <tr>
                                <td data-label="Company"><a href="{{ route('companies.show', $c) }}" class="fw-semibold">{{ $c->name }}</a><div class="small text-muted">{{ $c->account_number }} · {{ $c->payment_terms_days }} days to pay</div></td>
                                <td data-label="Contact" class="small">{{ $c->contact_name ?: '—' }}@if ($c->phone)<div class="text-muted">{{ $c->phone }}</div>@endif</td>
                                <td data-label="Owes" class="num fw-semibold {{ $c->balance < 0 ? 'text-success' : '' }}">{{ $c->balance < 0 ? 'In credit ' . $fmt(-$c->balance) : $fmt($c->balance) }}</td>
                                <td data-label="Overdue" class="num {{ ($c->ageing['overdue'] ?? 0) > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">{{ ($c->ageing['overdue'] ?? 0) > 0 ? $fmt($c->ageing['overdue']) : '—' }}</td>
                                <td data-label="Credit limit" class="num">{{ (float) $c->credit_limit > 0 ? $fmt($c->credit_limit) : 'No limit' }}@if ($over)<div><span class="status-pill st-danger">Over limit</span></div>@endif</td>
                                <td data-label="Stays" class="num">{{ $c->stays_count }}</td>
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
