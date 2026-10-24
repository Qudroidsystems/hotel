@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@include('it.partials.styles')
@php
    use App\Models\It\ItMonitor;
    $canManage = auth()->user()->can('Manage IT monitoring');
    $types = collect(ItMonitor::TYPES)->map(fn ($t) => ['label' => $t[0], 'target' => $t[2], 'threshold' => $t[3], 'default' => $t[4]]);
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Monitors" icon="ri-pulse-line" subtitle="What the control room checks automatically, how often, and when it raises an alert">
        <x-slot:actions>
            @if ($canManage)<button type="button" class="cb-hero-btn" data-monitor="new"><i class="ri-add-line"></i> Add monitor</button>@endif
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @include('it.partials.nav')

    @if ($monitors->isEmpty())
        <div class="cb-card"><div class="empty-state py-5"><i class="ri-pulse-line"></i><h6>No monitors yet</h6>
            <p class="small text-muted mb-3">Good first monitors: your internet (ping 8.8.8.8), the main router, each Wi-Fi access point, the hotel website, this system's database and disk, and the backup folder.</p>
            @if ($canManage)<button type="button" class="action-btn btn-go" data-monitor="new"><i class="ri-add-line"></i> Add monitor</button>@endif</div></div>
    @endif

    @foreach ($monitors->groupBy('category') as $cat => $group)
        <x-cb.card :title="ItMonitor::CATEGORIES[$cat][0] ?? $cat" :icon="ItMonitor::CATEGORIES[$cat][1] ?? ''" :count="$group->count()" :flush="true" :id="$cat">
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Monitor</th><th>Checks</th><th>Status</th><th class="num">7-day uptime</th><th>Alert</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($group as $m)
                            <tr style="{{ $m->is_active ? '' : 'opacity:.6' }}">
                                <td data-label="Monitor"><a href="{{ route('it.monitors.show', $m) }}" class="fw-semibold"><i class="{{ $m->type_icon }} text-muted"></i> {{ $m->name }}</a>
                                    <div class="small text-muted text-break">{{ $m->type_label }} · {{ $m->target_label }}{{ $m->asset ? ' · ' . $m->asset->name : '' }}</div></td>
                                <td data-label="Checks" class="small">every {{ $m->interval_minutes }} min<div class="text-muted">{{ $m->last_checked_at ? 'last ' . $m->last_checked_at->diffForHumans() : 'never' }}</div></td>
                                <td data-label="Status"><span class="status-pill {{ $m->status_pill }}">{{ $m->status_label }}</span>
                                    <div class="small text-muted text-truncate" style="max-width:260px" title="{{ $m->last_message }}">{{ $m->last_message }}</div></td>
                                <td data-label="7-day uptime" class="num">{{ isset($uptime[$m->id]) ? rtrim(rtrim(number_format($uptime[$m->id], 2), '0'), '.') . '%' : '—' }}</td>
                                <td data-label="Alert" class="small"><span class="status-pill {{ \App\Models\It\ItTicket::PRIORITIES[$m->severity][1] ?? 'st-muted' }}">{{ ucfirst($m->severity) }}</span><div class="text-muted">after {{ $m->alert_after }} fail{{ $m->alert_after === 1 ? '' : 's' }}</div></td>
                                <td class="text-end text-nowrap">
                                    @if ($canManage)
                                        <form method="POST" action="{{ route('it.monitors.check', $m) }}" class="d-inline" data-once>@csrf<button class="action-btn btn-open" title="Check now"><i class="ri-refresh-line"></i></button></form>
                                        <button type="button" class="action-btn btn-more" data-monitor="{{ json_encode($m->only(['id', 'name', 'category', 'type', 'target', 'port', 'asset_id', 'severity', 'interval_minutes', 'timeout_seconds', 'alert_after', 'expect_status', 'keyword', 'threshold', 'is_active', 'sort_order'])) }}"><i class="ri-pencil-line"></i></button>
                                        <form method="POST" action="{{ route('it.monitors.toggle', $m) }}" class="d-inline" data-once>@csrf<button class="action-btn btn-more" title="{{ $m->is_active ? 'Pause' : 'Resume' }}"><i class="{{ $m->is_active ? 'ri-pause-line' : 'ri-play-line' }}"></i></button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-cb.card>
    @endforeach

