@extends('billing.print-layout')
@section('title', 'Cash-up')
@section('heading', 'Collections · cash-up')
@section('back', route('billing.payments', array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString()])))

@section('body')
    <div class="cols">
        <div><div class="lbl">Period</div><strong>{{ $from->equalTo($to) ? $from->format('l j F Y') : $from->format('j M') . ' – ' . $to->format('j M Y') }}</strong></div>
        <div><div class="lbl">Taken by</div><strong>{{ $everyone ? ($cashier ? $cashiers->firstWhere('id', $cashier)?->name : 'All staff') : auth()->user()->name }}</strong></div>
        @if ($method)<div><div class="lbl">Method</div><strong>{{ $method->label() }}</strong></div>@endif
    </div>
    <table>
        <thead><tr><th>Method</th><th class="num">Count</th><th class="num">Bill payments</th><th class="num">Refunded</th><th class="num">Bar &amp; rest.</th><th class="num">Net</th></tr></thead>
        <tbody>
            @forelse ($byMethod as $row)
                <tr><td>{{ $row['method']->label() }}</td><td class="num">{{ $row['count'] }}</td><td class="num">@money($row['in'])</td><td class="num">@money($row['out'])</td><td class="num">@money($row['pos'])</td><td class="num"><strong>@money($row['net'])</strong></td></tr>
            @empty
                <tr><td colspan="6" class="muted">Nothing collected.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="totals">
        <div><span>Guest bill payments</span><span>@money($totals['received'])</span></div>
        <div><span>Refunded</span><span>−@money($totals['refunded'])</span></div>
        <div><span>Bar &amp; restaurant ({{ $totals['pos_count'] }})</span><span>@money($totals['pos'])</span></div>
        <div class="grand"><span>Net collected</span><span>@money($totals['net'])</span></div>
    </div>
    @if ($byAccount->isNotEmpty())
        <table>
            <thead><tr><th>Into bank account</th><th class="num">Amount</th></tr></thead>
            <tbody>@foreach ($byAccount as $row)<tr><td>{{ $row['account']?->bank_name }} · {{ $row['account']?->account_number }}</td><td class="num">@money($row['amount'])</td></tr>@endforeach</tbody>
        </table>
    @endif
    <table>
        <thead><tr><th>Receipt</th><th>Time</th><th>Guest</th><th>Method</th><th class="num">Amount</th></tr></thead>
        <tbody>
            @foreach ($all as $p)
                <tr style="{{ $p->isVoided() ? 'text-decoration:line-through;color:#94a3b8' : '' }}">
                    <td>{{ $p->receipt_number }}</td><td class="muted">{{ $p->received_at->format('d/m H:i') }}</td>
                    <td>{{ $p->folio->guest->full_name }}</td><td>{{ $p->method->label() }}{{ $p->reference ? ' · ' . $p->reference : '' }}</td>
                    <td class="num">{{ $p->kind->value === 'refund' ? '−' : '' }}@money($p->amount){{ $p->isVoided() ? ' (void)' : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div style="display:flex;gap:40px;margin-top:40px;font-size:12.5px">
        <div style="flex:1;border-top:1px solid #94a3b8;padding-top:6px">Cashier signature</div>
        <div style="flex:1;border-top:1px solid #94a3b8;padding-top:6px">Checked by (accounts)</div>
    </div>
@endsection
