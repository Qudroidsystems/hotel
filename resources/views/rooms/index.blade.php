@extends('layouts.master')

@section('content')
@include('rooms.partials.styles')
@php
    use App\Enums\RoomStatus;
    $baseQuery = array_filter($filters + ['view' => $view === 'list' ? 'list' : null]);
    $byBuilding = $rooms->groupBy('building_id');
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$view === 'list' ? 'Room List' : 'Room Board'" icon="ri-hotel-bed-line"
               subtitle="Live status of every room. Click a room to change its status or open its details.">
        <x-slot:pills>
            <span class="cb-meta-pill"><i class="ri-door-line"></i> {{ $totalActive }} active rooms</span>
            <span class="cb-meta-pill"><i class="ri-checkbox-circle-line"></i> {{ $statusCounts[RoomStatus::Available->value] ?? 0 }} available</span>
            @php $occ = ($statusCounts[RoomStatus::Occupied->value] ?? 0); @endphp
            <span class="cb-meta-pill"><i class="ri-pie-chart-line"></i> {{ $totalActive ? round($occ / $totalActive * 100) : 0 }}% occupied</span>
        </x-slot:pills>
        <x-slot:actions>
            @can('Create rooms')
                <a class="cb-hero-btn" href="{{ route('rooms.create') }}"><i class="ri-add-line"></i> Add room</a>
                <a class="cb-hero-btn" href="{{ route('rooms.bulk.create') }}"><i class="ri-stack-line"></i> Add in bulk</a>
            @endcan
            <a class="cb-hero-btn" href="{{ route('room-types.index') }}"><i class="ri-price-tag-3-line"></i> Room types</a>
            <a class="cb-hero-btn" href="{{ route('buildings.index') }}"><i class="ri-building-2-line"></i> Buildings</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    {{-- Status summary (click to filter) --}}
    <div class="rm-chips">
        <a href="{{ route('rooms.index', \Illuminate\Support\Arr::except($baseQuery, 'status')) }}"
           class="rm-chip {{ empty($filters['status']) ? 'active' : '' }}">
            <span class="dot" style="background: var(--cb-heading)"></span> All <span class="n">{{ $totalActive }}</span>
        </a>
        @foreach (RoomStatus::cases() as $st)
            <a href="{{ route('rooms.index', ['status' => $st->value] + $baseQuery) }}"
               class="rm-chip {{ ($filters['status'] ?? null) === $st->value ? 'active' : '' }}" style="--st: {{ $st->color() }}">
                <span class="dot"></span> {{ $st->label() }} <span class="n">{{ $statusCounts[$st->value] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <div class="cb-card">
        {{-- Filters --}}
        <form method="GET" action="{{ route('rooms.index') }}" class="cb-toolbar" id="roomFilters">
            @if ($view === 'list')<input type="hidden" name="view" value="list">@endif
            @if (! empty($filters['status']))<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <div class="cb-search">
                <i class="ri-search-line"></i>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Room number…" aria-label="Search room number">
            </div>
            <select name="building" class="cb-select" aria-label="Building" onchange="this.form.submit()">
                <option value="">All buildings</option>
                @foreach ($buildings as $b)
                    <option value="{{ $b->id }}" @selected(($filters['building'] ?? '') == $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
            <select name="floor" class="cb-select" aria-label="Floor" onchange="this.form.submit()">
                <option value="">All floors</option>
                @foreach ($buildings as $b)
                    <optgroup label="{{ $b->name }}">
                        @foreach ($b->floors as $f)
                            <option value="{{ $f->id }}" @selected(($filters['floor'] ?? '') == $f->id)>{{ $f->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <select name="type" class="cb-select" aria-label="Room type" onchange="this.form.submit()">
                <option value="">All room types</option>
                @foreach ($roomTypes as $t)
                    <option value="{{ $t->id }}" @selected(($filters['type'] ?? '') == $t->id)>{{ $t->name }}</option>
                @endforeach
            </select>
            <label class="d-flex align-items-center gap-2 small fw-semibold text-muted m-0">
                <input type="checkbox" name="inactive" value="1" class="form-check-input m-0" @checked(! empty($filters['inactive'])) onchange="this.form.submit()">
                Show inactive
            </label>
            <div class="ms-auto d-flex gap-2">
                @if (array_filter($filters))
                    <a href="{{ route('rooms.index', $view === 'list' ? ['view' => 'list'] : []) }}" class="action-btn btn-more"><i class="ri-close-line"></i> Clear</a>
                @endif
                <div class="term-chips" role="group" aria-label="View">
                    <a href="{{ route('rooms.index', \Illuminate\Support\Arr::except($baseQuery, 'view')) }}" class="term-chip {{ $view === 'board' ? 'active' : '' }}"><i class="ri-layout-grid-line"></i> Board</a>
                    <a href="{{ route('rooms.index', ['view' => 'list'] + $baseQuery) }}" class="term-chip {{ $view === 'list' ? 'active' : '' }}"><i class="ri-list-check"></i> List</a>
                </div>
            </div>
        </form>

        @if ($rooms->isEmpty())
            <div class="empty-state">
                <i class="ri-hotel-bed-line"></i>
                @if (array_filter($filters))
                    <h6>No rooms match these filters</h6>
                    <p>Try clearing a filter.</p>
                @else
                    <h6>No rooms yet</h6>
                    <p>Set up your <a href="{{ route('buildings.index') }}">buildings &amp; floors</a> and <a href="{{ route('room-types.index') }}">room types</a>, then add rooms.</p>
                    @can('Create rooms')
                        <a href="{{ route('rooms.bulk.create') }}" class="action-btn btn-primary-cb mt-3"><i class="ri-stack-line"></i> Add rooms in bulk</a>
                    @endcan
                @endif
            </div>

        @elseif ($view === 'board')
            <div class="cb-card-body">
                @foreach ($byBuilding as $buildingRooms)
                    @php $building = $buildingRooms->first()->building; @endphp
                    @if ($buildings->count() > 1)
                        <h5 class="fw-bold mb-3 d-flex align-items-center gap-2" style="color: var(--cb-heading)">
                            <i class="ri-building-2-line" style="color: var(--cb-teal)"></i> {{ $building->name }}
                            <span class="cb-count">{{ $buildingRooms->count() }}</span>
                        </h5>
                    @endif
                    @foreach ($buildingRooms->groupBy('floor_id') as $floorRooms)
                        <div class="rm-floor">
                            <div class="rm-floor-head">{{ $floorRooms->first()->floor->name }} · {{ $floorRooms->count() }} rooms</div>
                            <div class="rm-grid">
                                @foreach ($floorRooms as $room)
                                    @php $st = $room->status; @endphp
                                    @can('Change room status')
                                        <button type="button" class="rm-tile {{ $room->is_active ? '' : 'inactive' }}" style="--st: {{ $st->color() }}"
                                                data-status-room="{{ $room->id }}" data-status-number="{{ $room->number }}" data-status-current="{{ $st->value }}"
                                                title="{{ $room->roomType->name }} · {{ $st->label() }}{{ $room->status_changed_at ? ' since ' . $room->status_changed_at->diffForHumans() : '' }}">
                                    @else
                                        <a href="{{ route('rooms.show', $room) }}" class="rm-tile d-block text-decoration-none {{ $room->is_active ? '' : 'inactive' }}" style="--st: {{ $st->color() }}"
                                           title="{{ $room->roomType->name }} · {{ $st->label() }}">
                                    @endcan
                                        <span class="type-dot" style="background: {{ $room->roomType->color }}"></span>
                                        <div class="num">{{ $room->number }}</div>
                                        <div class="type">{{ $room->roomType->name }}</div>
                                        <div class="st"><i class="{{ $st->icon() }}"></i> {{ $st->label() }}</div>
                                    @can('Change room status')
                                        </button>
                                    @else
                                        </a>
                                    @endcan
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endforeach

                <div class="d-flex flex-wrap gap-3 small text-muted mt-2 pt-3 border-top">
                    <span class="fw-semibold">Room types:</span>
                    @foreach ($roomTypes as $t)
                        <span class="d-inline-flex align-items-center gap-1"><span style="width:8px;height:8px;border-radius:50%;background:{{ $t->color }}"></span>{{ $t->name }}</span>
                    @endforeach
                </div>
            </div>

        @else
            {{-- List view with bulk status change --}}
            <form method="POST" action="{{ route('rooms.bulk-status') }}" id="bulkStatusForm">
                @csrf
                @can('Change room status')
                    <div class="cb-toolbar" id="bulkBar" style="display:none; background: var(--cb-hover)">
                        <strong class="small"><span id="bulkCount">0</span> selected</strong>
                        <select name="status" class="cb-select" required aria-label="New status">
                            <option value="">Set status to…</option>
                            @foreach (RoomStatus::options() as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="reason" class="cb-select" style="min-width:240px" maxlength="500" placeholder="Reason (required for maintenance / out of service)">
                        <button class="action-btn btn-primary-cb" type="submit"><i class="ri-check-line"></i> Apply</button>
                    </div>
                @endcan
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead>
                            <tr>
                                @can('Change room status')<th style="width:36px"><input type="checkbox" class="form-check-input" id="checkAllRooms" aria-label="Select all"></th>@endcan
                                <th>Room</th><th>Type</th><th>Location</th><th>Status</th><th class="num">Rate / night</th><th>Last change</th><th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rooms as $room)
                                <tr class="{{ $room->is_active ? '' : 'opacity-50' }}">
                                    @can('Change room status')<td><input type="checkbox" name="rooms[]" value="{{ $room->id }}" class="form-check-input room-check" aria-label="Select room {{ $room->number }}"></td>@endcan
                                    <td data-label="Room"><a href="{{ route('rooms.show', $room) }}" class="fw-bold text-reset">{{ $room->number }}</a>
                                        @unless ($room->is_active)<span class="status-pill st-muted ms-1">Inactive</span>@endunless</td>
                                    <td data-label="Type"><span class="d-inline-flex align-items-center gap-1"><span style="width:8px;height:8px;border-radius:50%;background:{{ $room->roomType->color }}"></span>{{ $room->roomType->name }}</span></td>
                                    <td data-label="Location" class="text-muted">{{ $room->building->name }} · {{ $room->floor->name }}</td>
                                    <td data-label="Status">@include('rooms.partials.status-pill', ['status' => $room->status])</td>
                                    <td data-label="Rate" class="num">@money($room->rate)@if ($room->rate_override !== null)<i class="ri-pushpin-2-line text-muted ms-1" title="Custom rate for this room"></i>@endif</td>
                                    <td data-label="Last change" class="small text-muted">
                                        {{ $room->status_changed_at?->diffForHumans() ?? '—' }}
                                        @if ($room->statusChangedBy)<div>by {{ $room->statusChangedBy->name }}</div>@endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @can('Change room status')
                                            <button type="button" class="action-btn btn-go" data-status-room="{{ $room->id }}" data-status-number="{{ $room->number }}" data-status-current="{{ $room->status->value }}"><i class="ri-refresh-line"></i> Status</button>
                                        @endcan
                                        <a href="{{ route('rooms.show', $room) }}" class="action-btn btn-open"><i class="ri-eye-line"></i></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </form>
        @endif
    </div>

</div>
</div>
</div>

@include('rooms.partials.status-modal')

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Submit the search box after a short pause in typing.
    const q = document.querySelector('#roomFilters input[name=q]');
    let t; q?.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => q.form.submit(), 600); });

    // Bulk selection in list view.
    const bar = document.getElementById('bulkBar');
    const all = document.getElementById('checkAllRooms');
    const checks = () => Array.from(document.querySelectorAll('.room-check'));
    function refresh() {
        const n = checks().filter(c => c.checked).length;
        if (bar) { bar.style.display = n ? 'flex' : 'none'; document.getElementById('bulkCount').textContent = n; }
    }
    all?.addEventListener('change', () => { checks().forEach(c => c.checked = all.checked); refresh(); });
    document.addEventListener('change', e => { if (e.target.classList.contains('room-check')) refresh(); });
});
</script>
@endsection
