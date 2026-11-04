@extends('layouts.guest', ['title' => 'Spa, gym & pool'])

@section('content')
@php
    $stay = $stay ?? null;
    $first = collect($venues)->first();
    $min = $stay ? $screen['first_day'] : today()->toDateString();
    $max = $stay ? min($screen['last_day'], now()->addDays($days)->toDateString()) : now()->addDays($days)->toDateString();
    $timesUrl = $stay ? route('stay.wellness.times', $stay->guest_token) : route('wellness.times');
@endphp
<style>
    .wp-head { margin: 26px 0 16px; }
    .wp-head h1 { font-size: clamp(28px, 5vw, 42px); margin: 6px 0; line-height: 1.1; }
    .wp-grid { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); gap: 20px; align-items: start; }
    @media (max-width: 860px) { .wp-grid { grid-template-columns: 1fr; } }
    .wp-venue .panel { transition: border-color .2s; margin-bottom: 12px; }
    .wp-venue:has(input[name=venue_id]:checked) .panel { border-color: var(--lagoon); box-shadow: 0 0 0 3px var(--lagoon-l); }
    .wp-venue .vhead { display: flex; gap: 10px; align-items: baseline; cursor: pointer; }
    .wp-venue .vhead input { accent-color: var(--lagoon); }
    .wp-venue h2 { margin: 0 0 4px; font-size: 20px; }
    .wp-svc { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-top: 1px dashed var(--line); font-size: 15px; cursor: pointer; }
    .wp-svc input { margin-right: 8px; }
    .slots { display: flex; flex-wrap: wrap; gap: 8px; }
    .slots label span { display: inline-block; padding: 9px 12px; border: 1.5px solid var(--line); border-radius: 10px; cursor: pointer; font-variant-numeric: tabular-nums; }
    .slots label { position: relative; }
    .slots input { position: absolute; opacity: 0; width: 1px; height: 1px; left: 0; top: 0; }
    .slots input:checked + span { background: var(--lagoon); border-color: var(--lagoon); color: #fff; }
    .slots input:focus-visible + span { outline: 2px solid var(--lagoon-d); outline-offset: 2px; }
    .wp-sum { position: sticky; top: 16px; }
    .wp-total { font-size: 22px; font-weight: 700; margin: 6px 0 12px; }
</style>
<div class="wrap">
    <div class="wp-head">
        @if ($stay)<a href="{{ route('stay.show', $stay->guest_token) }}" class="small" style="color:var(--lagoon);text-decoration:none;font-weight:600"><i class="ri-arrow-left-line"></i> Your booking</a>@endif
        <h1 class="serif">Spa, gym &amp; pool</h1>
        <p class="muted">{{ $stay ? 'Book a treatment or a session during your stay. It goes on your room bill when you arrive.' : 'Book a treatment or a session at ' . config('hotel.name') . ' — you don’t need to be staying with us. Hotel guests can also book from their booking page.' }}</p>
    </div>
    @if (session('success'))<div class="notice ok"><i class="ri-checkbox-circle-line"></i><div>{{ session('success') }}</div></div>@endif
    @if ($stay && count($screen['bookings']))
        <div class="panel">
            <h2 style="margin-top:0;font-size:18px">Your bookings</h2>
            @foreach ($screen['bookings'] as $bk)
                <div style="display:flex;gap:10px;justify-content:space-between;padding:8px 0;border-top:1px solid var(--line)">
                    <div><strong>{{ $bk['what'] }}</strong> <span class="small muted">· {{ $bk['venue'] }} · {{ $bk['number'] }}</span><div class="small">{{ $bk['when'] }} · {{ $bk['total_label'] }}</div>@if ($bk['note'])<div class="small muted">{{ $bk['note'] }}</div>@endif</div>
                    <div style="text-align:right"><span class="pill {{ $bk['upcoming'] ? '' : 'grey' }}">{{ $bk['status_label'] }}</span>
                        @if ($bk['can_cancel'])<form method="POST" action="{{ route('stay.wellness.cancel', [$stay->guest_token, $bk['id']]) }}" onsubmit="return confirm('Cancel this booking?')">@csrf<button class="small" style="background:none;border:0;color:var(--bad);text-decoration:underline;cursor:pointer;padding:0">Cancel</button></form>@endif</div>
                </div>
            @endforeach
        </div>
    @endif
    @if ($stay && ! $screen['available'])
        <div class="notice warn"><i class="ri-information-line"></i><div>{{ $screen['blocker'] }}</div></div>
    @endif
    @if (session('error'))<div class="notice bad"><i class="ri-error-warning-line"></i><div>{{ session('error') }}</div></div>@endif
    @if ($errors->any())<div class="notice bad"><i class="ri-error-warning-line"></i><div>{{ $errors->first() }}</div></div>@endif

    @if ($stay && ! $screen['available'])
    @elseif (! $first)
        <div class="panel"><p style="margin:0">Online booking isn’t open right now. Please call us{{ config('hotel.booking.contact_phone') ? ' on ' . config('hotel.booking.contact_phone') : '' }}.</p></div>
    @else
    <form method="POST" action="{{ $stay ? route('stay.wellness.book', $stay->guest_token) : route('wellness.book') }}" id="wpForm" class="wp-grid">@csrf
        <div>
            @foreach ($venues as $v)
                <div class="wp-venue">
                    <div class="panel">
                        <label class="vhead"><input type="radio" name="venue_id" value="{{ $v['id'] }}" @checked(old('venue_id', $first['id']) == $v['id'])><h2 class="serif">{{ $v['name'] }}</h2></label>
                        <div class="small muted">{{ $v['hours'] }}{{ $v['session_minutes'] ? ' · sessions of ' . $v['session_minutes'] . ' min · ' . $v['price_label'] : '' }}</div>
                        @if ($v['description'])<p class="small" style="margin:6px 0 0">{{ $v['description'] }}</p>@endif
                        @if ($v['treatments'])
                            <div style="margin-top:8px">
                                @foreach ($v['services'] as $i => $s)
                                    <label class="wp-svc"><span><input type="radio" name="service_id" value="{{ $s['id'] }}" data-venue="{{ $v['id'] }}" @checked(old('service_id') == $s['id'])>{{ $s['name'] }} <span class="muted small">· {{ $s['minutes'] }} min</span></span><strong>{{ $s['price_label'] }}</strong></label>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="panel wp-sum">
            <div class="field"><label for="wpDate">Day</label><input id="wpDate" type="date" name="date" min="{{ $min }}" max="{{ $max }}" value="{{ old('date', $min) }}" required></div>
            <div class="field" id="wpPeopleBox"><label for="wpPeople">People</label><input id="wpPeople" type="number" name="people" min="1" max="10" value="{{ old('people', 1) }}"></div>
            <div class="field"><label>Time</label><div class="slots" id="wpSlots"><span class="small muted">Choose a treatment and a day.</span></div></div>
            <div class="wp-total" id="wpTotal"></div>
            @unless ($stay)
            <div class="field"><label for="wpName">Your name</label><input id="wpName" name="name" maxlength="120" required value="{{ old('name') }}" autocomplete="name"></div>
            <div class="field"><label for="wpEmail">E-mail</label><input id="wpEmail" type="email" name="email" maxlength="150" required value="{{ old('email') }}" autocomplete="email"></div>
            <div class="field"><label for="wpPhone">Phone</label><input id="wpPhone" type="tel" name="phone" maxlength="30" required value="{{ old('phone') }}" autocomplete="tel"></div>
            @endunless
            <div class="field"><label for="wpNote">Anything we should know? (optional)</label><input id="wpNote" name="note" maxlength="300" value="{{ old('note') }}"></div>
            <button class="btn" type="submit" id="wpBook" disabled style="width:100%">Book</button>
            <p class="small muted">{{ $stay ? 'Added to your room bill when you arrive.' : 'You pay online to confirm (or on arrival if online payment is off).' }} Bookings can be cancelled online up to {{ (int) config('hotel.wellness.cancel_hours', 2) }} hours before.</p>
        </div>
    </form>
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    const f = document.getElementById('wpForm'); if (!f) return;
    const venues = @json(collect($venues)->keyBy('id')), url = @json($timesUrl), oldTime = @json(old('time'));
    const slots = document.getElementById('wpSlots'), total = document.getElementById('wpTotal'), book = document.getElementById('wpBook');
    const venue = () => venues[f.querySelector('input[name=venue_id]:checked').value];
    async function load() {
        const v = venue(); book.disabled = true;
        document.getElementById('wpPeopleBox').style.display = v.treatments ? 'none' : '';
        f.querySelectorAll('input[name=service_id]').forEach(i => { if (String(i.dataset.venue) !== String(v.id)) i.checked = false; });
        const s = f.querySelector('input[name=service_id]:checked');
        if (v.treatments && !s) { slots.innerHTML = '<span class="small muted">Choose a treatment.</span>'; total.textContent = ''; return; }
        slots.innerHTML = '<span class="small muted">Looking…</span>';
        const q = new URLSearchParams({ venue_id: v.id, date: f.date.value, people: f.people.value || 1 });
        if (s) q.set('service_id', s.value);
        try {
            const d = await (await fetch(url + '?' + q, { headers: { Accept: 'application/json' } })).json();
            total.textContent = d.total;
            slots.innerHTML = d.slots.length ? d.slots.map(x => `<label><input type="radio" name="time" value="${x.time}" ${x.time === oldTime ? 'checked' : ''}><span>${x.label}</span></label>`).join('') : '<span class="small muted">Nothing free that day — try another.</span>';
            book.disabled = !f.querySelector('input[name=time]:checked');
        } catch (e) { slots.innerHTML = '<span class="small muted">Couldn’t load the times. Try again.</span>'; }
    }
    f.addEventListener('change', e => {
        if (e.target.name === 'time') { book.disabled = false; return; }
        if (e.target.name === 'service_id') { const r = f.querySelector(`input[name=venue_id][value="${e.target.dataset.venue}"]`); if (r) r.checked = true; }
        if (['venue_id', 'service_id', 'date', 'people'].includes(e.target.name)) load(); });
    f.addEventListener('submit', () => { book.disabled = true; });
    load();
})();
</script>
@endpush
