@extends('billing.print-layout')
@section('title', 'Shift ' . $shift->number)
@section('heading', 'Shift report · ' . $shift->number)
@section('width', '600px')
@section('back', route('cash-shifts.show', $shift))

@section('body')
    <div class="cols">
        <div><div class="lbl">Cashier</div><strong>{{ $shift->user->name }}</strong></div>
        <div><div class="lbl">Station</div><strong>{{ $shift->station }}</strong></div>
        <div><div class="lbl">Opened</div><strong>{{ $shift->opened_at->format('d/m/Y H:i') }}</strong></div>
        <div><div class="lbl">Closed</div><strong>{{ $shift->closed_at?->format('d/m/Y H:i') ?? 'Still open' }}</strong></div>
    </div>
    <table>
        <thead><tr><th>Method</th><th class="num">Count</th><th class="num">Refunds</th><th class="num">Net taken</th></tr></thead>
        <tbody>
            @forelse ($totals['methods'] as $row)
                <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['count'] }}</td><td class="num">@money($row['out'])</td><td class="num"><strong>@money($row['net'])</strong></td></tr>
            @empty
                <tr><td colspan="4" class="muted">Nothing taken.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="totals">
        <div><span>Opening float</span><span>@money($shift->opening_float)</span></div>
        <div><span>Cash taken</span><span>@money($totals['cash_sales'])</span></div>
        @if ($totals['paid_in'] > 0)<div><span>Paid in</span><span>@money($totals['paid_in'])</span></div>@endif
        @if ($totals['paid_out'] > 0)<div><span>Paid out</span><span>−@money($totals['paid_out'])</span></div>@endif
        @if ($totals['drops'] > 0)<div><span>Dropped to safe</span><span>−@money($totals['drops'])</span></div>@endif
        <div class="grand"><span>Expected cash</span><span>@money($totals['expected_cash'])</span></div>
        @if ($shift->counted_cash !== null)
            <div><span>Counted</span><span>@money($shift->counted_cash)</span></div>
            <div><strong>{{ $shift->varianceLabel() }}</strong><strong>@money(abs((float) $shift->variance))</strong></div>
        @endif
    </div>
    @if ($shift->movements->isNotEmpty())
        <table>
            <thead><tr><th>Paid in / out</th><th>Reason</th><th class="num">Amount</th></tr></thead>
            <tbody>@foreach ($shift->movements as $m)<tr><td>{{ $m->label() }} <span class="muted">{{ $m->created_at->format('H:i') }}</span></td><td>{{ $m->reason }}</td><td class="num">{{ $m->sign() < 0 ? '−' : '' }}@money($m->amount)</td></tr>@endforeach</tbody>
        </table>
    @endif
    @if ($totals['room_count'] > 0)<div class="note">{{ $totals['room_count'] }} bar sale(s) charged to guest rooms (@money($totals['room_charges'])) are on guest bills, not in the drawer.</div>@endif
    @if ($shift->close_note)<div class="note"><strong>Cashier's note:</strong> {{ $shift->close_note }}</div>@endif
    <table style="margin-top:36px">
        <tr><td style="border:none;width:50%">Cashier: ____________________</td><td style="border:none">Supervisor: ____________________</td></tr>
    </table>
    @if ($shift->reviewedBy)<div class="note">Signed off by {{ $shift->reviewedBy->name }}, {{ $shift->reviewed_at->format('d/m/Y H:i') }}.{{ $shift->review_note ? ' ' . $shift->review_note : '' }}</div>@endif
@endsection
