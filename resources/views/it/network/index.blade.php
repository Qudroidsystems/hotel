@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php
    use App\Models\Network\NetworkLink;
    use App\Models\Network\NetworkPass;
    use App\Models\Network\NetworkPlan;
    $canManage = auth()->user()->can('Manage network');
    $canIssue = auth()->user()->can('Give Wi-Fi codes');
    $o = $summary['overall'];
    $overallColor = ['online' => '#16a34a', 'degraded' => '#f59e0b', 'offline' => '#dc2626'][$o['status']] ?? '#94a3b8';
    $overallText = ['online' => 'All lines up', 'degraded' => 'Running, not at full strength', 'offline' => 'No internet', 'unknown' => 'No lines set up'][$o['status']] ?? $o['status'];
    $stripColor = ['online' => '#22c55e', 'degraded' => '#f59e0b', 'offline' => '#dc2626', 'unknown' => '#cbd5e1'];
    $printId = session('wifi_pass');
@endphp
<style>
    .nw-links { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; margin-bottom: 18px; }
    .nw-link { border-radius: 14px; border: 1px solid var(--cb-border, #e9ebec); background: var(--cb-card-bg, #fff); padding: 16px; position: relative; overflow: hidden; }
    .nw-link::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 4px; background: var(--st); }
    .nw-link.off { opacity: .55; }
    .nw-link h6 { margin: 0; font-weight: 700; color: var(--cb-heading); }
    .nw-link .meta { font-size: 12px; color: var(--bs-secondary-color, #6c757d); }
    .nw-nums { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 12px 0 8px; }
    .nw-nums div { background: var(--cb-subtle-bg, rgba(148, 163, 184, .08)); border-radius: 10px; padding: 8px 10px; }
    .nw-nums b { display: block; font-size: 17px; color: var(--cb-heading); line-height: 1.2; }
    .nw-nums span { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--bs-secondary-color, #6c757d); }
    .nw-signal { display: inline-flex; gap: 2px; align-items: flex-end; height: 16px; vertical-align: middle; }
    .nw-signal i { width: 4px; border-radius: 1px; background: #e2e8f0; }
    .nw-signal i.on { background: var(--st); }
    .nw-spark { width: 100%; height: 54px; display: block; }
    .nw-strip { display: flex; gap: 1px; height: 6px; margin-top: 2px; }
    .nw-strip span { flex: 1 1 0; border-radius: 1px; }
    .nw-bar { height: 6px; border-radius: 3px; background: #e2e8f0; overflow: hidden; }
    .nw-bar > span { display: block; height: 100%; background: var(--bar, #0ea5e9); }
    .nw-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 700; letter-spacing: .08em; font-size: 15px; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Internet & Wi-Fi" icon="ri-rocket-2-line"
               :subtitle="'Starlink dishes and other internet lines, guest Wi-Fi codes and speed plans · ' . $gateway->label()">
        <x-slot:actions>
            @if ($canIssue)<button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#passModal"><i class="ri-wifi-line"></i> Give a Wi-Fi code</button>@endif
            @if ($canManage)
                <form method="POST" action="{{ route('it.network.refresh') }}" class="d-inline" data-once>@csrf<button class="cb-hero-btn" type="submit"><i class="ri-refresh-line"></i> Read now</button></form>
                <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#linkModal"><i class="ri-add-line"></i> Add a line</button>
            @endif
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @include('it.partials.nav')

    @if ($printId)
        <div class="cb-banner success"><i class="ri-printer-line"></i><div>Hand the guest their code: <a href="{{ route('it.network.passes.print', $printId) }}" target="_blank" class="fw-semibold">print the Wi-Fi slip</a>.</div></div>
    @endif
    @if (! $gateway->live())
        <div class="cb-banner info"><i class="ri-information-line"></i><div>
            @if ($gateway->label() === 'Demo data')
                <strong>Demo data.</strong> The readings and devices online below are made up so you can try the pages. Wi-Fi codes are real records, but they only control the guest network once your router is connected.
            @else
                <strong>No router connected.</strong> Lines are listed by hand and readings are not available. Wi-Fi codes made here must also be typed into the router.
            @endif
        </div></div>
    @endif
    @foreach ($links->where('is_active', true)->where('status', 'offline') as $l)
        <div class="cb-banner danger"><i class="ri-error-warning-line"></i><div><strong>{{ $l->name }} is offline</strong> since {{ $l->status_since?->format('g:i A') }} ({{ $l->status_since?->diffForHumans(null, true) }}).
            {{ $l->last_message }} @if ($l->provider === 'starlink') Check the dish has power, a clear view of the sky and that its cable is firmly in. @endif</div></div>
    @endforeach

    <div class="it-tiles">
        <div class="it-tile" style="--tile: {{ $overallColor }}">
            <div class="t-head"><i class="ri-global-line"></i> Internet</div>
            <div class="t-main">{{ $overallText }}</div>
            <div class="t-sub">{{ $o['online'] }} of {{ $o['total'] }} line(s) up · {{ NetworkPlan::mbps($o['down_mbps']) }} down in total</div>
        </div>
        <div class="it-tile" style="--tile: #0ea5e9">
            <div class="t-head"><i class="ri-wifi-line"></i> Guest Wi-Fi</div>
            <div class="t-main">{{ $stats['connected'] }} device{{ $stats['connected'] === 1 ? '' : 's' }} online</div>
            <div class="t-sub">{{ $stats['codes'] }} active code{{ $stats['codes'] === 1 ? '' : 's' }} · network "{{ config('hotel.network.guest_ssid') }}"</div>
        </div>
        <div class="it-tile" style="--tile: #7c3aed">
            <div class="t-head"><i class="ri-database-2-line"></i> Data used</div>
            <div class="t-main">{{ number_format($stats['today_gb'], 1) }} GB today</div>
            <div class="t-sub">{{ number_format($stats['month_gb'], 1) }} GB this month across all lines</div>
        </div>
        <div class="it-tile" style="--tile: {{ $o['signal'] >= 70 ? '#16a34a' : ($o['signal'] >= 45 ? '#f59e0b' : '#dc2626') }}">
            <div class="t-head"><i class="ri-signal-wifi-3-line"></i> Signal</div>
            <div class="t-main">{{ $o['online'] ? $o['signal'] . '%' : '—' }}</div>
            <div class="t-sub">Average of the lines that are up</div>
        </div>
    </div>

    {{-- Internet lines --}}
    @if ($links->isEmpty())
        <div class="cb-card"><div class="empty-state py-5"><i class="ri-rocket-2-line"></i><h6>No internet lines yet</h6>
            <p class="small text-muted">Add each Starlink dish (and any fibre or 4G line) to see its speed, signal and data used here.</p>
            @if ($canManage)<button type="button" class="action-btn btn-go" data-bs-toggle="modal" data-bs-target="#linkModal"><i class="ri-add-line"></i> Add a line</button>@endif</div></div>
    @else
        <div class="nw-links" id="links">
            @foreach ($links as $l)
                @php
                    $rows = $history[$l->id] ?? [];
                    $max = max(10, collect($rows)->filter()->max('down') ?? 0);
                    $pts = collect($rows)->map(fn ($r, $i) => $r ? round($i * (300 / max(1, count($rows) - 1)), 1) . ',' . round(50 - ($r['down'] / $max) * 46, 1) : null)->filter()->implode(' ');
                    $bars = $l->signal === null ? 0 : (int) ceil($l->signal / 20);
                @endphp
                <div class="nw-link {{ $l->is_active ? '' : 'off' }}" style="--st: {{ $l->status_color }}" data-link="{{ $l->id }}">
                    <div class="d-flex justify-content-between gap-2 align-items-start">
                        <div style="min-width:0">
                            <h6 class="text-truncate"><i class="{{ $l->provider_icon }}"></i> {{ $l->name }}</h6>
                            <div class="meta">{{ $l->provider_label }} · {{ $l->role_label }}{{ $l->location ? ' · ' . $l->location : '' }}{{ $l->is_active ? '' : ' · not in use' }}</div>
                        </div>
                        <div class="text-end text-nowrap">
                            <span class="status-pill {{ $l->status_pill }}">{{ $l->status_label }}</span>
                            <div class="mt-1" title="Signal {{ $l->signal ?? '—' }}%"><span class="nw-signal">@for ($b = 1; $b <= 5; $b++)<i class="{{ $b <= $bars ? 'on' : '' }}" style="height: {{ 4 + $b * 2.4 }}px"></i>@endfor</span> <small class="text-muted">{{ $l->signal !== null ? $l->signal . '%' : '' }}</small></div>
                        </div>
                    </div>
                    <div class="nw-nums">
                        <div><b>{{ $l->down_mbps !== null ? number_format($l->down_mbps, 0) : '—' }}</b><span>Mbps down</span></div>
                        <div><b>{{ $l->up_mbps !== null ? number_format($l->up_mbps, 0) : '—' }}</b><span>Mbps up</span></div>
                        <div><b>{{ $l->latency_ms !== null ? $l->latency_ms : '—' }}</b><span>ms delay</span></div>
                    </div>
                    @if ($pts)
                        <svg class="nw-spark" viewBox="0 0 300 54" preserveAspectRatio="none" role="img" aria-label="Download speed over the last 24 hours">
                            <polyline points="{{ $pts }}" fill="none" stroke="{{ $l->status_color }}" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round"/>
                        </svg>
                        <div class="nw-strip">@foreach ($rows as $r)<span style="background: {{ $r ? $stripColor[$r['status']] ?? '#cbd5e1' : '#eef2f7' }}" title="{{ $r ? $r['at'] . ': ' . $r['down'] . ' Mbps, ' . ($r['latency'] ?? '—') . ' ms' : 'No reading' }}"></span>@endforeach</div>
                        <div class="d-flex justify-content-between meta mt-1"><span>24 h ago</span><span>now</span></div>
                    @endif
                    <div class="meta mt-2">
                        Packet loss {{ $l->packet_loss !== null ? rtrim(rtrim(number_format($l->packet_loss, 2), '0'), '.') . '%' : '—' }}
                        @if ($l->provider === 'starlink') · Sky blocked {{ $l->obstruction_pct !== null ? rtrim(rtrim(number_format($l->obstruction_pct, 2), '0'), '.') . '%' : '—' }}@endif
                        · Today {{ number_format($usage[$l->id]['today_gb'] ?? 0, 1) }} GB
                    </div>
                    <div class="mt-2">
                        <div class="d-flex justify-content-between meta"><span>This month {{ number_format($l->used_gb_month, 1) }} GB{{ $l->data_cap_gb ? ' of ' . number_format($l->data_cap_gb) . ' GB' : '' }}</span><span>{{ $l->cap_used_percent !== null ? $l->cap_used_percent . '%' : 'no limit' }}</span></div>
                        @if ($l->cap_used_percent !== null)<div class="nw-bar" style="--bar: {{ $l->cap_used_percent >= 90 ? '#dc2626' : ($l->cap_used_percent >= 75 ? '#f59e0b' : '#0ea5e9') }}"><span style="width: {{ $l->cap_used_percent }}%"></span></div>@endif
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <span class="meta" title="{{ $l->last_message }}">{{ $l->checked_at ? 'Read ' . $l->checked_at->diffForHumans() : 'Not read yet' }}{{ $l->last_message ? ' · ' . \Illuminate\Support\Str::limit($l->last_message, 48) : '' }}</span>
                        @if ($canManage)<button type="button" class="action-btn btn-open" data-bs-toggle="modal" data-bs-target="#linkModal{{ $l->id }}"><i class="ri-pencil-line"></i> Edit</button>@endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Guest Wi-Fi codes" icon="ri-wifi-line" :count="$passes->count()" :flush="true">
                <div class="p-3 pb-0">
                    <form method="GET" class="cb-toolbar d-flex gap-2"><input type="search" name="q" class="form-control" value="{{ $q }}" placeholder="Search code, guest or room"><button class="btn btn-light">Search</button></form>
                    @if ($inHouse->isNotEmpty() && $canIssue)
                        <div class="cb-banner warning mt-2 mb-0"><i class="ri-user-unfollow-line"></i><div>{{ $inHouse->count() }} checked-in guest(s) have no Wi-Fi code:
                            {{ $inHouse->take(6)->map(fn ($r) => $r->guest?->full_name . ' (' . ($r->rooms->pluck('room.number')->filter()->implode(', ') ?: '—') . ')')->implode(', ') }}{{ $inHouse->count() > 6 ? '…' : '' }}</div></div>
                    @endif
                </div>
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Code</th><th>Who</th><th>Plan</th><th>Online</th><th>Used</th><th>Until</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($passes as $p)
                                <tr class="{{ $printId === $p->id ? 'table-success' : '' }}">
                                    <td data-label="Code"><span class="nw-code">{{ $p->code }}</span></td>
                                    <td data-label="Who"><span class="fw-semibold">{{ $p->holder ?: '—' }}</span>
                                        <div class="small text-muted">{{ $p->room_number ? 'Room ' . $p->room_number . ' · ' : '' }}{{ $p->kind_label }}</div></td>
                                    <td data-label="Plan">{{ $p->plan?->name ?? 'Custom' }}<div class="small text-muted">{{ $p->speed_label }}</div></td>
                                    <td data-label="Online"><span class="it-dot" style="--dot: {{ $p->devices_online ? '#16a34a' : '#cbd5e1' }}"></span> {{ $p->devices_online }} / {{ $p->devices }}
                                        @if ($p->last_seen_at)<div class="small text-muted">seen {{ $p->last_seen_at->diffForHumans() }}</div>@endif</td>
                                    <td data-label="Used">{{ NetworkPlan::size($p->used_mb) }}
                                        @if ($p->cap_used_percent !== null)<div class="nw-bar mt-1" style="--bar: {{ $p->cap_used_percent >= 90 ? '#dc2626' : '#0ea5e9' }}"><span style="width: {{ $p->cap_used_percent }}%"></span></div>@endif</td>
                                    <td data-label="Until" class="small text-nowrap">{{ $p->expires_at->format('D j M, g:i A') }}</td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('it.network.passes.print', $p) }}" target="_blank" class="action-btn btn-open" title="Print a slip for the guest"><i class="ri-printer-line"></i></a>
                                        @if ($canIssue)
                                            <button type="button" class="action-btn btn-open" data-bs-toggle="modal" data-bs-target="#chg{{ $p->id }}" title="Change plan or time"><i class="ri-pencil-line"></i></button>
                                            <button type="button" class="action-btn btn-danger-soft" data-bs-toggle="modal" data-bs-target="#cut{{ $p->id }}" title="Cut off"><i class="ri-forbid-line"></i></button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-muted">{{ $q !== '' ? 'No active code matches "' . $q . '".' : 'No active Wi-Fi codes. Guests get one at check-in when a plan is set as the check-in plan.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-cb.card>

            <x-cb.card title="Recently ended" icon="ri-history-line" :flush="true">
                <div class="table-responsive">
                    <table class="cb-table stack">
                        <thead><tr><th>Code</th><th>Who</th><th>Status</th><th>Used</th><th>Ended</th></tr></thead>
                        <tbody>
                            @forelse ($ended as $p)
                                <tr><td data-label="Code"><span class="nw-code text-muted">{{ $p->code }}</span></td>
                                    <td data-label="Who">{{ $p->holder ?: '—' }}<div class="small text-muted">{{ $p->room_number ? 'Room ' . $p->room_number : $p->kind_label }}</div></td>
                                    <td data-label="Status"><span class="status-pill {{ $p->status_pill }}">{{ $p->status_label }}</span>
                                        @if ($p->revoke_reason)<div class="small text-muted">{{ $p->revoke_reason }}{{ $p->revokedBy ? ' · ' . $p->revokedBy->name : '' }}</div>@endif</td>
                                    <td data-label="Used">{{ NetworkPlan::size($p->used_mb) }}</td>
                                    <td data-label="Ended" class="small">{{ ($p->revoked_at ?? $p->expires_at)->format('D j M, g:i A') }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="text-muted">Nothing yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-cb.card>
        </div>

        <div class="col-xl-4">
            <x-cb.card title="Wi-Fi plans" icon="ri-speed-up-line" :count="$plans->count()">
                @forelse ($plans as $plan)
                    <div class="d-flex justify-content-between gap-2 py-2 border-bottom" style="{{ $plan->is_active ? '' : 'opacity:.55' }}">
                        <div style="min-width:0"><span class="fw-semibold">{{ $plan->name }}</span>
                            @if ($plan->is_default)<span class="status-pill st-info">At check-in</span>@endif
                            <div class="small text-muted">{{ $plan->speed_label }} · {{ $plan->devices }} device{{ $plan->devices === 1 ? '' : 's' }} · {{ $plan->cap_label }}</div>
                            <div class="small">{{ $plan->price > 0 ? config('hotel.currency_symbol') . number_format($plan->price, 2) . ' a day, added to the room bill' : 'Free' }} · {{ $plan->active_count }} in use</div></div>
                        @if ($canManage)<button type="button" class="action-btn btn-open align-self-start" data-bs-toggle="modal" data-bs-target="#planModal{{ $plan->id }}"><i class="ri-pencil-line"></i></button>@endif
                    </div>
                @empty
                    <div class="text-muted small">No plans yet. Add at least one, and mark it "At check-in" so every guest gets a code automatically.</div>
                @endforelse
                @if ($canManage)<button type="button" class="action-btn btn-go mt-2" data-bs-toggle="modal" data-bs-target="#planModal"><i class="ri-add-line"></i> Add a plan</button>@endif
            </x-cb.card>

            <x-cb.card title="How the control works" icon="ri-question-line">
                <ul class="small ps-3 mb-0">
                    <li>Each Starlink dish is set to <strong>bypass mode</strong> and plugged into one router, which shares traffic across the dishes and switches over when one drops.</li>
                    <li>Lines marked <strong>Staff &amp; systems</strong> keep the front desk, POS and payments working when guests are busy.</li>
                    <li>Guests join "{{ config('hotel.network.guest_ssid') }}" and type their code. Speed, devices and daily data follow their plan.</li>
                    <li>Codes start at check-in and stop at check-out (plus {{ (int) config('hotel.network.grace_minutes') }} minutes). Cutting a code off disconnects its devices.</li>
                    <li>When a line drops, an alert opens in <a href="{{ route('it.alerts.index') }}">Alerts</a> and closes by itself when it is back.</li>
                </ul>
            </x-cb.card>
        </div>
    </div>
    <p class="small text-muted mb-0">The page refreshes itself every minute.</p>

</div>
</div>
</div>

@if ($canManage)
    @include('it.network._link_form', ['link' => null])
    @foreach ($links as $l) @include('it.network._link_form', ['link' => $l]) @endforeach
    @include('it.network._plan_form', ['plan' => null])
    @foreach ($plans as $plan) @include('it.network._plan_form', ['plan' => $plan]) @endforeach
@endif

@if ($canIssue)
<div class="modal fade" id="passModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('it.network.passes.store') }}">@csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-wifi-line me-1"></i> Give a Wi-Fi code</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                @if ($errors->any())<div class="col-12"><div class="cb-banner danger mb-0"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div></div>@endif
                <div class="col-12"><label for="pass_res">For a guest staying</label>
                    <select id="pass_res" name="reservation_id" class="form-select">
                        <option value="">Someone else (visitor, event, staff device)</option>
                        @foreach ($checkedIn as $r)
                            <option value="{{ $r->id }}" @selected(old('reservation_id') == $r->id)>{{ $r->rooms->pluck('room.number')->filter()->implode(', ') ?: '—' }} · {{ $r->guest?->full_name }} (until {{ $r->check_out_date->format('D j M') }})</option>
                        @endforeach
                    </select><div class="form-text">A stay code stops at check-out by itself.</div></div>
                <div class="col-sm-7"><label for="pass_holder">Name (if not a guest)</label><input id="pass_holder" name="holder" class="form-control" maxlength="120" value="{{ old('holder') }}" placeholder="e.g. Conference: Zenith Bank"></div>
                <div class="col-sm-5"><label for="pass_kind">Type</label><select id="pass_kind" name="kind" class="form-select">@foreach (NetworkPass::KINDS as $k => $label)<option value="{{ $k }}" @selected(old('kind', 'visitor') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-7"><label for="pass_plan">Plan <span class="text-danger">*</span></label><select id="pass_plan" name="plan_id" class="form-select" required>
                    @foreach ($plans->where('is_active', true) as $plan)<option value="{{ $plan->id }}" @selected(old('plan_id', $plans->firstWhere('is_default', true)?->id) == $plan->id)>{{ $plan->name }} · {{ $plan->speed_label }}{{ $plan->price > 0 ? ' · ' . config('hotel.currency_symbol') . number_format($plan->price) . '/day' : '' }}</option>@endforeach
                </select></div>
                <div class="col-sm-5"><label for="pass_dev">Devices</label><input id="pass_dev" type="number" min="1" max="50" name="devices" class="form-control" value="{{ old('devices') }}" placeholder="Plan's number"></div>
                <div class="col-12"><label for="pass_until">Until</label><input id="pass_until" type="datetime-local" name="expires_at" class="form-control" value="{{ old('expires_at') }}"><div class="form-text">Leave empty: check-out for a guest, 24 hours for anyone else.</div></div>
                <div class="col-12"><label class="form-check"><input type="hidden" name="charge" value="0"><input type="checkbox" class="form-check-input" name="charge" value="1" checked> <span class="form-check-label">Add a paid plan's price to the guest's bill</span></label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit" @disabled($plans->where('is_active', true)->isEmpty())>Create code</button></div>
        </form>
    </div>
</div>
@foreach ($passes as $p)
<div class="modal fade" id="chg{{ $p->id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('it.network.passes.update', $p) }}">@csrf @method('PUT')
            <div class="modal-header"><h5 class="modal-title">Change code {{ $p->code }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label for="chg_plan{{ $p->id }}">Plan</label><select id="chg_plan{{ $p->id }}" name="plan_id" class="form-select">@foreach ($plans->where('is_active', true) as $plan)<option value="{{ $plan->id }}" @selected($p->plan_id == $plan->id)>{{ $plan->name }} · {{ $plan->speed_label }}</option>@endforeach</select>
                    <div class="form-text">Changing the plan here does not add a charge; post one on the bill if needed.</div></div>
                <div class="col-sm-5"><label for="chg_dev{{ $p->id }}">Devices</label><input id="chg_dev{{ $p->id }}" type="number" min="1" max="50" name="devices" class="form-control" value="{{ $p->devices }}"></div>
                <div class="col-sm-7"><label for="chg_until{{ $p->id }}">Until</label><input id="chg_until{{ $p->id }}" type="datetime-local" name="expires_at" class="form-control" value="{{ $p->expires_at->format('Y-m-d\TH:i') }}"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div>
</div>
<div class="modal fade" id="cut{{ $p->id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('it.network.passes.revoke', $p) }}">@csrf
            <div class="modal-header"><h5 class="modal-title">Cut off {{ $p->code }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p class="small">{{ $p->holder ?: 'This code' }}{{ $p->room_number ? ' (room ' . $p->room_number . ')' : '' }} will be disconnected straight away.</p>
                <label for="cut_reason{{ $p->id }}">Why <span class="text-danger">*</span></label><input id="cut_reason{{ $p->id }}" name="reason" class="form-control" maxlength="255" required placeholder="e.g. Code shared outside the hotel"></div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit">Cut off</button></div>
        </form>
    </div>
</div>
@endforeach
@if ($errors->any() && old('plan_id') && ! old('name'))<script>document.addEventListener('DOMContentLoaded', () => bootstrap.Modal.getOrCreateInstance(document.getElementById('passModal')).show());</script>@endif
@endif
<script>
    // Refresh every minute, but not while someone is typing or a dialog is open.
    setInterval(function () {
        if (document.hidden || document.querySelector('.modal.show') || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
        location.reload();
    }, 60000);
</script>
@endsection
