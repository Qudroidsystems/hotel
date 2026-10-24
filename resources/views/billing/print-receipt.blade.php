@extends('billing.print-layout')
@php $f = $payment->folio; $r = $f->reservation; $refund = $payment->kind->value === 'refund'; @endphp
@section('title', 'Receipt ' . $payment->receipt_number)
@section('heading', $refund ? 'Refund receipt' : ($payment->kind->value === 'deposit' ? 'Deposit receipt' : 'Payment receipt'))
@section('width', '520px')
@section('back', route('folios.show', $f))

@section('body')
    @if ($payment->isVoided())
        <div class="note" style="background:#fee2e2;color:#b91c1c;margin:0 0 14px"><strong>VOID</strong> — {{ $payment->void_reason }}</div>
    @endif
    <div style="text-align:center;margin:6px 0 16px">
        <div class="muted">{{ $refund ? 'Amount refunded' : 'Amount received' }}</div>
        <div style="font-size:30px;font-weight:800;color:#0f2342">@money($payment->amount)</div>
        <div class="ref">{{ $payment->receipt_number }}</div>
    </div>
    <table style="margin-top:0">
        <tbody>
            <tr><td class="muted">{{ $refund ? 'Paid to' : 'Received from' }}</td><td class="num"><strong>{{ $f->guest->full_name }}</strong></td></tr>
            <tr><td class="muted">Date</td><td class="num">{{ $payment->received_at->format('j M Y, g:i A') }}</td></tr>
            <tr><td class="muted">Method</td><td class="num">{{ $payment->method->label() }}</td></tr>
            @if ($payment->reference)<tr><td class="muted">Reference</td><td class="num">{{ $payment->reference }}</td></tr>@endif
            @if ($payment->bankAccount)<tr><td class="muted">{{ $refund ? 'Paid from' : 'Paid into' }}</td><td class="num">{{ $payment->bankAccount->bank_name }} · {{ $payment->bankAccount->account_number }}</td></tr>@endif
            <tr><td class="muted">Bill</td><td class="num">{{ $f->number }}{{ $r ? ' · ' . $r->reference : '' }}</td></tr>
            @if ($r)<tr><td class="muted">Stay</td><td class="num">{{ $r->check_in_date->format('j M') }} → {{ $r->check_out_date->format('j M Y') }}</td></tr>@endif
            @if ($payment->notes)<tr><td class="muted">Note</td><td class="num">{{ $payment->notes }}</td></tr>@endif
            <tr><td class="muted">{{ $refund ? 'Refunded by' : 'Received by' }}</td><td class="num">{{ $payment->receivedBy?->name ?? '—' }}</td></tr>
            <tr><td><strong>{{ $balance < -0.004 ? 'Credit on account' : 'Balance remaining' }}</strong></td><td class="num"><strong>@money(abs($balance))</strong></td></tr>
        </tbody>
    </table>
    <div class="note" style="text-align:center">Thank you.</div>
@endsection
