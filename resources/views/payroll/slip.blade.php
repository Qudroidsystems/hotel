@php $sym = config('hotel.currency_symbol'); $m = fn ($v) => $sym . number_format((float) $v, 2); @endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payslips · {{ $run->month_label }}</title>
<style>
    body { margin: 0; background: #eef2f4; font: 14px/1.45 Arial, Helvetica, sans-serif; color: #14213d; }
    .slip { background: #fff; max-width: 720px; margin: 20px auto; padding: 28px 32px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,.06); page-break-after: always; }
    .slip:last-child { page-break-after: auto; }
    header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #14213d; padding-bottom: 12px; margin-bottom: 16px; }
    header h1 { font: 700 22px Georgia, serif; margin: 0; }
    .who { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; margin-bottom: 16px; font-size: 13.5px; }
    .who span { color: #66748a; }
    .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 12px; color: #66748a; border-bottom: 1px solid #d6e0dc; padding: 6px 0; }
    td { padding: 6px 0; border-bottom: 1px dashed #e2e8f0; }
    td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; }
    tr.t td { font-weight: 700; border-bottom: 0; border-top: 1px solid #14213d; }
    .net { margin-top: 18px; background: #14213d; color: #fff; border-radius: 10px; padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; }
    .net b { font-size: 22px; }
    .small { font-size: 12px; color: #66748a; margin-top: 12px; }
    .bar { max-width: 720px; margin: 16px auto 0; text-align: right; }
    @media print { body { background: #fff; } .slip { box-shadow: none; margin: 0 auto; border-radius: 0; } .bar { display: none; } }
    @media (max-width: 600px) { .cols, .who { grid-template-columns: 1fr; } .slip { padding: 20px; margin: 10px; } }
</style>
</head>
<body>
<div class="bar"><button onclick="window.print()">Print / save as PDF</button></div>
@foreach ($slips as $s)
    <section class="slip">
        <header>
            <div><h1>{{ config('hotel.name') }}</h1><div class="small" style="margin:2px 0 0">Payslip · {{ $run->month_label }}</div></div>
            <div style="text-align:right"><strong>{{ $s->name }}</strong><div class="small" style="margin:2px 0 0">{{ $s->staff_no }}</div></div>
        </header>
        <div class="who">
            <div><span>Department</span> {{ $s->department ?? '—' }}</div>
            <div><span>Job</span> {{ $s->job_title ?? '—' }}</div>
            <div><span>Paid into</span> {{ $s->bank_name ?? '—' }}{{ $s->account_number ? ' •••• ' . substr($s->account_number, -4) : '' }}</div>
            <div><span>Days</span> @if ($s->attendance){{ $s->attendance['present'] }} worked of {{ $s->attendance['scheduled'] + $s->attendance['leave'] }}@else full month @endif</div>
        </div>
        <div class="cols">
            <table>
                <thead><tr><th>Earnings</th><th class="n">{{ $sym }}</th></tr></thead>
                <tbody>
                    @foreach ($s->earnings as $l)<tr><td>{{ $l['name'] }}</td><td class="n">{{ $l['amount'] < 0 ? '−' : '' }}{{ number_format(abs($l['amount']), 2) }}</td></tr>@endforeach
                    <tr class="t"><td>Gross pay</td><td class="n">{{ number_format((float) $s->gross, 2) }}</td></tr>
                </tbody>
            </table>
            <table>
                <thead><tr><th>Deductions</th><th class="n">{{ $sym }}</th></tr></thead>
                <tbody>
                    @forelse ($s->deductions as $l)<tr><td>{{ $l['name'] }}</td><td class="n">{{ number_format($l['amount'], 2) }}</td></tr>@empty<tr><td>None</td><td class="n">0.00</td></tr>@endforelse
                    <tr class="t"><td>Total deductions</td><td class="n">{{ number_format((float) $s->total_deductions, 2) }}</td></tr>
                </tbody>
            </table>
        </div>
        <div class="net"><span>Net pay</span><b>{{ $m($s->net) }}</b></div>
        <div class="small">Taxable pay this month {{ $m($s->taxable) }}@if ((float) $s->pension_employer > 0) · the hotel also paid {{ $m($s->pension_employer) }} into your pension{{ "" }}@endif. Questions about your pay? Ask HR.</div>
    </section>
@endforeach
</body>
</html>
