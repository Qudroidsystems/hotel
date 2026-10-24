@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php use App\Models\It\ItTicket; $base = array_filter(['priority' => $priority, 'category' => $category, 'q' => $q]); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-customer-service-2-line" :subtitle="$seeAll ? 'Every IT request from staff, with response and fix targets' : 'Problems with computers, Wi-Fi, tills, printers or the hotel system? Tell IT here and follow progress.'">
        <x-slot:actions><button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#ticketModal"><i class="ri-add-line"></i> Raise a ticket</button></x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($seeAll)@include('it.partials.nav')@endif

    @foreach ($maintenance as $w)
        <div class="cb-banner info"><i class="ri-tools-line"></i><div><strong>{{ $w->isRunning() ? 'Maintenance now' : 'Planned maintenance' }}: {{ $w->title }}</strong> — {{ $w->starts_at->format('D j M, g:i A') }} to {{ $w->ends_at->format('g:i A') }}.{{ $w->affects ? ' ' . $w->affects . '.' : '' }}</div></div>
    @endforeach

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat :label="$seeAll ? 'Open tickets' : 'My open requests'" :value="$stats['active']" icon="ri-customer-service-2-line" accent="teal" :hint="$stats['serious'] . ' high or critical'" /></div>
        @if ($seeAll)
            <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('it.tickets.index', ['show' => 'overdue']) }}"><x-cb.stat label="Overdue" :value="$stats['overdue']" icon="ri-timer-flash-line" accent="rose" hint="missed a response or fix target" /></a></div>
            <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('it.tickets.index', ['show' => 'unassigned']) }}"><x-cb.stat label="Unassigned" :value="$stats['unassigned']" icon="ri-user-unfollow-line" accent="amber" /></a></div>
            <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('it.tickets.index', ['show' => 'mine']) }}"><x-cb.stat label="Assigned to me" :value="$stats['mine']" icon="ri-user-follow-line" accent="green" /></a></div>
        @endif
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <select name="show" class="cb-select" aria-label="Show" onchange="this.form.submit()">
                @foreach (array_merge(['active' => 'Open'], $seeAll ? ['mine' => 'Assigned to me', 'unassigned' => 'Unassigned', 'overdue' => 'Overdue'] : [], ['resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All']) as $k => $label)<option value="{{ $k }}" @selected($show === $k)>{{ $label }}</option>@endforeach
            </select>
            <select name="priority" class="cb-select" aria-label="Priority" onchange="this.form.submit()"><option value="">Any priority</option>@foreach (ItTicket::PRIORITIES as $k => [$label])<option value="{{ $k }}" @selected($priority === $k)>{{ $label }}</option>@endforeach</select>
            <select name="category" class="cb-select" aria-label="Kind" onchange="this.form.submit()"><option value="">Any kind</option>@foreach (ItTicket::CATEGORIES as $k => [$label])<option value="{{ $k }}" @selected($category === $k)>{{ $label }}</option>@endforeach</select>
            <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $q }}" placeholder="Number, title, place…" aria-label="Search"></div>
        </form>
        @if ($tickets->isEmpty())
            <div class="empty-state py-5"><i class="ri-customer-service-2-line"></i><h6>No tickets here</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Ticket</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Targets</th></tr></thead>
                    <tbody>
                        @foreach ($tickets as $t)
                            <tr>
                                <td data-label="Ticket"><a href="{{ route('it.tickets.show', $t) }}" class="fw-semibold text-reset"><i class="{{ $t->category_icon }} text-muted"></i> {{ $t->title }}</a>
                                    <div class="small text-muted">{{ $t->number }} · {{ $t->category_label }}{{ $t->location ? ' · ' . $t->location : '' }} · {{ $t->requester?->name }} · {{ $t->created_at->diffForHumans() }}</div></td>
                                <td data-label="Priority"><span class="status-pill {{ $t->priority_pill }}">{{ $t->priority_label }}</span></td>
                                <td data-label="Status"><span class="status-pill {{ $t->status_pill }}">{{ $t->status_label }}</span>@if ($t->escalated_at && $t->isActive())<div class="small text-danger fw-semibold">escalated</div>@endif</td>
                                <td data-label="Assigned">{{ $t->assignee?->name ?? '—' }}</td>
                                <td data-label="Targets" class="small text-nowrap">
                                    @if ($t->isActive())
                                        @if (! $t->first_response_at)<div class="{{ $t->responseLate() ? 'text-danger fw-semibold' : '' }}">Respond {{ $t->response_due_at->diffForHumans() }}</div>@else<div class="text-muted"><i class="ri-check-line"></i> Responded</div>@endif
                                        <div class="{{ $t->resolveLate() ? 'text-danger fw-semibold' : 'text-muted' }}">Fix {{ $t->resolve_due_at->diffForHumans() }}</div>
                                    @else
                                        <span class="{{ $t->resolveLate() ? 'text-danger' : 'text-success' }}">{{ $t->resolveLate() ? 'Fixed late' : 'Fixed on time' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $tickets->links() }}</div>
        @endif
    </div>

