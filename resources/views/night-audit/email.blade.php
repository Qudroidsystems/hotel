@php $sym = config('hotel.currency_symbol'); $fmt = fn ($v) => $sym . number_format((float) $v, 2); @endphp
<div style="font-family: Segoe UI, Arial, sans-serif; color: #14213d; max-width: 560px">
    <h2 style="font-family: Georgia, serif; margin: 0 0 4px">{{ config('hotel.name') }}</h2>
    <p style="margin: 0 0 16px; color: #5b6676">Daily report for {{ $audit->business_date->format('l j F Y') }}</p>
    <table style="width: 100%; border-collapse: collapse; font-size: 14px">
        <tr><td style="padding: 6px 0">Occupancy</td><td style="text-align: right"><strong>{{ $r['rooms']['occupancy'] }}%</strong> ({{ $r['rooms']['sold'] }} of {{ $r['rooms']['sellable'] }} rooms)</td></tr>
        <tr><td style="padding: 6px 0">Average room rate</td><td style="text-align: right">{{ $fmt($r['rooms']['adr']) }}</td></tr>
        <tr><td style="padding: 6px 0">Revenue per available room</td><td style="text-align: right">{{ $fmt($r['rooms']['revpar']) }}</td></tr>
        <tr><td style="padding: 6px 0">Room revenue</td><td style="text-align: right">{{ $fmt($r['revenue']['rooms']) }}</td></tr>
        <tr><td style="padding: 6px 0">Bar &amp; restaurant</td><td style="text-align: right">{{ $fmt($r['revenue']['pos']['sales']) }}</td></tr>
        <tr><td style="padding: 6px 0; border-top: 1px solid #dfe6e4"><strong>Total revenue</strong></td><td style="text-align: right; border-top: 1px solid #dfe6e4"><strong>{{ $fmt($r['revenue']['total']) }}</strong></td></tr>
        <tr><td style="padding: 6px 0">Money in</td><td style="text-align: right">{{ $fmt($r['collections']['total']) }}</td></tr>
        <tr><td style="padding: 6px 0">Arrived / left / no-shows</td><td style="text-align: right">{{ $r['guests']['arrivals'] }} / {{ $r['guests']['departures'] }} / {{ $r['guests']['no_shows'] }}</td></tr>
        <tr><td style="padding: 6px 0">Owed by guests who have left</td><td style="text-align: right">{{ $fmt($r['owed']['after_stay']) }}</td></tr>
    </table>
    @if ($audit->notes)<p style="margin-top: 16px; padding: 10px 12px; background: #f4f7f6; border-radius: 8px">{{ $audit->notes }}</p>@endif
    <p style="margin-top: 16px"><a href="{{ route('night-audit.show', $audit) }}" style="color: #0f766e">Open the full report</a></p>
    <p style="color: #94a3b8; font-size: 12px">Powered by Qlouds · Developed by Qudroid Systems</p>
</div>
