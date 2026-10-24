@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $r = $folio->reservation; $g = $folio->guest; $bal = $summary['balance'];
    $roomNos = $r?->rooms->pluck('room.number')->filter()->join(', ');
@endphp
<style>
    .bl-void td { text-decoration: line-through; color: var(--cb-muted) !important; }
    .bl-void td.keep { text-decoration: none; }
    .bl-neg { color: #15803d; }
    .bl-sum { display: grid; grid-template-columns: 1fr auto; gap: 6px 16px; font-size: 14px; }
    .bl-sum .muted { color: var(--cb-muted); }
    .bl-sum .big { font-size: 1.35rem; font-weight: 800; }
    .bl-type { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 9px; background: var(--cb-surface-2); color: var(--cb-navy-2); flex: none; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="'Bill ' . $folio->number" icon="ri-bill-line"
               :subtitle="$g->full_name . ($r ? ' · ' . $r->reference . ' · ' . $r->check_in_date->format('j M') . ' → ' . $r->check_out_date->format('j M Y') : '') . ($roomNos ? ' · Room ' . $roomNos : '')"
               :back="$r ? route('reservations.show', $r) : route('billing.outstanding')" :back-label="$r ? 'Reservation' : 'Outstanding bills'">
        <x-slot:pills>
            <span class="cb-meta-pill"><i class="{{ $folio->isOpen() ? 'ri-lock-unlock-line' : 'ri-lock-line' }}"></i> {{ $folio->isOpen() ? 'Open' : 'Closed' }}</span>
            @if ($r)<span class="cb-meta-pill" style="background: {{ $r->status->color() }}; border-color: {{ $r->status->color() }}"><i class="{{ $r->status->icon() }}"></i> {{ $r->status->label() }}</span>@endif
            @if ($bal > 0.004)<span class="cb-meta-pill" style="background:#b91c1c;border-color:#b91c1c"><i class="ri-error-warning-line"></i> Owes @money($bal)</span>
            @elseif ($bal < -0.004)<span class="cb-meta-pill" style="background:#15803d;border-color:#15803d"><i class="ri-hand-coin-line"></i> In credit @money(-$bal)</span>
            @else<span class="cb-meta-pill"><i class="ri-checkbox-circle-line"></i> Settled</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            @can('Receive payments')
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#paymentModal" style="background:#16a34a;border-color:#16a34a"><i class="ri-bank-card-line"></i> Receive payment</button>
            @elsecan('Issue refunds')
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="ri-refund-2-line"></i> Refund</button>
            @endcan
            @can('Post charges')
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#chargeModal"><i class="ri-add-line"></i> Add charge</button>
            @endcan
            <a class="cb-hero-btn" href="{{ route('folios.print', $folio) }}" target="_blank" rel="noopener"><i class="ri-printer-line"></i> Invoice</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    @if ($r && $r->status->value === 'checked_out' && $bal > 0.004)
        <div class="cb-banner danger"><i class="ri-alarm-warning-line"></i><div>The guest checked out owing @money($bal). Chase payment and record it here when it arrives.</div></div>
    @elseif ($r && in_array($r->status->value, ['cancelled', 'no_show'], true) && $bal < -0.004)
        <div class="cb-banner warning"><i class="ri-refund-2-line"></i><div>This booking is {{ strtolower($r->status->label()) }} and the guest has @money(-$bal) on account. Refund it or keep it as a credit, per your policy.</div></div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Charges" icon="ri-file-list-3-line" :count="$charges->whereNull('voided_at')->count()" :flush="true">
                @if ($charges->isEmpty())
                    <div class="empty-state py-4"><i class="ri-file-list-3-line"></i><h6>No charges yet</h6>
                        <p class="small text-muted mb-0">Room nights are added automatically each night once the guest is checked in.</p></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Date</th><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($charges as $c)
                                    <tr class="{{ $c->isVoided() ? 'bl-void' : '' }}">
                                        <td data-label="Date" class="small text-nowrap">{{ $c->service_date->format('D j M') }}</td>
                                        <td data-label="Item">
                                            <div class="d-flex gap-2 align-items-center">
                                                <span class="bl-type"><i class="{{ $c->type->icon() }}"></i></span>
                                                <div>
                                                    <div class="fw-semibold" style="color: var(--cb-heading)">{{ $c->description }}</div>
                                                    <div class="small text-muted">{{ $c->type->label() }} · {{ $c->postedBy?->name ?? 'System' }}, {{ $c->created_at->format('j M g:i A') }}</div>
                                                </div>
                                            </div>
                                            @if ($c->isVoided())
                                                <div class="small text-danger mt-1 keep" style="text-decoration:none">Voided by {{ $c->voidedBy?->name }} · {{ $c->voided_at->format('j M g:i A') }} — {{ $c->void_reason }}</div>
                                            @endif
                                        </td>
                                        <td data-label="Qty" class="num">{{ rtrim(rtrim(number_format((float) $c->quantity, 2), '0'), '.') }}</td>
                                        <td data-label="Price" class="num text-nowrap">@money($c->unit_price)</td>
                                        <td data-label="Amount" class="num fw-semibold text-nowrap {{ $c->total < 0 ? 'bl-neg' : '' }}">{{ $c->total < 0 ? '−' : '' }}@money(abs($c->total))
                                            @if ($c->tax_amount > 0)<div class="small text-muted fw-normal">incl. tax @money($c->tax_amount)</div>@endif</td>
                                        <td class="keep text-end">
                                            @if (! $c->isVoided() && $c->source_type === null)
                                                @can('Void charges')
                                                    <button type="button" class="action-btn btn-danger-soft" data-void-url="{{ route('charges.void', $c) }}" data-void-label="“{{ $c->description }}”" title="Void"><i class="ri-forbid-line"></i></button>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-cb.card>

            <x-cb.card title="Payments" icon="ri-wallet-3-line" :count="$payments->whereNull('voided_at')->count()" :flush="true">
                @if ($payments->isEmpty())
                    <div class="empty-state py-4"><i class="ri-wallet-3-line"></i><h6>No payments yet</h6></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Receipt</th><th>Type</th><th>Method</th><th>Taken by</th><th class="num">Amount</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($payments as $p)
                                    <tr class="{{ $p->isVoided() ? 'bl-void' : '' }}">
                                        <td data-label="Receipt"><span class="rs-ref">{{ $p->receipt_number }}</span>
                                            <div class="small text-muted">{{ $p->received_at->format('D j M, g:i A') }}</div>
                                            @if ($p->isVoided())<div class="small text-danger keep" style="text-decoration:none">Voided by {{ $p->voidedBy?->name }} — {{ $p->void_reason }}</div>@endif</td>
                                        <td data-label="Type"><span class="status-pill {{ $p->kind->value === 'refund' ? 'st-danger' : ($p->kind->value === 'deposit' ? 'st-info' : 'st-success') }}">{{ $p->kind->label() }}</span></td>
                                        <td data-label="Method"><i class="{{ $p->method->icon() }} text-muted"></i> {{ $p->method->label() }}
                                            @if ($p->reference)<div class="small text-muted">Ref {{ $p->reference }}</div>@endif
                                            @if ($p->bankAccount)<div class="small text-muted">into {{ $p->bankAccount->short_label }}</div>@endif
                                            @if ($p->notes)<div class="small text-muted">{{ $p->notes }}</div>@endif</td>
                                        <td data-label="Taken by" class="small">{{ $p->receivedBy?->name ?? '—' }}</td>
                                        <td data-label="Amount" class="num fw-semibold text-nowrap">{{ $p->kind->value === 'refund' ? '−' : '' }}@money($p->amount)</td>
                                        <td class="keep text-end text-nowrap">
                                            @unless ($p->isVoided())
                                                <a href="{{ route('payments.receipt', $p) }}" target="_blank" rel="noopener" class="action-btn btn-open" title="Print receipt"><i class="ri-printer-line"></i></a>
                                                @can('Void payments')
                                                    <button type="button" class="action-btn btn-danger-soft" data-void-url="{{ route('payments.void', $p) }}" data-void-label="{{ strtolower($p->kind->label()) }} {{ $p->receipt_number }}" title="Void"><i class="ri-forbid-line"></i></button>
                                                @endcan
                                            @endunless
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            <x-cb.card title="Summary" icon="ri-calculator-line">
                <div class="bl-sum">
                    <span class="muted">Charges</span><span>@money($summary['subtotal'])</span>
                    @if ($summary['tax_added'] > 0)
                        <span class="muted">Tax added</span><span>@money($summary['tax_added'])</span>
                    @endif
                    <strong>Total</strong><strong>@money($summary['charged'])</strong>
                    <span class="muted">Paid (less refunds)</span><span>−@money($summary['paid'])</span>
                    <span class="border-top pt-2 mt-1 fw-bold">{{ $bal < -0.004 ? 'Credit' : 'Balance due' }}</span>
                    <span class="border-top pt-2 mt-1 big" style="color: {{ $bal > 0.004 ? '#b91c1c' : ($bal < -0.004 ? '#15803d' : 'var(--cb-heading)') }}">@money(abs($bal))</span>
                </div>
                @if ($summary['taxes'] && $summary['inclusive'])
                    <div class="small text-muted mt-3">Included in the prices:
                        @foreach ($summary['taxes'] as $name => $amt)<div class="d-flex justify-content-between"><span>{{ $name }}</span><span>@money($amt)</span></div>@endforeach
                    </div>
                @endif
                @if (! $folio->isOpen())
                    <div class="cb-banner success mt-3 mb-0"><i class="ri-lock-line"></i><div>Closed {{ $folio->closed_at?->format('j M Y, g:i A') }}{{ $folio->closedBy ? ' by ' . $folio->closedBy->name : '' }}. Posting anything reopens it.</div></div>
                @endif
            </x-cb.card>

            <x-cb.card title="Guest" icon="ri-user-3-line">
                <div class="fw-bold" style="color: var(--cb-heading)">{{ $g->full_name }}</div>
                @if ($g->company)<div class="small text-muted">{{ $g->company }}</div>@endif
                @if ($g->phone)<div class="small mt-2"><i class="ri-phone-line text-muted"></i> <a href="tel:{{ $g->phone }}">{{ $g->phone }}</a></div>@endif
                @if ($g->email)<div class="small"><i class="ri-mail-line text-muted"></i> <a href="mailto:{{ $g->email }}">{{ $g->email }}</a></div>@endif
                @if ($r)
                    <div class="mt-3"><a href="{{ route('reservations.show', $r) }}" class="action-btn btn-open"><i class="ri-calendar-check-line"></i> Reservation {{ $r->reference }}</a></div>
                @endif
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>

@include('billing.partials.modals', ['balance' => $bal, 'paidSoFar' => $summary['paid']])
@endsection
