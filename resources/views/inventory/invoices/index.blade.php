@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-bill-line" subtitle="Bills from suppliers, checked against what was delivered, and what the hotel still owes.">
        <x-slot:actions>@can('Manage supplier invoices')<a class="cb-hero-btn" href="{{ route('inventory.invoices.create') }}"><i class="ri-add-line"></i> Record invoice</a>@endcan</x-slot:actions>
    </x-cb.hero>
    @can('View stock')
        @include('inventory.partials.nav')
    @endcan
    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Owed to suppliers" :value="config('hotel.currency_symbol') . number_format($stats['owed'], 2)" icon="ri-bill-line" accent="teal" :hint="$owed->count() . ' ' . Str::plural('supplier', $owed->count())" /></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('inventory.invoices.index', ['show' => 'overdue']) }}"><x-cb.stat label="Overdue" :value="config('hotel.currency_symbol') . number_format($stats['overdue'], 2)" icon="ri-alarm-warning-line" accent="rose" /></a></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Due in the next 7 days" :value="config('hotel.currency_symbol') . number_format($stats['due_week'], 2)" icon="ri-calendar-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Paid this month" :value="config('hotel.currency_symbol') . number_format($stats['paid_month'], 2)" icon="ri-hand-coin-line" accent="green" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="cb-card">
                <form method="GET" class="cb-toolbar">
                    <select name="show" class="cb-select" aria-label="Show" onchange="this.form.submit()">
                        @foreach (['open' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Void', 'all' => 'All'] as $k => $label)<option value="{{ $k }}" @selected($show === $k)>{{ $label }}</option>@endforeach
                    </select>
                    <select name="supplier" class="cb-select" aria-label="Supplier" onchange="this.form.submit()">
                        <option value="">All suppliers</option>
                        @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected($supplierId === $s->id)>{{ $s->name }}</option>@endforeach
                    </select>
                    <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Invoice number…" aria-label="Search"></div>
                </form>
                @if ($invoices->isEmpty())
                    <div class="empty-state py-5"><i class="ri-bill-line"></i><h6>No invoices here</h6></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Invoice</th><th>Supplier</th><th>Due</th><th class="num">Amount</th><th class="num">Balance</th><th>Status</th></tr></thead>
                            <tbody>
                                @foreach ($invoices as $inv)
                                    <tr>
                                        <td data-label="Invoice"><a href="{{ route('inventory.invoices.show', $inv) }}" class="fw-semibold text-reset">{{ $inv->invoice_number }}</a>
                                            <div class="small text-muted">{{ $inv->number }} · {{ $inv->invoice_date->format('j M Y') }}{{ $inv->receipt ? ' · ' . $inv->receipt->number : '' }}</div></td>
                                        <td data-label="Supplier">{{ $inv->supplier->name }}</td>
                                        <td data-label="Due" class="{{ $inv->isOverdue() ? 'text-danger fw-semibold' : '' }}">{{ $inv->due_date->format('j M Y') }}@if ($inv->isOverdue())<div class="small">{{ $inv->due_date->diffInDays(today()) }} days late</div>@endif</td>
                                        <td data-label="Amount" class="num">@money($inv->amount)</td>
                                        <td data-label="Balance" class="num fw-semibold">@money($inv->balance())</td>
                                        <td data-label="Status"><span class="status-pill {{ $inv->status_pill }}">{{ $inv->status_label }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $invoices->links() }}</div>
                @endif
            </div>
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Who we owe" icon="ri-store-3-line" :count="$owed->count()">
                @forelse ($owed as $o)
                    <a href="{{ route('inventory.invoices.index', ['supplier' => $o['supplier']->id]) }}" class="d-flex justify-content-between py-2 border-bottom text-reset text-decoration-none">
                        <span><span class="fw-semibold">{{ $o['supplier']->name }}</span><div class="small text-muted">{{ $o['count'] }} {{ Str::plural('invoice', $o['count']) }}@if ($o['overdue'] > 0) · <span class="text-danger">@money($o['overdue']) overdue</span>@endif</div></span>
                        <strong>@money($o['balance'])</strong>
                    </a>
                @empty
                    <div class="small text-muted">Nothing owed.</div>
                @endforelse
            </x-cb.card>
            @can('Manage supplier invoices')
                @if ($uninvoiced->isNotEmpty())
                    <x-cb.card title="Deliveries with no bill yet" icon="ri-truck-line" :count="$uninvoiced->count()">
                        @foreach ($uninvoiced as $r)
                            <div class="d-flex justify-content-between align-items-center py-2 border-bottom small">
                                <span><a href="{{ route('inventory.receipts.show', $r) }}">{{ $r->number }}</a> · {{ $r->supplier?->name }}<div class="text-muted">{{ $r->received_on->format('j M') }} · @money($r->total)</div></span>
                                <a href="{{ route('inventory.invoices.create', ['receipt' => $r->id]) }}" class="action-btn btn-open">Record bill</a>
                            </div>
                        @endforeach
                    </x-cb.card>
                @endif
            @endcan
        </div>
    </div>
</div>
</div>
</div>
@endsection
