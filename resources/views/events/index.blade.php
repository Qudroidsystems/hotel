@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Events\Event;
    $sym = config('hotel.currency_symbol');
    $fmt = fn ($v) => $sym . number_format((float) $v, 2);
@endphp
<style>
    .ev-cal { border-collapse: separate; border-spacing: 0; font-size: 12px; table-layout: fixed; }
    .ev-cal th, .ev-cal td { border-bottom: 1px solid var(--cb-border); border-right: 1px solid var(--cb-border); vertical-align: top; padding: 4px; width: 112px; min-width: 112px; max-width: 112px; }
    .ev-cal th.hall, .ev-cal td.hall { position: sticky; left: 0; background: var(--cb-surface, #fff); width: 150px; min-width: 150px; max-width: 150px; z-index: 1; font-weight: 700; color: var(--cb-heading); }
    .ev-cal thead th { text-align: center; color: var(--cb-muted, #64748b); font-weight: 600; }
    .ev-cal td.today, .ev-cal th.today { background: rgba(15,118,110,.06); }
    .ev-chip { display: block; border-radius: 6px; padding: 3px 6px; margin-bottom: 3px; color: #fff; text-decoration: none; font-weight: 600; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ev-chip.enquiry { opacity: .55; border: 1px dashed rgba(255,255,255,.7); }
    .ev-chip.tentative { background-image: repeating-linear-gradient(135deg, rgba(255,255,255,.18) 0 6px, transparent 6px 12px); }
    .ev-chip small { display: block; font-weight: 400; opacity: .9; }
    .ev-add { display: block; text-align: center; color: var(--cb-muted, #94a3b8); text-decoration: none; opacity: 0; }
    .ev-cal td:hover .ev-add { opacity: 1; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Events & Halls" icon="ri-presentation-line" subtitle="Conferences, weddings, meetings and parties: from the first enquiry to the day, and the bill.">
        <x-slot:actions>
            @can('Manage events')<a class="cb-hero-btn" href="{{ route('events.create') }}"><i class="ri-add-line"></i> New event</a>@endcan
            @can('Manage halls')<a class="cb-hero-btn" href="{{ route('events.halls.index') }}"><i class="ri-building-4-line"></i> Halls &amp; packages</a>@endcan
            @canany(['Sell event tickets', 'View events'])<a class="cb-hero-btn" href="{{ route('events.tickets.index') }}"><i class="ri-coupon-3-line"></i> Tickets</a>@endcanany
            @canany(['Check event tickets', 'Sell event tickets'])<a class="cb-hero-btn" href="{{ route('events.tickets.door') }}"><i class="ri-qr-scan-2-line"></i> Check tickets</a>@endcanany
            <a class="cb-hero-btn" href="{{ route('events.usage') }}"><i class="ri-bar-chart-box-line"></i> Hall usage</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Booked, coming up" :value="$stats['upcoming']" icon="ri-calendar-event-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Enquiries to follow up" :value="$stats['enquiries']" icon="ri-question-answer-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Events this month" :value="$fmt($stats['month'])" icon="ri-money-dollar-circle-line" accent="green" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Waiting for a deposit" :value="$stats['deposits']" icon="ri-hand-coin-line" accent="rose" /></div>
    </div>

    <div class="rs-tabs">
        <a href="{{ route('events.index') }}" class="rs-tab {{ $view === 'list' ? 'active' : '' }}"><i class="ri-list-check"></i> Events</a>
        <a href="{{ route('events.index', ['view' => 'calendar']) }}" class="rs-tab {{ $view === 'calendar' ? 'active' : '' }}"><i class="ri-calendar-2-line"></i> Hall calendar</a>
    </div>

    @if ($halls->isEmpty())
        <div class="cb-banner warning"><i class="ri-building-4-line"></i><div>No halls are set up yet. @can('Manage halls')<a href="{{ route('events.halls.index') }}">Add your halls</a> with their capacities and rates first.@else Ask a manager to add the halls.@endcan</div></div>
    @endif

    @if ($view === 'calendar')
        <x-cb.card :flush="true">
            <x-slot:tools>
                <a class="btn btn-sm btn-light" href="{{ route('events.index', ['view' => 'calendar', 'month' => $month->copy()->subMonth()->format('Y-m')]) }}"><i class="ri-arrow-left-s-line"></i></a>
                <strong>{{ $month->format('F Y') }}</strong>
                <a class="btn btn-sm btn-light" href="{{ route('events.index', ['view' => 'calendar', 'month' => $month->copy()->addMonth()->format('Y-m')]) }}"><i class="ri-arrow-right-s-line"></i></a>
            </x-slot:tools>
            <div class="table-responsive">
                <table class="ev-cal">
                    <thead><tr><th class="hall">Hall</th>@foreach ($days as $d)<th class="{{ $d->isToday() ? 'today' : '' }}">{{ $d->format('D') }}<br>{{ $d->day }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($halls as $h)
                            <tr><td class="hall"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $h->color }}"></span> {{ $h->name }}</td>
                                @foreach ($days as $d)
                                    @php $todays = $events->filter(fn ($e) => $e->hall_id === $h->id && $e->starts_at->lt($d->copy()->addDay()) && $e->ends_at->gt($d)); @endphp
                                    <td class="{{ $d->isToday() ? 'today' : '' }}">
                                        @foreach ($todays as $e)
                                            <a class="ev-chip {{ $e->status }}" style="background: {{ $h->color }}" href="{{ route('events.show', $e) }}" title="{{ $e->title }} · {{ $e->status_label }} · {{ $e->when }}">{{ $e->title }}<small>{{ $e->starts_at->isSameDay($d) ? $e->starts_at->format('g:i A') : 'cont.' }} · {{ $e->guests_expected }}</small></a>
                                        @endforeach
                                        @can('Manage events')@if ($d->gte(today()))<a class="ev-add" href="{{ route('events.create', ['hall' => $h->id, 'date' => $d->toDateString()]) }}" title="New event here"><i class="ri-add-line"></i></a>@endif @endcan
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3 small text-muted">Solid: confirmed · Striped: provisional (holds the hall) · Faded: enquiry (doesn't hold the hall).</div>
        </x-cb.card>
    @else
        <div class="cb-card">
            <form method="GET" class="cb-toolbar">
                <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ request('q') }}" placeholder="Event, number or organiser…" aria-label="Search events"></div>
                <select name="show" class="cb-select" onchange="this.form.submit()" aria-label="Show">
                    @foreach (['upcoming' => 'Coming up', 'enquiries' => 'Enquiries', 'past' => 'Past', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected($show === $k)>{{ $l }}</option>@endforeach
                </select>
                <select name="hall" class="cb-select" onchange="this.form.submit()" aria-label="Hall"><option value="">All halls</option>@foreach ($halls as $h)<option value="{{ $h->id }}" @selected((string) request('hall') === (string) $h->id)>{{ $h->name }}</option>@endforeach</select>
                <button class="action-btn btn-open" type="submit"><i class="ri-search-line"></i> Search</button>
            </form>
            @if ($events->isEmpty())
                <div class="empty-state"><i class="ri-calendar-event-line"></i><h6>No events here</h6></div>
            @else
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>When</th><th>Event</th><th>Hall</th><th class="num">Guests</th><th class="num">Total</th><th class="num">Left to pay</th></tr></thead>
                        <tbody>
                            @foreach ($events as $e)
                                @php $left = $e->balance(); $depositShort = in_array($e->status, Event::HOLDING, true) && (float) $e->deposit_due > 0 && $e->paid() + 0.001 < (float) $e->deposit_due; @endphp
                                <tr>
                                    <td data-label="When" class="small text-nowrap">{{ $e->starts_at->format('D j M Y') }}<div class="text-muted">{{ $e->starts_at->format('g:i A') }} – {{ $e->ends_at->format($e->starts_at->isSameDay($e->ends_at) ? 'g:i A' : 'D j M') }}</div></td>
                                    <td data-label="Event"><a href="{{ route('events.show', $e) }}" class="fw-semibold">{{ $e->title }}</a> <span class="status-pill {{ $e->status_class }}">{{ $e->status_label }}</span>@if ($e->stage === 'in') <span class="status-pill st-success">In the hall</span>@endif @if ($e->is_ticketed) <span class="status-pill st-info"><i class="ri-coupon-3-line"></i> Tickets</span>@endif
                                        <div class="small text-muted">{{ $e->number }} · {{ $e->type_label }} · {{ $e->company?->name ?? $e->organiser_name }}</div></td>
                                    <td data-label="Hall" class="small">{{ $e->hall?->name }}</td>
                                    <td data-label="Guests" class="num">{{ $e->guests_expected }}</td>
                                    <td data-label="Total" class="num">{{ $fmt($e->total) }}</td>
                                    <td data-label="Left to pay" class="num {{ $left > 0.004 ? 'fw-semibold' : 'text-muted' }}">{{ $left > 0.004 ? $fmt($left) : 'Settled' }}@if ($depositShort)<div><span class="status-pill st-warning">Deposit due</span></div>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($events->hasPages())<div class="p-3">{{ $events->links() }}</div>@endif
            @endif
        </div>
    @endif

</div>
</div>
</div>
@endsection
