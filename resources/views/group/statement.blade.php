@extends('billing.print-layout')
@php
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => $sym . number_format((float) $v, 2);
    $due = $stays->sum('commission_amount');
    $paidSum = $paid->sum('amount');
    $group = config('hotel.group_name') ?: config('app.name');
@endphp
@section('title', 'Commission ' . $hotel->name . ' ' . $month->format('M Y'))
@section('heading', 'Commission statement')
@section('back', route('group.commission', ['month' => $month->format('Y-m')]))

@section('body')
    <div class="cols">
        <div><div class="lbl">To</div><strong>{{ $hotel->name }}</strong>
            @if ($hotel->address)<div>{{ $hotel->address }}</div>@endif
            @if ($hotel->location)<div class="muted">{{ $hotel->location }}</div>@endif</div>
        <div style="text-align:right"><div class="lbl">From</div><strong>{{ $group }}</strong>
            <div class="muted" style="margin-top:6px">Stays ended in {{ $month->format('F Y') }}</div></div>
    </div>

    <table>
        <thead><tr><th>Booking</th><th>Guest</th><th>Stay</th><th class="num">Booking total</th><th class="num">Rate</th><th class="num">Commission</th></tr></thead>
        <tbody>
            @forelse ($stays as $r)
                <tr>
                    <td>{{ $r->reference }}</td>
                    <td>{{ $r->guest?->full_name }}</td>
                    <td>{{ $r->check_in_date->format('j M') }} – {{ $r->check_out_date->format('j M Y') }}</td>
                    <td class="num">{{ $fmt($r->total_amount) }}</td>
                    <td class="num">{{ rtrim(rtrim((string) $r->commission_percent, '0'), '.') }}%</td>
                    <td class="num">{{ $fmt($r->commission_amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No stays booked through {{ $group }} ended this month.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr><td colspan="5"><strong>Commission for {{ $month->format('F Y') }}</strong></td><td class="num"><strong>{{ $fmt($due) }}</strong></td></tr>
            @foreach ($paid as $p)
                <tr><td colspan="5">Paid {{ $p->received_on->format('j M Y') }}{{ $p->reference ? ' · ' . $p->reference : '' }}</td><td class="num">−{{ $fmt($p->amount) }}</td></tr>
            @endforeach
            <tr><td colspan="5"><strong>Still to pay</strong></td><td class="num"><strong>{{ $fmt(max(0, $due - $paidSum)) }}</strong></td></tr>
        </tfoot>
    </table>
    <p class="muted" style="margin-top:14px">Commission is charged on stays booked through {{ $group }}'s booking site or app, once the guest has checked out. Cancelled bookings and no-shows carry none.</p>
@endsection