</div>
</div>
</div>

@if ($canManage)
<div class="modal fade" id="monitorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content rm-form" method="POST" action="{{ route('it.monitors.store') }}" id="monitorForm">
            @csrf
            <input type="hidden" name="_method" value="POST" id="monMethod">
            <input type="hidden" name="monitor_id" value="{{ old('monitor_id') }}" id="monId">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-pulse-line me-1"></i> <span id="monTitle">Add monitor</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                @if ($errors->any())<div class="col-12"><div class="cb-banner danger mb-0"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div></div>@endif
                <div class="col-sm-6"><label for="m_type">What to check <span class="text-danger">*</span></label>
                    <select name="type" id="m_type" class="form-select" required>@foreach ($types as $k => $t)<option value="{{ $k }}" @selected(old('type') === $k)>{{ $t['label'] }}</option>@endforeach</select></div>
                <div class="col-sm-6"><label for="m_name">Name <span class="text-danger">*</span></label><input name="name" id="m_name" class="form-control" maxlength="120" required value="{{ old('name') }}" placeholder="e.g. Main router, Lobby Wi-Fi"></div>
                <div class="col-sm-8" id="m_target_wrap"><label for="m_target" id="m_target_label">Address</label><input name="target" id="m_target" class="form-control" maxlength="500" value="{{ old('target') }}"></div>
                <div class="col-sm-4" id="m_port_wrap"><label for="m_port">Port</label><input type="number" name="port" id="m_port" class="form-control" min="1" max="65535" value="{{ old('port') }}" placeholder="e.g. 80, 443, 22"></div>
                <div class="col-sm-4"><label for="m_category">Group</label>
                    <select name="category" id="m_category" class="form-select">@foreach (ItMonitor::CATEGORIES as $k => [$label])<option value="{{ $k }}" @selected(old('category') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-4"><label for="m_asset_id">Equipment</label>
                    <select name="asset_id" id="m_asset_id" class="form-select"><option value="">—</option>@foreach ($assets as $a)<option value="{{ $a->id }}" data-ip="{{ $a->ip_address }}" @selected(old('asset_id') == $a->id)>{{ $a->name }}</option>@endforeach</select></div>
                <div class="col-sm-4"><label for="m_severity">Alert severity when down</label>
                    <select name="severity" id="m_severity" class="form-select">@foreach (\App\Models\It\ItTicket::PRIORITIES as $k => [$label])<option value="{{ $k }}" @selected(old('severity', 'high') === $k)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-sm-4"><label for="m_interval">Check every (minutes)</label><input type="number" name="interval_minutes" id="m_interval" class="form-control" min="1" max="1440" required value="{{ old('interval_minutes', 5) }}"></div>
                <div class="col-sm-4"><label for="m_alert_after">Alert after this many failed checks in a row</label><input type="number" name="alert_after" id="m_alert_after" class="form-control" min="1" max="20" required value="{{ old('alert_after', 2) }}"></div>
                <div class="col-sm-4"><label for="m_timeout">Give up after (seconds)</label><input type="number" name="timeout_seconds" id="m_timeout" class="form-control" min="1" max="60" required value="{{ old('timeout_seconds', 10) }}"></div>
                <div class="col-sm-6"><label for="m_threshold" id="m_threshold_label">Threshold</label><input type="number" step="0.01" name="threshold" id="m_threshold" class="form-control" min="0" value="{{ old('threshold') }}"><div class="form-text" id="m_threshold_help"></div></div>
                <div class="col-sm-6 http-only"><label for="m_expect">Expected HTTP status</label><input type="number" name="expect_status" id="m_expect" class="form-control" min="100" max="599" value="{{ old('expect_status') }}" placeholder="Any 200–399"></div>
                <div class="col-sm-6 http-only"><label for="m_keyword">Page must contain</label><input name="keyword" id="m_keyword" class="form-control" maxlength="120" value="{{ old('keyword') }}" placeholder="Optional, e.g. Book now"></div>
                <div class="col-sm-3"><label for="m_sort">Order</label><input type="number" name="sort_order" id="m_sort" class="form-control" min="0" max="999" value="{{ old('sort_order', 0) }}"></div>
                <div class="col-sm-3 d-flex align-items-end" id="m_active_wrap"><label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="is_active" value="1" id="m_is_active" checked> <span class="form-check-label">Active</span></label></div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-danger d-none" id="monDelete">Remove</button>
                <div><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button> <button class="btn btn-primary" type="submit">Save &amp; check</button></div>
            </div>
        </form>
    </div>
</div>
<form method="POST" id="monDeleteForm" class="d-none">@csrf @method('DELETE')</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const types = @json($types), form = document.getElementById('monitorForm'), modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('monitorModal'));
    const storeUrl = @json(route('it.monitors.store')), updateUrl = @json(route('it.monitors.update', '__ID__')), deleteUrl = @json(route('it.monitors.destroy', '__ID__'));
    const $ = id => document.getElementById(id);
    const fields = ['name', 'category', 'type', 'target', 'port', 'asset_id', 'severity', 'interval_minutes', 'timeout_seconds', 'alert_after', 'expect_status', 'keyword', 'threshold', 'sort_order'];
    const map = { interval_minutes: 'm_interval', timeout_seconds: 'm_timeout', expect_status: 'm_expect', sort_order: 'm_sort' };
    const el = k => $(map[k] || 'm_' + k);
    const defaultCat = { http: 'application', ssl: 'application', database: 'infrastructure', disk: 'infrastructure', backup: 'infrastructure', queue: 'application', ping: 'network', tcp: 'network' };

    function sync(changedType) {
        const t = $('m_type').value, info = types[t];
        const noTarget = ['database', 'queue'].includes(t);
        $('m_target_wrap').style.display = noTarget ? 'none' : '';
        $('m_target_label').textContent = info.target;
        $('m_target').required = ['http', 'ping', 'tcp', 'ssl', 'backup'].includes(t);
        $('m_port_wrap').style.display = ['tcp', 'ssl'].includes(t) ? '' : 'none';
        $('m_port').required = t === 'tcp';
        document.querySelectorAll('.http-only').forEach(e => e.style.display = t === 'http' ? '' : 'none');
        $('m_threshold_label').textContent = info.threshold;
        $('m_threshold').placeholder = 'Default ' + info.default;
        $('m_threshold_help').textContent = 'Leave blank to use ' + info.default + '.';
        if (changedType) $('m_category').value = defaultCat[t] || 'network';
    }
    $('m_type').addEventListener('change', () => sync(true));
    $('m_asset_id').addEventListener('change', function () {
        const ip = this.selectedOptions[0]?.dataset.ip;
        if (ip && ['ping', 'tcp'].includes($('m_type').value) && !$('m_target').value) $('m_target').value = ip;
    });

    document.querySelectorAll('[data-monitor]').forEach(btn => btn.addEventListener('click', () => {
        const m = btn.dataset.monitor === 'new' ? null : JSON.parse(btn.dataset.monitor);
        form.reset();
        form.action = m ? updateUrl.replace('__ID__', m.id) : storeUrl;
        $('monMethod').value = m ? 'PUT' : 'POST';
        $('monId').value = m ? m.id : '';
        $('monTitle').textContent = m ? 'Edit ' + m.name : 'Add monitor';
        $('m_active_wrap').style.display = m ? '' : 'none';
        $('monDelete').classList.toggle('d-none', !m);
        if (m) { fields.forEach(k => el(k).value = m[k] ?? ''); $('m_is_active').checked = !!m.is_active; }
        else { $('m_type').value = 'ping'; $('m_severity').value = 'high'; $('m_interval').value = 5; $('m_alert_after').value = 2; $('m_timeout').value = 10; $('m_sort').value = 0; }
        sync(!m);
        modal.show();
    }));
    $('monDelete').addEventListener('click', () => {
        if (!confirm('Remove this monitor and all its history?')) return;
        const f = $('monDeleteForm'); f.action = deleteUrl.replace('__ID__', $('monId').value); f.submit();
    });
    sync(false);
    @if ($errors->any())
        const oldId = $('monId').value;
        if (oldId) { form.action = updateUrl.replace('__ID__', oldId); $('monMethod').value = 'PUT'; $('monTitle').textContent = 'Edit monitor'; $('monDelete').classList.remove('d-none'); }
        else { $('m_active_wrap').style.display = 'none'; }
        modal.show();
    @endif
});
</script>
@endif
@endsection
