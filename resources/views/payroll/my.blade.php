@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid" style="max-width:900px">
    <x-cb.hero :title="$pagetitle" icon="ri-file-user-line" subtitle="Your pay for each month, once the payroll is approved." />
    <x-cb.card title="Payslips" icon="ri-file-list-3-line" :flush="true">
        <table class="cb-table stack">
            <thead><tr><th>Month</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net pay</th><th></th></tr></thead>
            <tbody>
                @forelse ($slips as $s)
                    <tr>
                        <td data-label="Month"><strong>{{ $s->run->month_label }}</strong> @if ($s->run->status === 'paid')<span class="status-pill st-success">Paid</span>@endif</td>
                        <td data-label="Gross" class="num">{{ config('hotel.currency_symbol') }}{{ number_format((float) $s->gross, 2) }}</td>
                        <td data-label="Deductions" class="num">{{ config('hotel.currency_symbol') }}{{ number_format((float) $s->total_deductions, 2) }}</td>
                        <td data-label="Net pay" class="num fw-semibold">{{ config('hotel.currency_symbol') }}{{ number_format((float) $s->net, 2) }}</td>
                        <td><a href="{{ route('payroll.my.show', $s) }}" target="_blank" rel="noopener">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="small text-muted">No payslips yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-cb.card>
</div>
</div>
</div>
@endsection
