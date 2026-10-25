@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $sym = config('hotel.currency_symbol'); $fmt = fn ($v) => $sym . number_format((float) $v, 2); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="Night Audit" icon="ri-moon-clear-line" :subtitle="'Business day: ' . $date->format('l j F Y')">
        <x-slot:pills>
            @if ($date->lt(today()))<span class="cb-meta-pill" style="background:#b45309;border-color:#b45309"><i class="ri-alarm-warning-line"></i> {{ $date->diffInDays(today()) }} day(s) behind</span>@endif
            @if (config('hotel.night_audit.auto_close'))<span class="cb-meta-pill"><i class="ri-timer-line"></i> Closes by itself at {{ config('hotel.night_audit.auto_close_at') }}</span>@endif
        </x-slot:pills>
    </x-cb.hero>

    @include('rooms.partials.flash')

    @if (! $canClose)
        <div class="cb-banner info"><i class="ri-moon-line"></i><div>Today is already closed. The next business day, {{ $date->format('D j M') }}, can be closed from tomorrow.</div></div>
    @else
        @can('Run night audit')
        <form method="POST" action="{{ route('night-audit.run') }}" class="rm-form" onsubmit="return confirm('Close {{ $date->format('D j M Y') }}? Room charges are posted and the day is locked.')">
            @csrf
            <input type="hidden" name="date" value="{{ $date->toDateString() }}">
            <div class="row g-3 mb-3">
                <div class="col-xl-8">
                    <x-cb.card title="Before closing {{ $date->format('D j M') }}" icon="ri-list-check-3">
                        <div class="small fw-bold mb-1" style="color:var(--cb-heading)"><i class="ri-login-box-line"></i> Arrivals who haven't checked in ({{ $checks['arrivals']->count() }})</div>
                        @forelse ($checks['arrivals'] as $a)
                            <label class="d-flex gap-2 align-items-start small py-1 border-bottom">
                                <input type="checkbox" class="form-check-input mt-1" name="no_shows[]" value="{{ $a->id }}" checked>
                                <span class="flex-grow-1"><a href="{{ route('reservations.show', $a) }}" target="_blank">{{ $a->guest?->full_name }}</a> · {{ $a->reference }} · due {{ $a->check_in_date->format('D j M') }}{{ $a->rooms->pluck('room.number')->filter()->isNotEmpty() ? ' · room ' . $a->rooms->pluck('room.number')->filter()->implode(', ') : '' }}
                                    <span class="text-muted">— {{ $a->status->label() }}</span></span>
                                <span class="text-muted">Mark no-show</span>
                            </label>
                        @empty
                            <div class="small text-muted mb-2">Everyone due has arrived.</div>
                        @endforelse
                        @if ($checks['arrivals']->isNotEmpty())<div class="small text-muted mt-1 mb-3">Ticked bookings are marked as no-shows and their rooms released. Untick anyone still expected tonight (e.g. a late flight).</div>@endif

                        <div class="small fw-bold mt-3 mb-1" style="color:var(--cb-heading)"><i class="ri-logout-box-r-line"></i> Guests who should have left ({{ $checks['overstays']->count() }})</div>
                        @forelse ($checks['overstays'] as $o)
                            <div class="small py-1 border-bottom"><a href="{{ route('reservations.show', $o) }}" target="_blank">{{ $o->guest?->full_name }}</a> · room {{ $o->rooms->pluck('room.number')->filter()->implode(', ') }} · was due out {{ $o->check_out_date->format('D j M') }} <span class="text-warning-emphasis">— check them out, or extend the stay so tonight is billed.</span></div>
                        @empty
                            <div class="small text-muted">None.</div>
                        @endforelse

                        <div class="small fw-bold mt-3 mb-1" style="color:var(--cb-heading)"><i class="ri-safe-2-line"></i> Tills still open ({{ $checks['shifts']->count() }})</div>
                        @forelse ($checks['shifts'] as $s)
                            <div class="small py-1">{{ $s->user?->name }} · {{ $s->station }} · since {{ $s->opened_at->format('D g:i A') }}</div>
                        @empty
                            <div class="small text-muted">None.</div>
                        @endforelse
                        @if ($checks['pos'] > 0)<div class="cb-banner warning mt-3 mb-0"><i class="ri-error-warning-line"></i><div>{{ $checks['pos'] }} bar/restaurant sale(s) need attention. <a href="{{ route('pos.orders', ['status' => 'attention']) }}">Sort them out</a> so they count in the right day.</div></div>@endif
                        @if ($checks['dirty'] > 0)<div class="small text-muted mt-3"><i class="ri-brush-line"></i> {{ $checks['dirty'] }} room(s) still need cleaning.</div>@endif
                    </x-cb.card>
                </div>
                <div class="col-xl-4">
                    <x-cb.card title="Close the day" icon="ri-lock-line">
                        <ol class="small ps-3">
                            <li>Ticked arrivals become no-shows.</li>
                            <li>Tonight's room is charged to every guest in house.</li>
                            <li>The day's report is saved{{ config('hotel.night_audit.report_emails') ? ' and emailed' : '' }}.</li>
                            <li>The day is locked: charges and payments dated {{ $date->format('j M') }} can then only be voided by a manager.</li>
                        </ol>
                        <label for="notes" class="small">Notes for the day (optional)</label>
                        <textarea name="notes" id="notes" class="form-control mb-3" rows="3" maxlength="1000" placeholder="e.g. Generator down 2–4 pm; two rooms comped for the noise."></textarea>
                        <button class="btn btn-primary w-100 py-2 fw-bold"><i class="ri-moon-clear-line me-1"></i> Close {{ $date->format('D j M') }}</button>
                    </x-cb.card>
                </div>
            </div>
        </form>
        @endcan

        <h5 class="fw-bold mt-2 mb-3" style="color:var(--cb-heading)">{{ $date->format('D j M') }} so far <span class="small text-muted fw-normal">(tonight's rooms counted at their booked rates)</span></h5>
        @include('night-audit._report', ['r' => $preview])
    @endif

    <x-cb.card title="Closed days" icon="ri-history-line" :flush="true">
        <div class="table-responsive">
            <table class="cb-table stack">
                <thead><tr><th>Day</th><th class="num">Occupancy</th><th class="num">Average rate</th><th class="num">Revenue</th><th class="num">Money in</th><th>Closed</th></tr></thead>
                <tbody>
                    @forelse ($history as $h)
                        <tr>
                            <td data-label="Day"><a href="{{ route('night-audit.show', $h) }}" class="fw-semibold">{{ $h->business_date->format('D j M Y') }}</a></td>
                            <td data-label="Occupancy" class="num">{{ $h->report['rooms']['occupancy'] }}%</td>
                            <td data-label="Average rate" class="num">{{ $fmt($h->report['rooms']['adr']) }}</td>
                            <td data-label="Revenue" class="num">{{ $fmt($h->report['revenue']['total']) }}</td>
                            <td data-label="Money in" class="num">{{ $fmt($h->report['collections']['total']) }}</td>
                            <td data-label="Closed" class="small">{{ $h->automatic ? 'Automatically' : ($h->closer?->name ?? '—') }}, {{ $h->closed_at->format('D j M g:i A') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">No days closed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($history->hasPages())<div class="p-3">{{ $history->links() }}</div>@endif
    </x-cb.card>

</div>
</div>
</div>
@endsection
