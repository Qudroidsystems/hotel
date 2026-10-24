@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Enums\PaymentMethod; $q = array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'method' => $method?->value, 'cashier' => $cashier ?: null]); @endphp
<style>.bl-void td { text-decoration: line-through; color: var(--cb-muted) !important; } .bl-void td.keep { text-decoration: none; }</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-hand-coin-line"
               :subtitle="($from->equalTo($to) ? $from->format('l j F Y') : $from->format('j M') . ' – ' . $to->format('j M Y')) . ($everyone ? '' : ' · payments you took')">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('billing.payments', $q + ['print' => 1]) }}" target="_blank" rel="noopener"><i class="ri-printer-line"></i> Print cash-up</a>
            @can('View folios')<a class="cb-hero-btn" href="{{ route('billing.outstanding') }}"><i class="ri-error-warning-line"></i> Outstanding bills</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Guest bill payments" :value="config('hotel.currency_symbol') . number_format($totals['received'], 2)" icon="ri-download-2-line" accent="green" :hint="$totals['refunded'] > 0 ? 'less ' . config('hotel.currency_symbol') . number_format($totals['refunded'], 2) . ' refunded' : null" /></div>
        <div class="col-6 col-lg-3"><a href="{{ route('pos.orders', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="text-decoration-none"><x-cb.stat label="Bar & restaurant (paid at till)" :value="config('hotel.currency_symbol') . number_format($totals['pos'], 2)" icon="ri-goblet-line" accent="amber" :hint="$totals['pos_count'] . ' sale' . ($totals['pos_count'] === 1 ? '' : 's')" /></a></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Net collected" :value="config('hotel.currency_symbol') . number_format($totals['net'], 2)" icon="ri-safe-2-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Voided" :value="$totals['voided']" icon="ri-forbid-line" accent="rose" hint="bill payments, not counted" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-4 order-xl-2">
            <x-cb.card title="By method" icon="ri-pie-chart-2-line">
                @forelse ($byMethod as $row)
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <span><i class="{{ $row['method']->icon() }} text-muted me-1"></i> {{ $row['method']->label() }} <span class="small text-muted">({{ $row['count'] }})</span></span>
                        <span class="text-end"><strong>@money($row['net'])</strong>
                            @if ($row['pos'] > 0 || $row['out'] > 0)<div class="small text-muted">bills @money($row['in']){{ $row['out'] > 0 ? ' − ' . config('hotel.currency_symbol') . number_format($row['out'], 2) . ' refunds' : '' }}{{ $row['pos'] > 0 ? ' · bar ' . config('hotel.currency_symbol') . number_format($row['pos'], 2) : '' }}</div>@endif</span>
                    </div>
                @empty
                    <div class="text-muted small">Nothing collected in this period.</div>
                @endforelse
                @if ($byMethod->has('cash'))
                    <div class="cb-banner info mt-3 mb-0"><i class="ri-money-dollar-box-line"></i><div>Cash in the drawer(s) should be <strong>@money($byMethod['cash']['net'])</strong>{{ $everyone && ! $cashier ? ' (all cashiers and tills)' : '' }}.</div></div>
                @endif
            </x-cb.card>
            @if ($byAccount->isNotEmpty())
                <x-cb.card title="Into bank accounts" icon="ri-bank-line">
                    @foreach ($byAccount as $row)
                        <div class="d-flex justify-content-between py-2 border-bottom"><span>{{ $row['account']?->bank_name }} <span class="small text-muted">{{ $row['account']?->account_number }}</span></span><strong>@money($row['amount'])</strong></div>
                    @endforeach
                    <div class="small text-muted mt-2">Tick these off against each bank statement.</div>
                </x-cb.card>
            @endif
        </div>

        <div class="col-xl-8 order-xl-1">
            <div class="cb-card">
                <form method="GET" class="cb-toolbar">
                    <input type="date" name="from" class="cb-select" value="{{ $from->toDateString() }}" aria-label="From">
                    <input type="date" name="to" class="cb-select" value="{{ $to->toDateString() }}" aria-label="To">
                    <select name="method" class="cb-select" aria-label="Method" onchange="this.form.submit()">
                        <option value="">Any method</option>
                        @foreach (PaymentMethod::cases() as $m)<option value="{{ $m->value }}" @selected($method === $m)>{{ $m->label() }}</option>@endforeach
                    </select>
                    @if ($everyone)
                        <select name="cashier" class="cb-select" aria-label="Taken by" onchange="this.form.submit()">
                            <option value="">Everyone</option>
                            @foreach ($cashiers as $u)<option value="{{ $u->id }}" @selected($cashier === $u->id)>{{ $u->name }}</option>@endforeach
                        </select>
                    @endif
                    <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Show</button>
                    <a href="{{ route('billing.payments', ['from' => today()->subDay()->toDateString()]) }}" class="action-btn btn-more">Yesterday</a>
                    <a href="{{ route('billing.payments', ['from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()]) }}" class="action-btn btn-more">This month</a>
                </form>

                @if ($all->isEmpty())
                    <div class="empty-state py-5"><i class="ri-hand-coin-line"></i><h6>No payments in this period</h6></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Receipt</th><th>Guest</th><th>Type</th><th>Method</th>@if ($everyone)<th>Taken by</th>@endif<th class="num">Amount</th></tr></thead>
                            <tbody>
                                @foreach ($all as $p)
                                    <tr class="{{ $p->isVoided() ? 'bl-void' : '' }}">
                                        <td data-label="Receipt"><a href="{{ route('payments.receipt', $p) }}" target="_blank" rel="noopener" class="rs-ref">{{ $p->receipt_number }}</a>
                                            <div class="small text-muted">{{ $p->received_at->format('j M, g:i A') }}</div>
                                            @if ($p->isVoided())<div class="small text-danger keep">Voided — {{ $p->void_reason }}</div>@endif</td>
                                        <td data-label="Guest">
                                            @can('View folios')<a href="{{ route('folios.show', $p->folio) }}" class="fw-semibold text-reset">{{ $p->folio->guest->full_name }}</a>
                                            @else<span class="fw-semibold">{{ $p->folio->guest->full_name }}</span>@endcan
                                            <div class="small text-muted">{{ $p->folio->number }}{{ $p->folio->reservation ? ' · ' . $p->folio->reservation->reference : '' }}</div></td>
                                        <td data-label="Type"><span class="status-pill {{ $p->kind->value === 'refund' ? 'st-danger' : ($p->kind->value === 'deposit' ? 'st-info' : 'st-success') }}">{{ $p->kind->label() }}</span></td>
                                        <td data-label="Method">{{ $p->method->label() }}@if ($p->reference)<div class="small text-muted">Ref {{ $p->reference }}</div>@endif @if ($p->bankAccount)<div class="small text-muted">into {{ $p->bankAccount->short_label }}</div>@endif</td>
                                        @if ($everyone)<td data-label="Taken by" class="small">{{ $p->receivedBy?->name }}</td>@endif
                                        <td data-label="Amount" class="num fw-semibold">{{ $p->kind->value === 'refund' ? '−' : '' }}@money($p->amount)</td>
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
</div>
</div>
@endsection
