@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => $sym . number_format((float) $v, 2);
    $t = $report['totals'];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Hall Usage" icon="ri-bar-chart-box-line" :back="route('events.index')" back-label="Events & Halls"
               :subtitle="$report['from']->format('j M Y') . ' – ' . $report['to']->format('j M Y') . ' · how much each hall was booked and used, who came, and what it earned'" />

    @include('rooms.partials.flash')

    @if ($today->isNotEmpty())
        <x-cb.card title="Today in the halls" icon="ri-door-open-line" :flush="true">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <tbody>
                        @foreach ($today as $e)
                            <tr>
                                <td data-label="Event"><a href="{{ route('events.show', $e) }}" class="fw-semibold">{{ $e->title }}</a><div class="small text-muted">{{ $e->hall?->name }} · {{ $e->when }}</div></td>
                                <td data-label="Now" class="small">
                                    @if ($e->stage === 'booked')<span class="status-pill st-muted">Not handed over</span>
                                    @elseif ($e->stage === 'in')<span class="status-pill st-success">In the hall since {{ $e->checked_in_at->format('g:i A') }}</span>@if ($e->ends_at->isPast()) <span class="status-pill st-warning">Over time</span>@endif
                                    @else<span class="status-pill st-info">Given back {{ $e->checked_out_at->format('g:i A') }}</span>@endif
                                </td>
                                <td data-label="Guests" class="num small">{{ $e->guests_actual ?? $e->guests_expected }} {{ $e->guests_actual !== null ? 'came' : 'expected' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-cb.card>
    @endif

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <label class="small">From <input type="date" name="from" value="{{ $report['from']->toDateString() }}" class="cb-select"></label>
            <label class="small">To <input type="date" name="to" value="{{ $report['to']->toDateString() }}" class="cb-select"></label>
            <button class="action-btn btn-open" type="submit"><i class="ri-refresh-line"></i> Show</button>
        </form>
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Hall</th><th class="num">Events</th><th class="num">Hours booked</th><th class="num">Hours used</th><th class="num">Booked</th><th class="num">Came / expected</th><th class="num">Overtime</th><th class="num">Damage</th><th class="num">Event bills</th><th class="num">Tickets</th></tr></thead>
                <tbody>
                    @forelse ($report['rows'] as $r)
                        <tr>
                            <td data-label="Hall"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $r['hall']->color }}"></span> <strong>{{ $r['hall']->name }}</strong></td>
                            <td data-label="Events" class="num">{{ $r['events'] }}</td>
                            <td data-label="Hours booked" class="num">{{ $r['booked_hours'] }}</td>
                            <td data-label="Hours used" class="num">{{ $r['used_hours'] }}</td>
                            <td data-label="Booked" class="num"><div class="d-flex align-items-center justify-content-end gap-2"><div style="width:70px;height:6px;background:var(--cb-border);border-radius:3px;overflow:hidden"><div style="width:{{ $r['utilisation'] }}%;height:100%;background:{{ $r['hall']->color }}"></div></div>{{ $r['utilisation'] }}%</div></td>
                            <td data-label="Came / expected" class="num">{{ $r['guests_actual'] }} / {{ $r['guests_expected'] }}</td>
                            <td data-label="Overtime" class="num">{{ $r['overtime'] > 0 ? $fmt($r['overtime']) : '—' }}</td>
                            <td data-label="Damage" class="num">{{ $r['damages'] > 0 ? $fmt($r['damages']) : '—' }}{{ $r['damaged'] ? ' (' . $r['damaged'] . '×)' : '' }}</td>
                            <td data-label="Event bills" class="num">{{ $fmt($r['revenue']) }}</td>
                            <td data-label="Tickets" class="num">{{ $r['tickets'] > 0 ? $fmt($r['tickets']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-muted">No halls yet.</td></tr>
                    @endforelse
                </tbody>
                @if ($report['rows']->isNotEmpty())
                    <tfoot><tr class="fw-bold"><td>All halls</td><td class="num">{{ (int) $t['events'] }}</td><td class="num">{{ $t['booked_hours'] }}</td><td class="num">{{ $t['used_hours'] }}</td><td></td><td class="num">{{ (int) $t['guests_actual'] }} / {{ (int) $t['guests_expected'] }}</td><td class="num">{{ $fmt($t['overtime']) }}</td><td class="num">{{ $fmt($t['damages']) }}</td><td class="num">{{ $fmt($t['revenue']) }}</td><td class="num">{{ $fmt($t['tickets']) }}</td></tr></tfoot>
                @endif
            </table>
        </div>
        <div class="p-3 small text-muted">Confirmed and done events. "Booked" is hours booked out of {{ $report['open_hours'] }} hours a day over {{ $report['days'] }} {{ Str::plural('day', $report['days']) }}. Hours used run from handing the hall over to taking it back. Came / expected counts only events where attendance was recorded.</div>
    </div>

</div>
</div>
</div>
@endsection
