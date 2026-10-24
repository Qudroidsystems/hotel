@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Booking\GuestRequest; $where = ['housekeeping' => 'Housekeeping', 'maintenance' => 'Maintenance', 'front_desk' => 'Front desk']; @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Guest Requests" icon="ri-service-line" subtitle="Sent by in-house guests from their booking page. Towels and cleaning go to housekeeping automatically; the rest is handled here.">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('book.index') }}" target="_blank" rel="noopener"><i class="ri-global-line"></i> Public booking page</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Waiting" :value="$stats['open']" icon="ri-notification-3-line" accent="rose" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Being handled" :value="$stats['working']" icon="ri-loader-4-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Sent today" :value="$stats['today']" icon="ri-calendar-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Average time to answer" :value="$stats['minutes'] !== null ? $stats['minutes'] . ' min' : '—'" icon="ri-timer-line" accent="green" hint="today" /></div>
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="show" class="cb-select" onchange="this.form.submit()" aria-label="Show">
                @foreach (['active' => 'Waiting & being handled', 'done' => 'Done & cancelled', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected($show === $k)>{{ $l }}</option>@endforeach
            </select>
        </form>
        @if ($requests->isEmpty())
            <div class="empty-state py-5"><i class="ri-service-line"></i><h6>No requests here</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Request</th><th>Guest</th><th>Goes to</th><th>Status</th><th style="min-width:300px">Answer</th></tr></thead>
                    <tbody>
                        @foreach ($requests as $q)
                            <tr>
                                <td data-label="Request"><span class="fw-semibold"><i class="{{ $q->type_icon }} text-muted"></i> {{ $q->type_label }}</span>
                                    @if ($q->details)<div>{{ $q->details }}</div>@endif
                                    <div class="small text-muted">{{ $q->created_at->format('D j M, g:i A') }} ({{ $q->created_at->diffForHumans() }})</div></td>
                                <td data-label="Guest"><strong>Room {{ $q->room?->number ?? '—' }}</strong>
                                    <div class="small">@can('View reservations')<a href="{{ route('reservations.show', $q->reservation_id) }}">{{ $q->reservation?->guest?->full_name }}</a>@else{{ $q->reservation?->guest?->full_name }}@endcan
                                        @if ($q->reservation?->guest?->is_vip)<span class="status-pill st-warning">VIP</span>@endif</div></td>
                                <td data-label="Goes to" class="small">{{ $where[$q->route] ?? '—' }}
                                    @if ($q->housekeepingTask)<div class="text-muted">Task: {{ $q->housekeepingTask->status_label }}</div>@endif
                                    @if ($q->maintenanceOrder)<div><a href="{{ route('maintenance.show', $q->maintenanceOrder) }}">{{ $q->maintenanceOrder->number }}</a> · {{ $q->maintenanceOrder->status_label }}</div>@endif</td>
                                <td data-label="Status"><span class="status-pill {{ $q->status_pill }}">{{ $q->status_label }}</span>
                                    @if ($q->handler)<div class="small text-muted">{{ $q->handler->name }}</div>@endif</td>
                                <td data-label="Answer">
                                    @if (in_array($q->status, ['open', 'in_progress'], true))
                                        <form method="POST" action="{{ route('guest-requests.update', $q) }}" class="d-flex gap-2 flex-wrap" data-once>@csrf
                                            <input name="staff_note" class="form-control form-control-sm" style="max-width:220px" maxlength="500" value="{{ $q->staff_note }}" placeholder="Message to the guest (optional)">
                                            @if ($q->status === 'open')<button name="status" value="in_progress" class="action-btn btn-open">On it</button>@endif
                                            <button name="status" value="done" class="action-btn btn-go">Done</button>
                                        </form>
                                        @if ($q->route === 'maintenance' && ! $q->maintenance_order_id)
                                            @canany(['Report maintenance issues', 'Manage maintenance'])
                                                <form method="POST" action="{{ route('guest-requests.maintenance', $q) }}" class="mt-1" data-once>@csrf<button class="action-btn btn-more"><i class="ri-tools-line"></i> Send to maintenance</button></form>
                                            @endcanany
                                        @endif
                                    @else
                                        <span class="small text-muted">{{ $q->staff_note ?: '—' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $requests->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>
@endsection
