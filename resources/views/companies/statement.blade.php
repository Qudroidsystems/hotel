@extends('billing.print-layout')
@php
    use App\Services\Companies\CompanyLedger;
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => ((float) $v < 0 ? '−' : '') . $sym . number_format(abs((float) $v), 2);
@endphp
@section('title', 'Statement ' . $company->account_number)
@section('heading', 'Statement of account')
@section('back', route('companies.show', $company))
@section('styles') .filter { max-width: 800px; margin: 0 auto 14px; display: flex; gap: 8px; align-items: center; justify-content: flex-end; font-size: 13px; } .filter input { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 8px; } @media print { .filter { display: none; } } @endsection

@section('body')
    <form method="GET" class="filter" style="margin:-8px 0 14px;justify-content:flex-start">
        From <input type="date" name="from" value="{{ $from->toDateString() }}"> to <input type="date" name="to" value="{{ $to->toDateString() }}">
        <button style="border:none;background:#0f2342;color:#fff;padding:7px 14px;border-radius:8px">Show</button>
    </form>
    <div class="cols">
        <div><div class="lbl">To</div><strong>{{ $company->name }}</strong>
            @if ($company->contact_name)<div>Attn: {{ $company->contact_name }}</div>@endif
            @if ($company->address)<div style="white-space:pre-line">{{ $company->address }}</div>@endif
            @if ($company->tax_number)<div class="muted">TIN {{ $company->tax_number }}</div>@endif</div>
        <div style="text-align:right"><div class="lbl">Account</div><span class="ref">{{ $company->account_number }}</span>
            <div class="muted" style="margin-top:6px">{{ $from->format('j M Y') }} – {{ $to->format('j M Y') }}</div>
            <div class="muted">Terms: {{ $company->payment_terms_days }} days</div></div>
    </div>

    <table>
        <thead><tr><th>Date</th><th>Ref</th><th>Details</th><th class="num">Charges</th><th class="num">Payments / credits</th><th class="num">Balance</th></tr></thead>
        <tbody>
            <tr><td colspan="5"><strong>Balance brought forward</strong></td><td class="num"><strong>{{ $fmt($statement['opening']) }}</strong></td></tr>
            @forelse ($statement['entries'] as $e)
                <tr><td style="white-space:nowrap">{{ $e->entry_date->format('j M Y') }}</td><td>{{ $e->number }}</td>
                    <td>{{ $e->description }}@if ($e->type === 'invoice' && $e->due_date)<div class="muted">Due {{ $e->due_date->format('j M Y') }}</div>@endif</td>
                    <td class="num">{{ (float) $e->amount > 0 ? $fmt($e->amount) : '' }}</td><td class="num">{{ (float) $e->amount < 0 ? $fmt(-$e->amount) : '' }}</td><td class="num">{{ $fmt($e->running) }}</td></tr>
            @empty
                <tr><td colspan="6" class="muted">Nothing in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="totals"><div class="grand"><span>{{ $statement['closing'] < 0 ? 'In credit' : 'Amount due' }}</span><span>{{ $fmt(abs($statement['closing'])) }}</span></div></div>

    <table style="margin-top:20px">
        <thead><tr>@foreach (CompanyLedger::BUCKETS as $label)<th class="num">{{ $label }}</th>@endforeach</tr></thead>
        <tbody><tr>@foreach (CompanyLedger::BUCKETS as $k => $label)<td class="num">{{ $fmt($ageing['buckets'][$k]) }}</td>@endforeach</tr></tbody>
    </table>
    @include('companies._bank', ['ref' => $company->account_number])
@endsection