</div>
</div>
</div>

<div class="modal fade" id="ticketModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content rm-form" method="POST" action="{{ route('it.tickets.store') }}" data-once>@csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-customer-service-2-line me-1"></i> Raise an IT ticket</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                @if ($errors->any())<div class="col-12"><div class="cb-banner danger mb-0"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div></div>@endif
                <div class="col-12"><label for="t_title">What's wrong? <span class="text-danger">*</span></label><input name="title" id="t_title" class="form-control" maxlength="150" required value="{{ old('title') }}" placeholder="e.g. Bar till won't print receipts"></div>
                <div class="col-sm-6"><label for="t_category">Kind of problem <span class="text-danger">*</span></label><select name="category" id="t_category" class="form-select" required>@foreach (ItTicket::CATEGORIES as $k => [$label])<option value="{{ $k }}" @selected(old('category') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label for="t_priority">How urgent? <span class="text-danger">*</span></label><select name="priority" id="t_priority" class="form-select" required>
                    @foreach (ItTicket::PRIORITIES as $k => [$label])<option value="{{ $k }}" @selected(old('priority', 'normal') === $k) title="{{ ItTicket::PRIORITY_HELP[$k] }}">{{ $label }} — {{ ItTicket::PRIORITY_HELP[$k] }}</option>@endforeach</select></div>
                <div class="col-12"><label for="t_description">Details <span class="text-danger">*</span></label><textarea name="description" id="t_description" class="form-control" rows="4" maxlength="5000" required placeholder="What happened, any error message, what you already tried">{{ old('description') }}</textarea></div>
                <div class="col-sm-4"><label for="t_department">Department</label><input name="department" id="t_department" class="form-control" maxlength="80" value="{{ old('department', auth()->user()->getRoleNames()->first()) }}" list="itDepts"></div>
                <div class="col-sm-4"><label for="t_location">Where</label><input name="location" id="t_location" class="form-control" maxlength="120" value="{{ old('location') }}" placeholder="e.g. Main bar, Room 214"></div>
                <div class="col-sm-4"><label for="t_asset">Device (if known)</label><select name="asset_id" id="t_asset" class="form-select"><option value="">—</option>@foreach ($assets as $a)<option value="{{ $a->id }}" @selected(old('asset_id') == $a->id)>{{ $a->name }}{{ $a->location ? ' · ' . $a->location : '' }}</option>@endforeach</select></div>
            </div>
            <div class="modal-footer"><span class="small text-muted me-auto">Never put passwords in a ticket.</span><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Send to IT</button></div>
        </form>
    </div>
</div>
<datalist id="itDepts">@foreach (['Front Desk', 'Bar & Restaurant', 'Kitchen', 'Housekeeping', 'Maintenance', 'Security', 'Accounts', 'Store', 'HR', 'Management', 'IT'] as $d)<option value="{{ $d }}">@endforeach</datalist>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (location.hash === '#new' || {{ $errors->any() ? 'true' : 'false' }}) bootstrap.Modal.getOrCreateInstance(document.getElementById('ticketModal')).show();
});
</script>
@endsection
