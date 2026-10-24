@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Http\Controllers\AuditLogController;
    use App\Support\Audit;
    $q = array_filter(['from' => $from->toDateString(), 'to' => $to->toDateString()] + $filters, fn ($v) => $v !== null && $v !== '');
    $show = function ($v) {
        if (is_null($v) || $v === '') return '—';
        if (is_bool($v)) return $v ? 'Yes' : 'No';
        if (is_array($v)) return implode(', ', array_map(fn ($x) => is_scalar($x) ? $x : json_encode($x), $v));
        return (string) $v;
    };
@endphp
<style>
    .au-row { cursor: pointer; }
    .au-row.flag td:first-child { box-shadow: inset 3px 0 0 #dc2626; }
    .au-ico { width: 30px; height: 30px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: var(--cb-surface-2); color: var(--cb-muted); font-size: 15px; flex-shrink: 0; }
    .au-detail td { background: var(--cb-surface-2) !important; }
    .au-diff { width: 100%; font-size: 12px; }
    .au-diff th, .au-diff td { padding: 4px 8px; border-bottom: 1px solid var(--cb-border); vertical-align: top; word-break: break-word; }
    .au-diff th { color: var(--cb-muted); font-weight: 600; width: 22%; }
    .au-old { color: #b91c1c; text-decoration: line-through; }
    .au-new { color: #15803d; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-shield-check-line"
               :subtitle="($from->isSameDay($to) ? $from->format('l j F Y') : $from->format('j M') . ' – ' . $to->format('j M Y')) . ' · who did what, when, and why'">
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('audit.index', $q + ['export' => 'csv']) }}"><i class="ri-file-excel-2-line"></i> Export CSV</a>
            <a class="cb-hero-btn" href="{{ route('audit.index', ['flagged' => 1, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"><i class="ri-flag-line"></i> Flagged only</a>
        </x-slot:actions>
    </x-cb.hero>

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Entries" :value="number_format((int) $summary->total)" icon="ri-file-list-3-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('audit.index', $q + ['flagged' => 1]) }}"><x-cb.stat label="Flagged for review" :value="number_format((int) $summary->flagged)" icon="ri-flag-line" accent="rose" hint="large voids, owing check-outs, repeated failures" /></a></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Voids & refunds" :value="number_format((int) $summary->voids)" icon="ri-forbid-line" accent="amber" /></div>
        <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('audit.index', ['module' => 'auth', 'q' => 'Failed', 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"><x-cb.stat label="Failed sign-ins" :value="number_format((int) $summary->failed)" icon="ri-lock-password-line" accent="green" /></a></div>
    </div>

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <input type="date" name="from" class="cb-select" value="{{ $from->toDateString() }}" aria-label="From">
            <input type="date" name="to" class="cb-select" value="{{ $to->toDateString() }}" aria-label="To">
            <select name="module" class="cb-select" aria-label="Area" onchange="this.form.submit()">
                <option value="">All areas</option>
                @foreach ($modules as $key => [$label])<option value="{{ $key }}" @selected(($filters['module'] ?? '') === $key)>{{ $label }}</option>@endforeach
            </select>
            <select name="user" class="cb-select" aria-label="User" onchange="this.form.submit()">
                <option value="">Everyone</option>
                @foreach ($users as $u)<option value="{{ $u->id }}" @selected((int) ($filters['user'] ?? 0) === $u->id)>{{ $u->name }}</option>@endforeach
            </select>
            <input type="search" name="q" class="cb-select" placeholder="Search description, record, reason…" value="{{ $filters['q'] ?? '' }}" style="min-width: 220px">
            @if (! empty($filters['type']))<input type="hidden" name="type" value="{{ $filters['type'] }}"><input type="hidden" name="id" value="{{ $filters['id'] ?? '' }}">@endif
            <label class="small d-flex align-items-center gap-1 mb-0"><input type="checkbox" name="flagged" value="1" @checked(! empty($filters['flagged'])) onchange="this.form.submit()"> Flagged</label>
            <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Show</button>
            <a href="{{ route('audit.index', ['from' => today()->toDateString()]) }}" class="action-btn btn-more">Today</a>
            <a href="{{ route('audit.index', ['from' => today()->startOfMonth()->toDateString()]) }}" class="action-btn btn-more">This month</a>
        </form>

        @if (! empty($filters['type']))
            <div class="cb-banner info m-3"><i class="ri-history-line"></i><div>Showing the history of one record. <a href="{{ route('audit.index', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">Show everything</a></div></div>
        @endif

        @if ($logs->isEmpty())
            <div class="empty-state py-5"><i class="ri-shield-check-line"></i><h6>Nothing recorded for these filters</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>When</th><th>Who</th><th>What happened</th><th>Area</th></tr></thead>
                    <tbody>
                        @foreach ($logs as $log)
                            @php $url = AuditLogController::subjectUrl($log); $changes = $log->changes(); @endphp
                            <tr class="au-row {{ $log->flagged ? 'flag' : '' }}" data-bs-toggle="collapse" data-bs-target="#au-{{ $log->id }}" aria-expanded="false">
                                <td data-label="When" class="text-nowrap">{{ $log->created_at->format('j M, g:i:s A') }}
                                    <div class="small text-muted">{{ $log->created_at->diffForHumans() }}</div></td>
                                <td data-label="Who"><span class="fw-semibold">{{ $log->user_name ?? 'Unknown' }}</span>
                                    @if ($log->user_roles)<div class="small text-muted">{{ $log->user_roles }}</div>@endif</td>
                                <td data-label="What happened">
                                    <div class="d-flex gap-2 align-items-start">
                                        <span class="au-ico"><i class="{{ $log->icon() }}"></i></span>
                                        <div>
                                            @if ($log->flagged)<i class="ri-flag-fill text-danger" title="Flagged for review"></i>@endif
                                            {{ $log->description }}
                                            @if ($log->reason)<div class="small text-muted">Reason: {{ $log->reason }}</div>@endif
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Area"><span class="status-pill st-muted">{{ $log->moduleLabel() }}</span>
                                    <div class="small text-muted">{{ $log->actionLabel() }}</div></td>
                            </tr>
                            <tr class="collapse au-detail" id="au-{{ $log->id }}">
                                <td colspan="4">
                                    <div class="row g-3">
                                        <div class="col-lg-8">
                                            @if ($changes)
                                                <table class="au-diff">
                                                    <tr><th>Field</th><th>Before</th><th>After</th></tr>
                                                    @foreach ($changes as $field => [$old, $new])
                                                        <tr><th>{{ str_replace('_', ' ', $field) }}</th>
                                                            <td class="{{ ! is_null($old) && ! is_null($new) ? 'au-old' : '' }}">{{ $show($old) }}</td>
                                                            <td class="{{ ! is_null($new) ? 'au-new' : '' }}">{{ $show($new) }}</td></tr>
                                                    @endforeach
                                                </table>
                                            @else
                                                <div class="small text-muted">No field values recorded for this entry.</div>
                                            @endif
                                        </div>
                                        <div class="col-lg-4 small">
                                            @if ($log->subject_label)<div><span class="text-muted">Record:</span>
                                                @if ($url)<a href="{{ $url }}">{{ $log->subject_label }}</a>@else{{ $log->subject_label }}@endif</div>@endif
                                            @if ($log->subject_id)<div><a href="{{ route('audit.index', ['type' => $log->subject_type, 'id' => $log->subject_id, 'from' => '2000-01-01']) }}"><i class="ri-history-line"></i> Full history of this record</a></div>@endif
                                            <div><span class="text-muted">Time:</span> {{ $log->created_at->format('D j M Y, H:i:s') }}</div>
                                            @if ($log->ip_address)<div><span class="text-muted">IP address:</span> {{ $log->ip_address }}</div>@endif
                                            @if ($log->user_agent)<div class="text-muted text-truncate" title="{{ $log->user_agent }}">{{ $log->user_agent }}</div>@endif
                                            <div class="text-muted">Entry #{{ $log->id }} · {{ $log->action }}</div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $logs->links() }}</div>
        @endif
    </div>
    <div class="small text-muted mt-2">Entries can't be edited or deleted. A flag means "worth a look", not that anything went wrong.</div>

</div>
</div>
</div>
@endsection
