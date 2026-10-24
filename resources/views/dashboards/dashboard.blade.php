@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="'Welcome, ' . auth()->user()->first_name" icon="ri-hotel-line"
               :subtitle="config('hotel.name') . ' · ' . now()->format('l, j F Y')">
        <x-slot:pills>
            @foreach(auth()->user()->getRoleNames() as $roleName)
                <span class="cb-meta-pill"><i class="ri-shield-user-line"></i> {{ $roleName }}</span>
            @endforeach
        </x-slot:pills>
        @can('Create user')
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('users.index') }}"><i class="ri-user-add-line me-1"></i> Manage users</a>
        </x-slot:actions>
        @endcan
    </x-cb.hero>

    @if ($bar && $bar['attention'] > 0)
        <div class="cb-banner warning"><i class="ri-alarm-warning-line"></i><div><strong>{{ $bar['attention'] }} bar/restaurant sale{{ $bar['attention'] === 1 ? '' : 's' }} need{{ $bar['attention'] === 1 ? 's' : '' }} attention</strong> — usually a room charge that couldn’t go on a guest bill. <a href="{{ route('pos.orders', ['status' => 'attention', 'from' => now()->subDays(30)->toDateString(), 'to' => today()->toDateString()]) }}" class="fw-bold">Sort them out</a></div></div>
    @endif
    @if ($staffToday && $staffToday['total'])
        <x-cb.card title="Staff today" icon="ri-user-follow-line" class="mb-3">
            <x-slot:tools><a href="{{ route('attendance.board') }}" class="action-btn btn-open">Who's in</a></x-slot:tools>
            <div class="row g-3 text-center">
                <div class="col-4 col-md-2"><div class="fs-3 fw-bold text-success">{{ $staffToday['in'] }}</div><div class="small text-muted">In now</div></div>
                <div class="col-4 col-md-2"><div class="fs-3 fw-bold text-warning">{{ $staffToday['late'] }}</div><div class="small text-muted">Late</div></div>
                <div class="col-4 col-md-2"><div class="fs-3 fw-bold" style="color:#0284c7">{{ $staffToday['due'] }}</div><div class="small text-muted">Not in yet</div></div>
                <div class="col-4 col-md-2"><div class="fs-3 fw-bold text-danger">{{ $staffToday['absent'] }}</div><div class="small text-muted">Absent</div></div>
                <div class="col-4 col-md-2"><div class="fs-3 fw-bold text-info">{{ $staffToday['leave'] }}</div><div class="small text-muted">On leave</div></div>
                <div class="col-4 col-md-2"><div class="fs-3 fw-bold">{{ $staffToday['total'] }}</div><div class="small text-muted">Staff tracked</div></div>
            </div>
        </x-cb.card>
    @endif
    @if ($money || $bar)
        @php $sym = config('hotel.currency_symbol'); @endphp
        <div class="row g-3 mb-3">
            @canany(['View finance reports', 'Receive payments'])
                <div class="col-6 col-lg-4"><a href="{{ route('billing.payments') }}" class="text-decoration-none"><x-cb.stat :label="$money['mine'] ? 'You collected today' : 'Collected today'" :value="$sym . number_format($money['collected'], 2)" icon="ri-hand-coin-line" accent="green" :hint="$money['count'] . ' payment' . ($money['count'] === 1 ? '' : 's') . ($money['pos'] > 0 ? ' · incl. bar ' . $sym . number_format($money['pos'], 0) : '')" /></a></div>
            @endcanany
            @can('View folios')
                <div class="col-6 col-lg-4"><a href="{{ route('billing.outstanding') }}" class="text-decoration-none"><x-cb.stat label="Owed by guests" :value="$sym . number_format($money['owed'], 2)" icon="ri-funds-line" accent="rose" :hint="$money['owing'] . ' open bill' . ($money['owing'] === 1 ? '' : 's')" /></a></div>
                <div class="col-6 col-lg-4"><a href="{{ route('billing.outstanding', ['tab' => 'credit']) }}" class="text-decoration-none"><x-cb.stat label="Guest credits" :value="$sym . number_format($money['credit'], 2)" icon="ri-refund-2-line" accent="teal" hint="deposits & overpayments" /></a></div>
            @endcan
            @if ($bar)
                <div class="col-6 col-lg-4"><a href="{{ route('pos.orders') }}" class="text-decoration-none"><x-cb.stat label="Bar & restaurant today" :value="$sym . number_format($bar['sales'], 2)" icon="ri-goblet-line" accent="amber" :hint="$bar['count'] . ' sale' . ($bar['count'] === 1 ? '' : 's') . ($bar['room'] > 0 ? ' · ' . $sym . number_format($bar['room'], 0) . ' to rooms' : '')" /></a></div>
            @endif
        </div>
    @endif

    @if ($stock)
        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3"><a href="{{ route('inventory.index') }}" class="text-decoration-none"><x-cb.stat label="Stock value" :value="config('hotel.currency_symbol') . number_format($stock['value'], 0)" icon="ri-archive-line" accent="teal" /></a></div>
            <div class="col-6 col-lg-3"><a href="{{ route('inventory.index', ['tab' => 'low']) }}" class="text-decoration-none"><x-cb.stat label="Items to reorder" :value="$stock['low']" icon="ri-alarm-warning-line" accent="amber" /></a></div>
            <div class="col-6 col-lg-3"><a href="{{ route('inventory.po.index') }}" class="text-decoration-none"><x-cb.stat label="Deliveries due" :value="$stock['due']" icon="ri-truck-line" accent="sky" hint="purchase orders not yet received" /></a></div>
            <div class="col-6 col-lg-3"><a href="{{ route('inventory.index', ['tab' => 'negative']) }}" class="text-decoration-none"><x-cb.stat label="Below zero" :value="$stock['negative']" icon="ri-error-warning-line" accent="rose" hint="items to check" /></a></div>
        </div>
    @endif

    @if ($frontDesk)
        <div class="row g-3 mb-1">
            <div class="col-6 col-lg-3"><a href="{{ route('reservations.index', ['tab' => 'arrivals']) }}" class="text-decoration-none"><x-cb.stat label="Arrivals today" :value="$frontDesk['arrivals']->count()" icon="ri-login-box-line" accent="sky" /></a></div>
            <div class="col-6 col-lg-3"><a href="{{ route('reservations.index', ['tab' => 'inhouse']) }}" class="text-decoration-none"><x-cb.stat label="Guests in house" :value="$frontDesk['inhouse']" icon="ri-hotel-bed-line" accent="violet" /></a></div>
            <div class="col-6 col-lg-3"><a href="{{ route('reservations.index', ['tab' => 'departures']) }}" class="text-decoration-none"><x-cb.stat label="Departures today" :value="$frontDesk['departures']" icon="ri-logout-box-r-line" accent="amber" /></a></div>
            <div class="col-6 col-lg-3"><a href="{{ route('reservations.index', ['tab' => 'upcoming']) }}" class="text-decoration-none"><x-cb.stat label="Arriving next 7 days" :value="$frontDesk['upcoming']" icon="ri-calendar-event-line" accent="green" /></a></div>
        </div>
        @if ($frontDesk['arrivals']->isNotEmpty())
            <x-cb.card title="Expected today" icon="ri-login-box-line" :count="$frontDesk['arrivals']->count()" :flush="true" class="mt-3">
                <x-slot:tools>
                    @can('Create reservations')<a href="{{ route('reservations.create') }}" class="action-btn btn-go"><i class="ri-add-line"></i> Walk-in</a>@endcan
                </x-slot:tools>
                <table class="cb-table stack">
                    <tbody>
                        @foreach ($frontDesk['arrivals']->take(6) as $a)
                            <tr>
                                <td data-label="Guest"><a href="{{ route('reservations.show', $a) }}" class="fw-bold text-reset">{{ $a->guest->full_name }}</a> <span class="small text-muted">{{ $a->reference }}</span></td>
                                <td data-label="Arrival" class="small">{{ $a->expected_arrival_time ? \Carbon\Carbon::parse($a->expected_arrival_time)->format('g:i A') : 'Time not given' }}</td>
                                <td data-label="Rooms">
                                    @foreach ($a->rooms as $line)
                                        <span class="status-pill {{ $line->room ? 'st-info' : 'st-warning' }}">{{ $line->room ? 'Room ' . $line->room->number : $line->roomType->code . ' · unassigned' }}</span>
                                    @endforeach
                                </td>
                                <td class="text-end"><a href="{{ route('reservations.show', $a) }}" class="action-btn btn-more"><i class="ri-arrow-right-line"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-cb.card>
        @endif
    @endif

    @if ($roomCounts)
        @php
            $rc = $roomCounts['by_status'];
            $occupancy = $roomCounts['total'] ? round($rc['occupied'] / $roomCounts['total'] * 100) : 0;
        @endphp
        <x-cb.card title="Rooms right now" icon="ri-hotel-bed-line" :count="$roomCounts['total']">
            <x-slot:tools>
                <a href="{{ route('rooms.index') }}" class="action-btn btn-open"><i class="ri-layout-grid-line"></i> Room board</a>
            </x-slot:tools>
            @if ($roomCounts['total'] === 0)
                <div class="text-muted small">No rooms set up yet. @can('Create rooms')<a href="{{ route('rooms.bulk.create') }}">Add rooms</a>@endcan</div>
            @else
                <div class="row g-3 align-items-center">
                    <div class="col-md-3 text-center">
                        <div class="rm-occ" style="--p: {{ $occupancy }}"><span>{{ $occupancy }}%</span></div>
                        <div class="small text-muted mt-2 fw-semibold">Occupancy</div>
                    </div>
                    <div class="col-md-9">
                        <div class="row g-2">
                            @foreach (\App\Enums\RoomStatus::cases() as $st)
                                <div class="col-6 col-xl-3">
                                    <a href="{{ route('rooms.index', ['status' => $st->value]) }}" class="d-flex align-items-center gap-2 p-2 rounded-3 text-decoration-none border" style="border-color: color-mix(in srgb, {{ $st->color() }} 30%, transparent) !important">
                                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle text-white" style="width:34px;height:34px;background:{{ $st->color() }}"><i class="{{ $st->icon() }}"></i></span>
                                        <span><span class="d-block fw-bold fs-5" style="color: var(--cb-heading); line-height:1">{{ $rc[$st->value] }}</span><span class="small text-muted">{{ $st->label() }}</span></span>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </x-cb.card>
        <style>
            .rm-occ { --c: #7c3aed; width:110px; height:110px; border-radius:50%; margin:0 auto; display:flex; align-items:center; justify-content:center;
                background: conic-gradient(var(--c) calc(var(--p) * 1%), var(--cb-border) 0); }
            .rm-occ span { width:84px; height:84px; border-radius:50%; background: var(--cb-surface); display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:800; color: var(--cb-heading); }
        </style>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><x-cb.stat label="Staff accounts" :value="$stats['users']" icon="ri-team-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Active accounts" :value="$stats['active']" icon="ri-user-follow-line" accent="green" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Roles" :value="$stats['roles']" icon="ri-shield-user-line" accent="sky" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Permissions" :value="$stats['permissions']" icon="ri-lock-2-line" accent="violet" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-5">
            <x-cb.card title="Staff by role" icon="ri-pie-chart-2-line" :count="$usersByRole->count()">
                @forelse($usersByRole as $role)
                    @php $pct = $stats['users'] ? round($role->users_count / $stats['users'] * 100) : 0; @endphp
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            @can('View role')
                                <a href="{{ route('roles.show', $role->id) }}" class="fw-semibold text-reset">{{ $role->name }}</a>
                            @else
                                <span class="fw-semibold">{{ $role->name }}</span>
                            @endcan
                            <span class="text-muted">{{ $role->users_count }}</span>
                        </div>
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar" role="progressbar" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="empty-state"><i class="ri-shield-line"></i><h6>No roles yet</h6><p>Run the database seeder to create the hotel roles.</p></div>
                @endforelse
            </x-cb.card>
        </div>

        <div class="col-xl-7">
            <x-cb.card title="Recent sign-ins" icon="ri-login-circle-line" :flush="true">
                @if($recentLogins->isEmpty())
                    <div class="empty-state"><i class="ri-time-line"></i><h6>No sign-ins recorded yet</h6><p>Sign-in activity will appear here.</p></div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>User</th><th>Role</th><th class="text-end">Last sign-in</th></tr></thead>
                            <tbody>
                            @foreach($recentLogins as $u)
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="{{ $u->avatar_url }}" alt="" class="rounded-circle" width="32" height="32" style="object-fit:cover" onerror="this.onerror=null;this.src='{{ $u->fallback_avatar_url }}'">
                                            <div>
                                                <div class="fw-semibold">{{ $u->name }}</div>
                                                <div class="text-muted small">{{ $u->email }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ $u->roles->pluck('name')->implode(', ') ?: '—' }}</td>
                                    <td class="text-end text-muted small" title="{{ $u->last_login_at->format('d M Y, g:i A') }}">{{ $u->last_login_at->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-cb.card>

            <x-cb.card title="Newest accounts" icon="ri-user-add-line" :flush="true" class="mt-3">
                <ul class="list-group list-group-flush">
                    @forelse($newestUsers as $u)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                @can('View user')
                                    <a href="{{ route('users.show', $u->id) }}" class="fw-semibold text-reset">{{ $u->name }}</a>
                                @else
                                    <span class="fw-semibold">{{ $u->name }}</span>
                                @endcan
                                <span class="text-muted small ms-1">{{ $u->roles->pluck('name')->implode(', ') }}</span>
                            </span>
                            <span class="text-muted small">{{ $u->created_at->format('d M Y') }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">No accounts yet.</li>
                    @endforelse
                </ul>
            </x-cb.card>
        </div>
    </div>

</div>
</div>
</div>
@endsection
