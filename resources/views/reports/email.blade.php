@php
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => $sym . number_format((float) $v, 2);
    $k = $r['kpi']; $pk = $prev['kpi'];
    $chg = function ($now, $before) {
        if (! $before) return '';
        $d = ($now - $before) / abs($before) * 100;
        return '<span style="color:' . ($d >= 0 ? '#146c43' : '#b42318') . ';font-size:12px"> ' . ($d >= 0 ? '▲' : '▼') . ' ' . number_format(abs($d), 0) . '%</span>';
    };
    $td = 'padding:7px 0;border-bottom:1px solid #eef2f1';
    $title = $kind === 'month' ? $from->format('F Y') : $from->format('j M') . ' – ' . $to->format('j M Y');
@endphp
<div style="font-family: Segoe UI, Arial, sans-serif; color: #14213d; max-width: 600px">
    <h2 style="font-family: Georgia, serif; margin: 0 0 4px">{{ config('hotel.name') }}</h2>
    <p style="margin: 0 0 16px; color: #5b6676">{{ $kind === 'month' ? 'Monthly' : 'Weekly' }} report · {{ $title }} <span style="font-size:12px">(changes are against the {{ $kind === 'month' ? 'month' : 'week' }} before)</span></p>

    <h3 style="font-size: 15px; margin: 18px 0 6px">Rooms and revenue</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 14px">
        <tr><td style="{{ $td }}">Occupancy</td><td style="{{ $td }};text-align:right"><strong>{{ $k['occupancy'] }}%</strong> ({{ $k['sold'] }} of {{ $k['available'] }} room nights){!! $chg($k['occupancy'], $pk['occupancy']) !!}</td></tr>
        <tr><td style="{{ $td }}">Average room rate</td><td style="{{ $td }};text-align:right">{{ $fmt($k['adr']) }}{!! $chg($k['adr'], $pk['adr']) !!}</td></tr>
        <tr><td style="{{ $td }}">Revenue per available room</td><td style="{{ $td }};text-align:right">{{ $fmt($k['revpar']) }}{!! $chg($k['revpar'], $pk['revpar']) !!}</td></tr>
        <tr><td style="{{ $td }}">Room revenue</td><td style="{{ $td }};text-align:right">{{ $fmt($k['room_revenue']) }}{!! $chg($k['room_revenue'], $pk['room_revenue']) !!}</td></tr>
        <tr><td style="{{ $td }}">Bar &amp; restaurant</td><td style="{{ $td }};text-align:right">{{ $fmt($k['pos']) }}{!! $chg($k['pos'], $pk['pos']) !!}</td></tr>
        <tr><td style="{{ $td }}"><strong>Total revenue</strong></td><td style="{{ $td }};text-align:right"><strong>{{ $fmt($k['revenue']) }}</strong>{!! $chg($k['revenue'], $pk['revenue']) !!}</td></tr>
        <tr><td style="{{ $td }}">Money collected</td><td style="{{ $td }};text-align:right">{{ $fmt($r['money']['collected']) }}</td></tr>
        <tr><td style="{{ $td }}">Owed on open bills now</td><td style="{{ $td }};text-align:right">{{ $fmt($r['money']['owed_now']) }}</td></tr>
    </table>
    @if (count($r['departments']) || count($r['outlets']))
        <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; color: #3c4a66">
            @foreach (collect($r['departments'])->concat($r['outlets'])->sortByDesc('amount')->take(6) as $d)
                <tr><td style="padding:4px 0">{{ $d['label'] }} <span style="color:#94a3b8">· {{ $d['source'] }}</span></td><td style="text-align:right">{{ $fmt($d['amount']) }}</td></tr>
            @endforeach
        </table>
    @endif

    <h3 style="font-size: 15px; margin: 18px 0 6px">Bookings</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 14px">
        <tr><td style="{{ $td }}">Arrivals booked / stayed</td><td style="{{ $td }};text-align:right">{{ $r['bookings']['arrivals'] }} / {{ $r['bookings']['stayed'] }}</td></tr>
        <tr><td style="{{ $td }}">Cancelled / no-shows</td><td style="{{ $td }};text-align:right">{{ $r['bookings']['cancelled'] }} / {{ $r['bookings']['no_show'] }}</td></tr>
        <tr><td style="{{ $td }}">Average stay</td><td style="{{ $td }};text-align:right">{{ $r['bookings']['avg_nights'] }} nights</td></tr>
        @foreach (collect($r['bookings']['by_source'])->take(4) as $s)
            <tr><td style="padding:4px 0;font-size:13px;color:#3c4a66">· {{ $s['label'] }}</td><td style="text-align:right;font-size:13px;color:#3c4a66">{{ $s['count'] }}</td></tr>
        @endforeach
    </table>

    <h3 style="font-size: 15px; margin: 18px 0 6px">Guest feedback</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 14px">
        <tr><td style="{{ $td }}">Average score</td><td style="{{ $td }};text-align:right"><strong>{{ $feedback['average'] !== null ? $feedback['average'] . ' / 5' : '—' }}</strong> from {{ $feedback['count'] }} guest(s){{ $feedback['response'] !== null ? ' (' . $feedback['response'] . '% of check-outs)' : '' }}</td></tr>
        <tr><td style="{{ $td }}">Net promoter score</td><td style="{{ $td }};text-align:right">{{ $feedback['nps'] ?? '—' }}</td></tr>
        <tr><td style="{{ $td }}">Low scores / still to follow up</td><td style="{{ $td }};text-align:right">{{ $feedback['low'] }} / <span style="color:{{ $feedback['open'] ? '#b42318' : 'inherit' }}">{{ $feedback['open'] }}</span></td></tr>
    </table>

    <h3 style="font-size: 15px; margin: 18px 0 6px">Repairs</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 14px">
        <tr><td style="{{ $td }}">Jobs fixed</td><td style="{{ $td }};text-align:right">{{ $repairs['fixed'] }} ({{ $fmt($repairs['cost']) }})</td></tr>
        <tr><td style="{{ $td }}">Open now / overdue</td><td style="{{ $td }};text-align:right">{{ $repairs['open'] }} / <span style="color:{{ $repairs['overdue'] ? '#b42318' : 'inherit' }}">{{ $repairs['overdue'] }}</span></td></tr>
        <tr><td style="{{ $td }}">Servicing plans overdue</td><td style="{{ $td }};text-align:right">{{ $repairs['plans_overdue'] }}</td></tr>
    </table>

    @if (! empty($energy))
    <h3 style="font-size: 15px; margin: 18px 0 6px">Power &amp; diesel</h3>
    <table style="width: 100%; border-collapse: collapse; font-size: 14px">
        @if ($energy['grid_availability'] !== null)<tr><td style="{{ $td }}">Grid power available</td><td style="{{ $td }};text-align:right">{{ $energy['grid_availability'] }}% ({{ $energy['outages'] }} outage(s), {{ $energy['grid_off_hours'] }} h off)</td></tr>@endif
        <tr><td style="{{ $td }}">Generator hours</td><td style="{{ $td }};text-align:right">{{ $energy['generator_hours'] }} h · about {{ number_format($energy['expected_litres']) }} L burned</td></tr>
        <tr><td style="{{ $td }}">Diesel delivered</td><td style="{{ $td }};text-align:right">{{ number_format($energy['delivered_litres']) }} L ({{ $fmt($energy['delivered_cost']) }})</td></tr>
        <tr><td style="{{ $td }}">Energy per occupied room-night</td><td style="{{ $td }};text-align:right">{{ $energy['per_room_night'] !== null ? $fmt($energy['per_room_night']) : '—' }}</td></tr>
        <tr><td style="{{ $td }}">Saved by switching off</td><td style="{{ $td }};text-align:right">{{ number_format($energy['saved_kwh'], 1) }} kWh (about {{ $fmt($energy['saved_value']) }} of diesel)</td></tr>
        @foreach ($energy['flags'] as $flag)<tr><td colspan="2" style="{{ $td }};color:#b42318">Diesel check — {{ $flag }}</td></tr>@endforeach
    </table>
    @endif

    <p style="margin-top: 18px"><a href="{{ route('reports.index', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" style="color: #0f766e">Open the full report</a></p>
    <p style="color: #94a3b8; font-size: 12px">Sent because this address is under Hotel Settings → Report E-mails. Powered by Qlouds · Developed by Qudroid Systems</p>
</div>
