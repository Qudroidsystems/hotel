@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Guests" icon="ri-user-star-line"
               subtitle="Everyone who has booked or stayed. Guest profiles are created automatically when a reservation is made.">
        @can('Create reservations')
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('reservations.create') }}"><i class="ri-add-line"></i> New reservation</a>
        </x-slot:actions>
        @endcan
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <div class="cb-search"><i class="ri-search-line"></i>
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Name, phone, email or company…" aria-label="Search guests"></div>
            <label class="d-flex align-items-center gap-2 small fw-semibold text-muted m-0">
                <input type="checkbox" name="vip" value="1" class="form-check-input m-0" @checked(request()->boolean('vip')) onchange="this.form.submit()"> VIP only
            </label>
            <button class="action-btn btn-open" type="submit"><i class="ri-search-line"></i> Search</button>
        </form>

        @if ($guests->isEmpty())
            <div class="empty-state"><i class="ri-user-search-line"></i><h6>No guests found</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Guest</th><th>Contact</th><th>Company</th><th class="num">Bookings</th><th>Last arrival</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($guests as $g)
                            <tr>
                                <td data-label="Guest"><a href="{{ route('guests.show', $g) }}" class="rs-guest text-decoration-none">{{ $g->full_name }}</a>
                                    @if ($g->is_vip)<span class="status-pill st-violet ms-1">VIP</span>@endif</td>
                                <td data-label="Contact" class="small">{{ $g->phone }}<div class="text-muted">{{ $g->email }}</div></td>
                                <td data-label="Company" class="small">{{ $g->company ?: '—' }}</td>
                                <td data-label="Bookings" class="num">{{ $g->reservations_count }}</td>
                                <td data-label="Last arrival" class="small text-muted">{{ $g->reservations_max_check_in_date ? \Carbon\Carbon::parse($g->reservations_max_check_in_date)->format('j M Y') : '—' }}</td>
                                <td class="text-end"><a href="{{ route('guests.show', $g) }}" class="action-btn btn-more"><i class="ri-arrow-right-line"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3">{{ $guests->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
