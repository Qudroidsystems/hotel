@extends('billing.print-layout')
@php $r = $folio->reservation; $g = $folio->guest; $bal = $summary['balance']; @endphp
@section('title', 'Invoice ' . $folio->number)
@section('heading', $bal > 0.004 ? 'Invoice' : 'Guest bill')
@section('back', route('folios.show', $folio))

@section('body')
    <div class="cols">
        <div>
            <div class="lbl">Billed to</div>
            <strong>{{ $g->full_name }}</strong>
            @if ($g->company)<div>{{ $g->company }}</div>@endif
            @if ($g->address)<div class="muted">{{ $g->address }}</div>@endif
            @if ($g->phone)<div class="muted">{{ $g->phone }}</div>@endif
            @if ($g->email)<div class="muted">{{ $g->email }}</div>@endif
        </div>
        <div>
            <div class="lbl">Bill no.</div><div class="ref">{{ $folio->number }}</div>
            @if ($r)
                <div class="lbl" style="margin-top:8px">Booking</div><div class="ref">{{ $r->reference }}</div>
            @endif
        </div>
        @if ($r)
            <div>
                <div class="lbl">Stay</div>
                <div>{{ $r->check_in_date->format('D j M Y') }} → {{ $r->check_out_date->format('D j M Y') }}</div>
                <div class="muted">{{ $r->nights }} night{{ $r->nights === 1 ? '' : 's' }}@if ($r->rooms->pluck('room.number')->filter()->isNotEmpty()) · Room {{ $r->rooms->pluck('room.number')->filter()->join(', ') }}@endif</div>
            </div>
        @endif
        <div style="text-align:right">
            @if ($bal > 0.004)<span class="stamp" style="background:#b91c1c">BALANCE DUE</span>
            @elseif ($bal < -0.004)<span class="stamp" style="background:#0d9488">IN CREDIT</span>
            @else<span class="stamp" style="background:#15803d">PAID</span>@endif
        </div>
    </div>

    <table>
        <thead><tr><th>Date</th><th>Description</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
        <tbody>
            @forelse ($charges as $c)
                <tr>
                    <td class="muted" style="white-space:nowrap">{{ $c->service_date->format('d/m/Y') }}</td>
                    <td>{{ $c->description }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $c->quantity, 2), '0'), '.') }}</td>
                    <td class="num">@money($c->unit_price)</td>
                    <td class="num">{{ $c->total < 0 ? '−' : '' }}@money(abs($c->total))</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No charges.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span>@money($summary['subtotal'])</span></div>
        @if ($summary['tax_added'] > 0)
            @foreach ($summary['taxes'] as $name => $amt)<div><span>{{ $name }}</span><span>@money($amt)</span></div>@endforeach
        @endif
        <div style="font-weight:700"><span>Total</span><span>@money($summary['charged'])</span></div>
        @foreach ($payments as $p)
            <div class="muted"><span>{{ $p->kind->label() }} · {{ $p->method->label() }}{{ $p->bankAccount ? ' (' . $p->bankAccount->short_label . ')' : '' }} · {{ $p->received_at->format('d/m') }} ({{ $p->receipt_number }})</span><span>{{ $p->kind->value === 'refund' ? '+' : '−' }}@money($p->amount)</span></div>
        @endforeach
        <div class="grand"><span>{{ $bal < -0.004 ? 'Credit' : 'Balance due' }}</span><span>@money(abs($bal))</span></div>
    </div>

    @if ($summary['taxes'] && $summary['inclusive'])
        <div class="note">Prices include
            {{ collect($summary['taxes'])->map(fn ($amt, $name) => $name . ' (' . config('hotel.currency_symbol') . number_format($amt, 2) . ')')->join(', ') }}.</div>
    @endif
    @if ($bal > 0.004 && $bankAccounts->isNotEmpty())
        <div class="note">
            <strong>Pay by bank transfer</strong> — please use <strong>{{ $folio->number }}</strong> as the payment reference.
            <table style="margin-top:8px;font-size:12.5px">
                <tbody>
                    @foreach ($bankAccounts as $acc)
                        <tr><td style="padding:4px 0"><strong>{{ $acc->bank_name }}</strong>{{ $acc->branch ? ', ' . $acc->branch : '' }}</td>
                            <td style="padding:4px 8px"><span class="ref">{{ $acc->account_number }}</span></td>
                            <td style="padding:4px 0">{{ $acc->account_name }}{{ $acc->currency !== 'NGN' ? ' (' . $acc->currency . ')' : '' }}{{ $acc->sort_code ? ' · ' . $acc->sort_code : '' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <div class="note">Thank you for staying with us.</div>
@endsection
