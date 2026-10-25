@extends('billing.print-layout')
@php
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => ((float) $v < 0 ? '−' : '') . $sym . number_format(abs((float) $v), 2);
    $r = $entry->reservation;
@endphp
@section('title', 'Invoice ' . $entry->number)
@section('heading', 'Invoice')
@section('back', route('companies.show', $company))

@section('body')
    @if ($entry->isVoided())<div class="note" style="background:#fee2e2;color:#b91c1c;margin:0 0 14px"><strong>VOID</strong> — {{ $entry->void_reason }}</div>@endif
    <div class="cols">
        <div><div class="lbl">Bill to</div><strong>{{ $company->name }}</strong>
            @if ($company->contact_name)<div>Attn: {{ $company->contact_name }}</div>@endif
            @if ($company->address)<div style="white-space:pre-line">{{ $company->address }}</div>@endif
            @if ($company->tax_number)<div class="muted">TIN {{ $company->tax_number }}</div>@endif</div>
        <div style="text-align:right"><div class="lbl">Invoice</div><span class="ref">{{ $entry->number }}</span>
            <div class="muted" style="margin-top:6px">Date {{ $entry->entry_date->format('j M Y') }}</div>
            @if ($entry->due_date)<div><strong>Due {{ $entry->due_date->format('j M Y') }}</strong></div>@endif
            <div class="muted">Account {{ $company->account_number }}</div></div>
    </div>

    @if ($r)
        <div class="note" style="margin-top:14px">Guest <strong>{{ $r->guest?->full_name }}</strong> · Booking {{ $r->reference }} · {{ $r->check_in_date->format('j M') }} – {{ $r->check_out_date->format('j M Y') }}{{ $r->rooms->pluck('room.number')->filter()->isNotEmpty() ? ' · Room ' . $r->rooms->pluck('room.number')->filter()->implode(', ') : '' }}</div>
    @endif

    @if ($charges->isNotEmpty())
        <table>
            <thead><tr><th>Date</th><th>Description</th><th class="num">Amount</th></tr></thead>
            <tbody>
                @foreach ($charges as $ch)<tr><td style="white-space:nowrap">{{ $ch->service_date->format('j M') }}</td><td>{{ $ch->description }}</td><td class="num">{{ $fmt($ch->total) }}</td></tr>@endforeach
            </tbody>
        </table>
        <div class="totals">
            <div><span>Bill total</span><span>{{ $fmt($charges->sum('total')) }}</span></div>
            @if (abs($otherPaid) >= 0.01)<div><span>Paid by the guest</span><span>{{ $fmt(-$otherPaid) }}</span></div>@endif
            <div class="grand"><span>Billed to {{ $company->name }}</span><span>{{ $fmt($entry->amount) }}</span></div>
        </div>
    @else
        <table><thead><tr><th>Description</th><th class="num">Amount</th></tr></thead>
            <tbody><tr><td>{{ $entry->description }}</td><td class="num">{{ $fmt($entry->amount) }}</td></tr></tbody></table>
        <div class="totals"><div class="grand"><span>Amount due</span><span>{{ $fmt($entry->amount) }}</span></div></div>
    @endif
    @include('companies._bank', ['ref' => $entry->number])
@endsection
