@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $prev = $day->copy()->subDay()->toDateString(); $next = $day->copy()->addDay()->toDateString();
    $venueJson = $venues->map(fn ($v) => ['id' => $v->id, 'treatments' => $v->hasTreatments(), 'max' => $v->hasTreatments() ? 1 : min(\App\Services\Wellness\WellnessBookings::MAX_PEOPLE, $v->capacity),
        'services' => $v->services->map(fn ($s) => ['id' => $s->id, 'name' => $s->name . ' · ' . $s->minutes . ' min · ' . $sym . number_format((float) $s->price, 0)])->values()])->values();
@endphp
<style>
    .wl-stats { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
    .wl-stat { background: var(--cb-surface, #fff); border: 1px solid var(--cb-border); border-radius: 12px; padding: 10px 14px; min-width: 130px; }
    .wl-stat b { font-size: 20px; display: block; font-variant-numeric: tabular-nums; }
    .wl-day { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
    .wl-day h5 { margin: 0 8px; }
    .wl-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 16px; align-items: start; }
    @media (max-width: 1100px) { .wl-grid { grid-template-columns: 1fr; } }
    .wl-venue { margin-bottom: 16px; }
    .wl-row { display: grid; grid-template-columns: 92px minmax(0, 1fr) auto; gap: 12px; padding: 12px 16px; border-top: 1px solid var(--cb-border); align-items: center; }
    .wl-row:first-child { border-top: 0; }
    .wl-time { font-weight: 800; font-size: 17px; font-variant-numeric: tabular-nums; }
    .wl-time small { display: block; font-weight: 500; font-size: 12px; color: #64748b; }
    .wl-row.st-arrived { background: rgba(79,70,229,.05); }
    .wl-row.st-cancelled, .wl-row.st-no_show, .wl-row.st-completed { opacity: .62; }
    .wl-acts { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
    .wl-acts form { display: flex; gap: 6px; }
    .wl-cancel input { width: 150px; }
    .wl-slots { display: flex; flex-wrap: wrap; gap: 6px; }
    .wl-slots label { cursor: pointer; }
    .wl-slots input { display: none; }
    .wl-slots span { display: inline-block; padding: 6px 10px; border: 1px solid var(--cb-border); border-radius: 8px; font-size: 13px; font-variant-numeric: tabular-nums; }
    .wl-slots input:checked + span { background: #0e7490; border-color: #0e7490; color: #fff; }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-leaf-line" subtitle="The day's treatments and sessions. Guests book from the guest app and their booking page, visitors on the website. Marking an in-house guest as arrived puts it on their room bill.">
        <x-slot:actions>
            @can('Set up wellness')<a class="cb-hero-btn" href="{{ route('wellness.setup') }}"><i class="ri-settings-3-line"></i> Places, treatments &amp; therapists</a>@endcan
            <a class="cb-hero-btn" href="{{ route('wellness.public') }}" target="_blank" rel="noopener"><i class="ri-global-line"></i> Booking page</a>
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($errors->any())<div class="cb-banner danger"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div>@endif
    @if ($venues->isEmpty())
        <div class="cb-banner info"><i class="ri-information-line"></i><div>No spa, gym or pool is set up yet. @can('Set up wellness')<a href="{{ route('wellness.setup') }}">Add them here</a>.@endcan</div></div>
    @endif

    <div class="wl-day">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('wellness.index', ['date' => $prev]) }}" aria-label="Day before"><i class="ri-arrow-left-s-line"></i></a>
        <h5>{{ $day->isToday() ? 'Today' : ($day->isTomorrow() ? 'Tomorrow' : '') }} {{ $day->format('l j F') }}</h5>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('wellness.index', ['date' => $next]) }}" aria-label="Day after"><i class="ri-arrow-right-s-line"></i></a>
        @unless ($day->isToday())<a class="btn btn-sm btn-link" href="{{ route('wellness.index') }}">Today</a>@endunless
        <form method="GET" class="ms-auto"><input type="date" name="date" class="form-control form-control-sm" value="{{ $day->toDateString() }}" onchange="this.form.submit()" aria-label="Go to a day"></form>
    </div>

    <div class="wl-stats">
        <div class="wl-stat"><span class="small text-muted">Still to come</span><b>{{ $counts['booked'] }}</b></div>
        <div class="wl-stat"><span class="small text-muted">Here now</span><b>{{ $counts['arrived'] }}</b></div>
        <div class="wl-stat"><span class="small text-muted">Taken (arrived)</span><b>{{ $sym }}{{ number_format($counts['takings'], 0) }}</b></div>
    </div>

    <div class="wl-grid">
        <div>
            @foreach ($venues as $v)
                @php $list = $bookings->get($v->id, collect()); @endphp
                <x-cb.card :title="$v->name . ' · ' . $v->hours_label" :icon="$v->icon" :flush="true" class="wl-venue">
                    @forelse ($list as $b)
                        <div class="wl-row st-{{ $b->status }}">
                            <div class="wl-time">{{ $b->starts_at->format('g:i') }}<small>{{ $b->starts_at->format('A') }} – {{ $b->ends_at->format('g:i A') }}</small></div>
                            <div>
                                <strong>{{ $b->guest_name }}</strong>@if ($b->reservation?->guest?->is_vip) <span class="status-pill st-warning">VIP</span>@endif
                                <span class="status-pill {{ $b->status_pill }}">{{ $b->status_label }}</span>
                                <div class="small">{{ $b->what }}{{ $b->therapist ? ' · with ' . $b->therapist->name : '' }}</div>
                                <div class="small text-muted">{{ $b->number }} · {{ $b->reservation_id ? 'Room guest' : 'Visitor' }} · {{ (float) $b->total > 0 ? $sym . number_format((float) $b->total, 2) . ' · ' . $b->payment_label : 'Free' }}{{ $b->phone && ! $b->reservation_id ? ' · ' . $b->phone : '' }}</div>
                                @if ($b->note)<div class="small" style="color:#b45309;font-weight:600">“{{ $b->note }}”</div>@endif
                                @if ($b->staff_note)<div class="small text-muted">{{ $b->staff_note }}</div>@endif
                            </div>
                            @if ($canManage)
                                <div class="wl-acts">
                                    @if ($b->status === 'booked')
                                        <form method="POST" action="{{ route('wellness.act', [$b, 'arrive']) }}" data-once>@csrf<button class="action-btn btn-go"><i class="ri-login-circle-line"></i> Arrived</button></form>
                                        @if ($b->starts_at->isPast())<form method="POST" action="{{ route('wellness.act', [$b, 'no_show']) }}" data-once>@csrf<button class="action-btn btn-more">Didn’t come</button></form>@endif
                                    @endif
                                    @if ($b->status === 'arrived')
                                        <form method="POST" action="{{ route('wellness.act', [$b, 'complete']) }}" data-once>@csrf<button class="action-btn btn-go"><i class="ri-check-line"></i> Done</button></form>
                                    @endif
                                    @if (in_array($b->status, ['booked', 'pending'], true))
                                        <form method="POST" action="{{ route('wellness.act', [$b, 'cancel']) }}" data-once class="wl-cancel">@csrf
                                            <input name="note" class="form-control form-control-sm" maxlength="255" required placeholder="Reason, to cancel"><button class="action-btn btn-more">Cancel</button></form>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="small text-muted p-3">Nothing booked.</div>
                    @endforelse
                </x-cb.card>
            @endforeach
        </div>

        @if ($canManage && $venues->isNotEmpty())
        <x-cb.card title="Book" icon="ri-calendar-check-line">
            <form method="POST" action="{{ route('wellness.store') }}" id="wlForm" class="row g-2">@csrf
                <div class="col-12"><label class="form-label small">Where</label>
                    <select name="venue_id" class="form-select" required>@foreach ($venues as $v)<option value="{{ $v->id }}" @selected(old('venue_id') == $v->id)>{{ $v->name }}</option>@endforeach</select></div>
                <div class="col-12" id="wlSvcBox"><label class="form-label small">Treatment</label><select name="service_id" class="form-select"></select></div>
                <div class="col-7"><label class="form-label small">Day</label><input type="date" name="date" class="form-control" value="{{ old('date', $day->toDateString()) }}" min="{{ today()->toDateString() }}" required></div>
                <div class="col-5" id="wlPeopleBox"><label class="form-label small">People</label><input type="number" name="people" class="form-control" min="1" value="{{ old('people', 1) }}"></div>
                <div class="col-12"><label class="form-label small">Time</label><div class="wl-slots" id="wlSlots"><span class="small text-muted">Choose where and when.</span></div></div>
                <div class="col-12"><label class="form-label small">For</label>
                    <select name="reservation_id" class="form-select"><option value="">A visitor (pays on arrival)</option>@foreach ($inHouse as $r)<option value="{{ $r['id'] }}" @selected(old('reservation_id') == $r['id'])>{{ $r['label'] }}</option>@endforeach</select></div>
                <div class="col-7 wl-visitor"><input name="name" class="form-control" maxlength="120" placeholder="Visitor's name" value="{{ old('name') }}"></div>
                <div class="col-5 wl-visitor"><input name="phone" class="form-control" maxlength="30" placeholder="Phone" value="{{ old('phone') }}"></div>
                <div class="col-12"><input name="note" class="form-control" maxlength="300" placeholder="Note (optional)" value="{{ old('note') }}"></div>
                <div class="col-12"><button class="btn btn-primary w-100" id="wlBook" disabled>Book</button></div>
            </form>
        </x-cb.card>
        @endif
    </div>
</div>
</div>
</div>
@if ($canManage && $venues->isNotEmpty())
<script>
(function () {
    const f = document.getElementById('wlForm'), venues = @json($venueJson), url = @json(route('wellness.slots')), oldTime = @json(old('time'));
    const svc = f.service_id, slots = document.getElementById('wlSlots'), book = document.getElementById('wlBook');
    const venue = () => venues.find(v => String(v.id) === f.venue_id.value);
    function paintVenue() {
        const v = venue();
        document.getElementById('wlSvcBox').style.display = v.treatments ? '' : 'none';
        document.getElementById('wlPeopleBox').style.display = v.treatments ? 'none' : '';
        f.people.max = v.max;
        svc.innerHTML = v.services.map(s => `<option value="${s.id}">${s.name.replace(/</g, '&lt;')}</option>`).join('');
        load();
    }
    async function load() {
        const v = venue(); book.disabled = true;
        slots.innerHTML = '<span class="small text-muted">Looking…</span>';
        const q = new URLSearchParams({ venue_id: v.id, date: f.date.value, people: f.people.value || 1 });
        if (v.treatments && svc.value) q.set('service_id', svc.value);
        try {
            const res = await fetch(url + '?' + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const d = await res.json();
            slots.innerHTML = d.slots.length ? d.slots.map(s => `<label><input type="radio" name="time" value="${s.time}" ${s.time === oldTime ? 'checked' : ''}><span>${s.label}${v.treatments ? '' : ' · ' + s.left + ' free'}</span></label>`).join('')
                : '<span class="small text-muted">Nothing free that day.</span>';
            book.disabled = !f.querySelector('input[name=time]:checked');
        } catch (e) { slots.innerHTML = '<span class="small text-danger">Couldn’t load the times.</span>'; }
    }
    const visitor = () => f.querySelectorAll('.wl-visitor').forEach(el => el.style.display = f.reservation_id.value ? 'none' : '');
    f.venue_id.addEventListener('change', paintVenue);
    [svc, f.date, f.people].forEach(el => el.addEventListener('change', load));
    slots.addEventListener('change', () => book.disabled = false);
    f.reservation_id.addEventListener('change', visitor);
    f.addEventListener('submit', e => { if (e.submitter) e.submitter.disabled = true; });
    visitor(); paintVenue();
})();
</script>
@endif
@endsection
