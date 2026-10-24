@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Enums\ReservationStatus;
    use App\Enums\BookingSource;
    $tabMeta = [
        'arrivals'   => ['Arrivals today', 'ri-login-box-line'],
        'inhouse'    => ['In house', 'ri-hotel-bed-line'],
        'departures' => ['Departures today', 'ri-logout-box-r-line'],
        'upcoming'   => ['Upcoming', 'ri-calendar-event-line'],
        'all'        => ['All bookings', 'ri-list-check'],
    ];
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Front Desk" icon="ri-calendar-check-line"
               :subtitle="'Arrivals, departures and guests in house for ' . now()->format('l j F Y') . '.'">
        <x-slot:pills>
            <span class="cb-meta-pill"><i class="ri-login-box-line"></i> {{ $counts['arrivals'] }} arriving</span>
            <span class="cb-meta-pill"><i class="ri-hotel-bed-line"></i> {{ $counts['inhouse'] }} in house</span>
            <span class="cb-meta-pill"><i class="ri-logout-box-r-line"></i> {{ $counts['departures'] }} leaving</span>
        </x-slot:pills>
        <x-slot:actions>
            @can('Create reservations')
                <a class="cb-hero-btn" href="{{ route('reservations.create') }}"><i class="ri-add-line"></i> New reservation</a>
            @endcan
            <a class="cb-hero-btn" href="{{ route('reservations.scan') }}"><i class="ri-qr-scan-2-line"></i> Scan / find</a>
            <a class="cb-hero-btn" href="{{ route('reservations.calendar') }}"><i class="ri-calendar-2-line"></i> Calendar</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    @if ($overdue > 0)
        <div class="cb-banner warning">
            <i class="ri-alarm-warning-line"></i>
            <div><strong>{{ $overdue }} booking(s)</strong> were due to arrive before today and haven't checked in.
                Check them in, change their dates, or mark them as no-show.
                <a href="{{ route('reservations.index', ['tab' => 'all', 'to' => today()->subDay()->toDateString(), 'status' => 'confirmed']) }}" class="fw-bold">Review</a></div>
        </div>
    @endif

    <div class="rs-tabs" role="tablist">
        @foreach ($tabMeta as $key => [$label, $icon])
            <a href="{{ route('reservations.index', ['tab' => $key]) }}" class="rs-tab {{ $tab === $key ? 'active' : '' }}" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                <i class="{{ $icon }}"></i> {{ $label }} @isset($counts[$key])<span class="n">{{ $counts[$key] }}</span>@endisset
            </a>
        @endforeach
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="cb-search">
                <i class="ri-search-line"></i>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, guest name, phone…" aria-label="Search">
            </div>
            @if ($tab === 'all')
                <select name="status" class="cb-select" aria-label="Status" onchange="this.form.submit()">
                    <option value="">Any status</option>
                    @foreach (ReservationStatus::options() as $v => $l)<option value="{{ $v }}" @selected(($filters['status'] ?? '') === $v)>{{ $l }}</option>@endforeach
                </select>
                <input type="date" name="from" class="cb-select" value="{{ $filters['from'] ?? '' }}" aria-label="Staying after" title="Staying after">
                <input type="date" name="to" class="cb-select" value="{{ $filters['to'] ?? '' }}" aria-label="Arriving by" title="Arriving by">
            @endif
            <select name="source" class="cb-select" aria-label="Source" onchange="this.form.submit()">
                <option value="">Any source</option>
                @foreach (BookingSource::options() as $v => $l)<option value="{{ $v }}" @selected(($filters['source'] ?? '') === $v)>{{ $l }}</option>@endforeach
            </select>
            <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Filter</button>
            @if (array_filter($filters))
                <a href="{{ route('reservations.index', ['tab' => $tab]) }}" class="action-btn btn-more"><i class="ri-close-line"></i> Clear</a>
            @endif
        </form>

        @if ($reservations->isEmpty())
            <div class="empty-state">
                <i class="{{ $tabMeta[$tab][1] }}"></i>
                <h6>{{ match ($tab) { 'arrivals' => 'No arrivals due today', 'inhouse' => 'No guests in house', 'departures' => 'No departures due today', 'upcoming' => 'No upcoming bookings', default => 'No reservations found' } }}</h6>
                @can('Create reservations')
                    <a href="{{ route('reservations.create') }}" class="action-btn btn-primary-cb mt-3"><i class="ri-add-line"></i> New reservation</a>
                @endcan
            </div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead>
                        <tr><th>Guest</th><th>Reference</th><th>Stay</th><th>Rooms</th><th>Status</th><th class="num">Total</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($reservations as $r)
                            <tr>
                                <td data-label="Guest">
                                    <div class="rs-guest">{{ $r->guest->full_name }} @if ($r->guest->is_vip)<span class="status-pill st-violet ms-1">VIP</span>@endif</div>
                                    <div class="small text-muted">{{ $r->guest->phone }}</div>
                                </td>
                                <td data-label="Reference"><a href="{{ route('reservations.show', $r) }}" class="rs-ref text-decoration-none">{{ $r->reference }}</a>
                                    <div class="small text-muted mt-1">{{ $r->source->label() }}</div></td>
                                <td data-label="Stay">
                                    <div class="fw-semibold">{{ $r->check_in_date->format('D j M') }} → {{ $r->check_out_date->format('D j M') }}</div>
                                    <div class="small text-muted">{{ $r->nights }} night{{ $r->nights === 1 ? '' : 's' }} · {{ $r->adults }} adult{{ $r->adults === 1 ? '' : 's' }}{{ $r->children ? ', ' . $r->children . ' child' . ($r->children === 1 ? '' : 'ren') : '' }}</div>
                                </td>
                                <td data-label="Rooms">@include('reservations.partials.rooms-cell', ['reservation' => $r])</td>
                                <td data-label="Status">@include('reservations.partials.status-pill', ['status' => $r->status])</td>
                                <td data-label="Total" class="num fw-semibold">@money($r->total_amount)</td>
                                <td class="text-end text-nowrap">
                                    @if ($r->isArrivalDue() && $r->check_out_date->gt(today()))
                                        @can('Check in guests')
                                            @if ($r->allRoomsAssigned())
                                                <form method="POST" action="{{ route('reservations.check-in', $r) }}" class="d-inline">@csrf
                                                    <button class="action-btn btn-go" type="submit"><i class="ri-login-box-line"></i> Check in</button></form>
                                            @else
                                                <a href="{{ route('reservations.show', $r) }}#rooms" class="action-btn btn-more"><i class="ri-door-line"></i> Assign room</a>
                                            @endif
                                        @endcan
                                    @elseif ($r->status === ReservationStatus::CheckedIn)
                                        @can('Check out guests')
                                            <form method="POST" action="{{ route('reservations.check-out', $r) }}" class="d-inline" onsubmit="return confirm('Check out {{ $r->guest->full_name }}?');">@csrf
                                                <button class="action-btn btn-open" type="submit"><i class="ri-logout-box-r-line"></i> Check out</button></form>
                                        @endcan
                                    @endif
                                    <a href="{{ route('reservations.show', $r) }}" class="action-btn btn-more" title="Open"><i class="ri-arrow-right-line"></i></a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3">{{ $reservations->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
