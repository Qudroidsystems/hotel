@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $manage = auth()->user()->can('Manage room calendars');
@endphp
<style>
    .ch-url { display: flex; gap: 6px; align-items: center; }
    .ch-url input { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; }
    .ch-ota { font-weight: 700; color: var(--cb-heading); }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Room Calendars" icon="ri-calendar-2-line" subtitle="For rooms you also list on Airbnb, Vrbo or similar sites: the site reads which nights are taken here, and its bookings come in as reservations.">
        <x-slot:actions>
            @if ($manage)
                <form method="POST" action="{{ route('channels.pull') }}" class="d-inline">@csrf<button class="cb-hero-btn"><i class="ri-download-cloud-2-line"></i> Read calendars now</button></form>
            @endif
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    @if ($status['last_error'])
        <div class="cb-banner warning"><i class="ri-error-warning-line"></i><div><strong>Last problem ({{ $status['last_error']->created_at->diffForHumans() }}):</strong> {{ $status['last_error']->message }}</div></div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><x-cb.stat label="Calendars" :value="$status['feeds']" icon="ri-calendar-2-line" accent="violet" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Bookings in this month" :value="$status['month_bookings']" icon="ri-calendar-check-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Need attention" :value="$status['attention']" icon="ri-alarm-warning-line" :accent="$status['attention'] ? 'rose' : 'sky'" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Last read" :value="$status['last_read'] ? $status['last_read']->created_at->diffForHumans(null, true) . ' ago' : '—'" icon="ri-time-line" accent="green" /></div>
    </div>

    <div class="rs-tabs">
        @if ($manage)<a href="{{ route('channels.index', ['tab' => 'calendars']) }}" class="rs-tab {{ $tab === 'calendars' ? 'active' : '' }}"><i class="ri-calendar-2-line"></i> Calendars</a>@endif
        <a href="{{ route('channels.index', ['tab' => 'bookings']) }}" class="rs-tab {{ $tab === 'bookings' ? 'active' : '' }}"><i class="ri-inbox-archive-line"></i> Bookings received</a>
        <a href="{{ route('channels.index', ['tab' => 'log']) }}" class="rs-tab {{ $tab === 'log' ? 'active' : '' }}"><i class="ri-history-line"></i> Sync log</a>
    </div>

    {{-- ── Bookings received ───────────────────────────────── --}}
    @if ($tab === 'bookings')
        <div class="cb-card">
            <form method="GET" class="cb-toolbar">
                <input type="hidden" name="tab" value="bookings">
                <select name="show" class="cb-select" onchange="this.form.submit()" aria-label="Show">
                    <option value="all" @selected($show === 'all')>Everything received</option>
                    <option value="attention" @selected($show === 'attention')>Needs attention</option>
                </select>
            </form>
            @if ($bookings->isEmpty())
                <div class="empty-state"><i class="ri-inbox-line"></i><h6>{{ $show === 'attention' ? 'Nothing needs attention' : 'No channel bookings yet' }}</h6><p>Bookings, changes and cancellations read from the calendars are listed here.</p></div>
            @else
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Received</th><th>Channel</th><th>Guest &amp; stay</th><th>Change</th><th>Here</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($bookings as $b)
                                @php $r = $b->reservation; @endphp
                                <tr>
                                    <td data-label="Received" class="small text-nowrap">{{ $b->received_at->format('D j M, g:i A') }}<div class="text-muted">{{ $b->received_at->diffForHumans() }}</div></td>
                                    <td data-label="Channel"><span class="ch-ota">{{ $b->ota_name ?? ucfirst($b->provider) }}</span>@if ($b->ota_code)<div class="small text-muted">{{ $b->ota_code }}</div>@endif
                                        @if ($b->provider === 'ical')<div class="small text-muted">Calendar{{ $b->feed?->room ? ' · room ' . $b->feed->room->number : '' }}</div>@endif</td>
                                    <td data-label="Guest & stay">
                                        @if ($r)
                                            <a href="{{ route('reservations.show', $r) }}" class="fw-semibold">{{ $r->guest?->full_name }}</a> <span class="small text-muted">{{ $r->reference }}</span>
                                            <div class="small text-muted">{{ $r->check_in_date->format('D j M') }} – {{ $r->check_out_date->format('D j M Y') }} · {{ $r->rooms->map(fn ($l) => $l->roomType?->name)->filter()->countBy()->map(fn ($n, $t) => ($n > 1 ? $n . ' × ' : '') . $t)->implode(', ') }}</div>
                                        @else
                                            @php $p = $b->payload ?? []; @endphp
                                            <span class="fw-semibold">{{ trim(data_get($p, 'customer.name') . ' ' . data_get($p, 'customer.surname')) ?: '—' }}</span>
                                            @if (data_get($p, 'arrival_date') || data_get($p, 'start'))<div class="small text-muted">{{ data_get($p, 'arrival_date', data_get($p, 'start')) }} – {{ data_get($p, 'departure_date', data_get($p, 'end')) }}</div>@endif
                                        @endif
                                    </td>
                                    <td data-label="Change" class="small">{{ ['new' => 'New booking', 'modified' => 'Changed', 'cancelled' => 'Cancelled'][$b->status] ?? ucfirst($b->status) }}</td>
                                    <td data-label="Here">
                                        <span class="status-pill {{ $b->result_class }}">{{ $b->result_label }}</span>
                                        @if ($b->overbooked)<span class="status-pill st-danger">Overbooked</span>@endif
                                        @if ($b->error)<div class="small text-danger mt-1" style="max-width: 380px">{{ $b->error }}</div>@endif
                                    </td>
                                    <td class="text-end">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($bookings->hasPages())<div class="p-3">{{ $bookings->links() }}</div>@endif
            @endif
        </div>
        <p class="small text-muted mt-2">Calendar bookings come in confirmed, without the guest's name or price: add them from the site's booking. An overbooking (the site sold a night that was already taken) is still booked in so nothing is lost: move a guest or contact the site.</p>
    @endif

    {{-- ── Calendars (iCal) ────────────────────────────────── --}}
    @if ($tab === 'calendars')
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Room calendars" icon="ri-calendar-2-line" :flush="true">
                    @if ($feeds->isEmpty())
                        <div class="empty-state"><i class="ri-calendar-2-line"></i><h6>No calendars yet</h6><p>Add one for each room you list on Airbnb, Vrbo or another site that syncs by iCal.</p></div>
                    @else
                        <div class="table-responsive">
                            <table class="cb-table stack">
                                <thead><tr><th>Room</th><th>Site</th><th>Our calendar (give this to the site)</th><th>Their calendar</th><th></th></tr></thead>
                                <tbody>
                                    @foreach ($feeds as $f)
                                        <tr style="{{ $f->is_active ? '' : 'opacity:.55' }}">
                                            <td data-label="Room" class="fw-semibold">{{ $f->room?->number }}<div class="small text-muted fw-normal">{{ $f->room?->roomType?->name }}</div></td>
                                            <td data-label="Site"><span class="ch-ota">{{ $f->name }}</span>@unless ($f->is_active) <span class="status-pill st-muted">Off</span>@endunless</td>
                                            <td data-label="Our calendar"><div class="ch-url"><input class="form-control form-control-sm" readonly value="{{ $f->export_url }}" onclick="this.select()"><button type="button" class="btn btn-sm btn-light" onclick="navigator.clipboard.writeText(this.previousElementSibling.value); this.innerHTML='<i class=\'ri-check-line\'></i>'" title="Copy"><i class="ri-file-copy-line"></i></button></div></td>
                                            <td data-label="Their calendar" class="small">
                                                @if ($f->import_url)
                                                    Read {{ $f->last_synced_at ? $f->last_synced_at->diffForHumans() : 'not yet' }}
                                                    @if ($f->last_error)<div class="text-danger">{{ $f->last_error }}</div>@endif
                                                @else
                                                    <span class="text-muted">Not added: their bookings won't come in</span>
                                                @endif
                                            </td>
                                            <td class="text-end text-nowrap">
                                                @if ($f->import_url)<form method="POST" action="{{ route('channels.ical.sync', $f) }}" class="d-inline">@csrf<button class="action-btn btn-more" title="Read now"><i class="ri-refresh-line"></i></button></form>@endif
                                                <button type="button" class="action-btn btn-more" title="Edit" data-bs-toggle="collapse" data-bs-target="#feed-{{ $f->id }}"><i class="ri-pencil-line"></i></button>
                                                <form method="POST" action="{{ route('channels.ical.destroy', $f) }}" class="d-inline" onsubmit="return confirm('Remove this calendar? Bookings it brought in are kept.')">@csrf @method('DELETE')<button class="action-btn btn-more" title="Remove"><i class="ri-delete-bin-6-line"></i></button></form>
                                            </td>
                                        </tr>
                                        <tr class="collapse" id="feed-{{ $f->id }}"><td colspan="5">
                                            <form method="POST" action="{{ route('channels.ical.update', $f) }}" class="row g-2 align-items-end">
                                                @csrf @method('PUT')
                                                <div class="col-md-2"><label class="form-label small">Room</label><select name="room_id" class="form-select form-select-sm">@foreach ($rooms as $room)<option value="{{ $room->id }}" @selected($room->id === $f->room_id)>{{ $room->number }}</option>@endforeach</select></div>
                                                <div class="col-md-2"><label class="form-label small">Site</label><input name="name" class="form-control form-control-sm" value="{{ $f->name }}" required maxlength="60"></div>
                                                <div class="col-md-5"><label class="form-label small">Their calendar address</label><input name="import_url" type="url" class="form-control form-control-sm" value="{{ $f->import_url }}" placeholder="https://www.airbnb.com/calendar/ical/….ics"></div>
                                                <div class="col-md-1"><div class="form-check"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="act-{{ $f->id }}" @checked($f->is_active)><label class="form-check-label small" for="act-{{ $f->id }}">On</label></div></div>
                                                <div class="col-md-2"><button class="action-btn btn-primary-cb w-100">Save</button></div>
                                            </form>
                                        </td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-cb.card>
            </div>
            <div class="col-xl-4">
                <x-cb.card title="Add a calendar" icon="ri-add-line">
                    <form method="POST" action="{{ route('channels.ical.store') }}">
                        @csrf
                        <div class="mb-2"><label class="form-label small">Room</label>
                            <select name="room_id" class="form-select" required>@foreach ($rooms as $room)<option value="{{ $room->id }}" @selected(old('room_id') == $room->id)>{{ $room->number }} · {{ $room->roomType?->name }}</option>@endforeach</select></div>
                        <div class="mb-2"><label class="form-label small">Site</label>
                            <input name="name" class="form-control" list="ch-sites" value="{{ old('name') }}" required maxlength="60" placeholder="Airbnb">
                            <datalist id="ch-sites"><option value="Airbnb"><option value="Vrbo"><option value="Booking.com"><option value="Expedia"><option value="Google Vacation Rentals"><option value="Hotels.ng"></datalist></div>
                        <div class="mb-3"><label class="form-label small">Their calendar address <span class="text-muted">(optional)</span></label>
                            <input name="import_url" type="url" class="form-control" value="{{ old('import_url') }}" placeholder="https://…/calendar.ics">
                            <div class="form-text">On Airbnb: Listing → Availability → Sync calendars → Export calendar.</div></div>
                        <button class="action-btn btn-primary-cb w-100"><i class="ri-add-line"></i> Add calendar</button>
                    </form>
                </x-cb.card>
                <x-cb.card title="How calendars work" icon="ri-information-line">
                    <p class="small mb-2">Each calendar is for one room. Give the site <strong>our calendar</strong>: it shows the nights that room is booked (and nights its room type is full), without guests' names.</p>
                    <p class="small mb-2">Add <strong>their calendar</strong> and their bookings come in as reservations on that room. Calendars carry dates only, so add the guest's name and price from the site.</p>
                    <p class="small mb-0">Sites read calendars every few hours, so a room can be sold twice in between. Close a room on the site straight away when it sells here at the last minute.</p>
                </x-cb.card>
            </div>
        </div>
    @endif

    {{-- ── Sync log ────────────────────────────────────────── --}}
    @if ($tab === 'log')
        <div class="cb-card">
            @if ($logs->isEmpty())
                <div class="empty-state"><i class="ri-history-line"></i><h6>No calendars read yet</h6></div>
            @else
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>When</th><th>What</th><th class="num">Changes</th><th>Result</th></tr></thead>
                        <tbody>
                            @foreach ($logs as $l)
                                <tr>
                                    <td data-label="When" class="small text-nowrap">{{ $l->created_at->format('D j M, g:i A') }}</td>
                                    <td data-label="What" class="small">{{ ['availability' => 'Rooms left sent', 'rates' => 'Prices & rules sent', 'bookings' => 'Bookings fetched', 'ical' => 'Calendar read'][$l->action] ?? $l->action }}</td>
                                    <td data-label="Changes" class="num">{{ $l->changes }}</td>
                                    <td data-label="Result"><span class="status-pill {{ $l->ok ? 'st-success' : 'st-danger' }}">{{ $l->ok ? 'Done' : 'Failed' }}</span> <span class="small text-muted">{{ $l->message }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($logs->hasPages())<div class="p-3">{{ $logs->links() }}</div>@endif
            @endif
        </div>
    @endif

</div>
</div>
</div>
@endsection
