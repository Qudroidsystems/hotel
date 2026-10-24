@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Enums\BookingSource;
    $oldGuest = old('guest_id') ? \App\Models\Guest::find(old('guest_id')) : $guest;
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero title="New Reservation" icon="ri-calendar-check-line"
               subtitle="Walk-in, telephone or email booking. Availability and prices update as you choose dates and rooms."
               :back="route('reservations.index')" back-label="Front Desk" />

    @include('rooms.partials.flash')

    <form method="POST" action="{{ route('reservations.store') }}" id="bookingForm" class="rm-form" novalidate>
        @csrf
        <div class="row g-3">
            <div class="col-xl-8">

                {{-- 1. Dates --}}
                <x-cb.card>
                    <div class="rs-step">Step 1</div>
                    <h5 class="fw-bold mb-3" style="color: var(--cb-heading)">Dates</h5>
                    <div class="row g-3 align-items-end">
                        <div class="col-sm-4">
                            <label for="check_in_date">Check-in <span class="text-danger">*</span></label>
                            <input type="date" name="check_in_date" id="check_in_date" class="form-control" required min="{{ today()->toDateString() }}" value="{{ old('check_in_date', $in) }}">
                        </div>
                        <div class="col-sm-4">
                            <label for="check_out_date">Check-out <span class="text-danger">*</span></label>
                            <input type="date" name="check_out_date" id="check_out_date" class="form-control" required value="{{ old('check_out_date', $out) }}">
                        </div>
                        <div class="col-sm-4">
                            <div class="d-flex gap-1 flex-wrap" aria-label="Quick lengths">
                                @foreach ([1, 2, 3, 7] as $n)
                                    <button type="button" class="term-chip" data-nights="{{ $n }}">{{ $n }} night{{ $n > 1 ? 's' : '' }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </x-cb.card>

                {{-- 2. Rooms --}}
                <x-cb.card>
                    <div class="rs-step">Step 2</div>
                    <h5 class="fw-bold mb-1" style="color: var(--cb-heading)">Rooms</h5>
                    <p class="small text-muted mb-3">Choose how many rooms of each type. You can pick the exact room now or assign it later.</p>
                    <div id="typeList"><div class="text-muted small py-3"><span class="spinner-border spinner-border-sm me-2"></span>Checking availability…</div></div>
                    <div id="lineList" class="mt-3"></div>
                </x-cb.card>

                {{-- 3. Guest --}}
                <x-cb.card>
                    <div class="rs-step">Step 3</div>
                    <h5 class="fw-bold mb-3" style="color: var(--cb-heading)">Guest</h5>
                    <input type="hidden" name="guest_id" id="guest_id" value="{{ $oldGuest?->id }}">

                    <div id="guestChosen" class="cb-banner info align-items-center {{ $oldGuest ? '' : 'd-none' }}">
                        <i class="ri-user-star-line"></i>
                        <div class="flex-grow-1"><strong id="guestChosenName">{{ $oldGuest?->full_name }}</strong>
                            <div class="small" id="guestChosenMeta">{{ $oldGuest?->phone }} {{ $oldGuest?->email ? '· ' . $oldGuest->email : '' }}</div></div>
                        <button type="button" class="action-btn btn-more" id="guestChange"><i class="ri-close-line"></i> Change</button>
                    </div>

                    <div id="guestPicker" class="{{ $oldGuest ? 'd-none' : '' }}">
                        @can('View guests')
                        <div class="position-relative mb-3">
                            <label for="guestSearch">Returning guest? Search by name, phone or email</label>
                            <input type="text" id="guestSearch" class="form-control" autocomplete="off" placeholder="Start typing…">
                            <div id="guestHits" class="border rounded-3 mt-1 bg-white shadow-sm d-none" style="position:absolute; z-index:20; width:100%; max-height:280px; overflow:auto;"></div>
                        </div>
                        <div class="small fw-bold text-muted text-uppercase mb-2">Or enter a new guest</div>
                        @endcan
                        <div class="row g-3">
                            <div class="col-md-6"><label>First name <span class="text-danger">*</span></label><input type="text" name="guest[first_name]" class="form-control" maxlength="100" value="{{ old('guest.first_name') }}"></div>
                            <div class="col-md-6"><label>Last name <span class="text-danger">*</span></label><input type="text" name="guest[last_name]" class="form-control" maxlength="100" value="{{ old('guest.last_name') }}"></div>
                            <div class="col-md-6"><label>Phone <span class="text-danger">*</span></label><input type="tel" name="guest[phone]" class="form-control" maxlength="30" value="{{ old('guest.phone') }}" placeholder="+234…"></div>
                            <div class="col-md-6"><label>Email</label><input type="email" name="guest[email]" class="form-control" maxlength="255" value="{{ old('guest.email') }}"></div>
                            <div class="col-md-4"><label>Nationality</label><input type="text" name="guest[nationality]" class="form-control" maxlength="100" value="{{ old('guest.nationality', 'Nigerian') }}"></div>
                            <div class="col-md-4"><label>ID type</label>
                                <select name="guest[id_type]" class="form-select"><option value="">—</option>
                                    @foreach ($idTypes as $t)<option @selected(old('guest.id_type') === $t)>{{ $t }}</option>@endforeach
                                </select></div>
                            <div class="col-md-4"><label>ID number</label><input type="text" name="guest[id_number]" class="form-control" maxlength="100" value="{{ old('guest.id_number') }}"></div>
                            <div class="col-md-6"><label>Company</label><input type="text" name="guest[company]" class="form-control" maxlength="150" value="{{ old('guest.company') }}"></div>
                            <div class="col-md-6"><label>Address</label><input type="text" name="guest[address]" class="form-control" maxlength="500" value="{{ old('guest.address') }}"></div>
                        </div>
                    </div>
                </x-cb.card>

                {{-- 4. Details --}}
                <x-cb.card>
                    <div class="rs-step">Step 4</div>
                    <h5 class="fw-bold mb-3" style="color: var(--cb-heading)">Booking details</h5>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="source">Source <span class="text-danger">*</span></label>
                            <select name="source" id="source" class="form-select" required>
                                @foreach (BookingSource::options() as $v => $l)<option value="{{ $v }}" @selected(old('source', 'walk_in') === $v)>{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="booking_status">Status</label>
                            <select name="status" id="booking_status" class="form-select">
                                <option value="confirmed" @selected(old('status', 'confirmed') === 'confirmed')>Confirmed</option>
                                <option value="pending" @selected(old('status') === 'pending')>Pending (awaiting deposit)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="expected_arrival_time">Expected arrival</label>
                            <input type="time" name="expected_arrival_time" id="expected_arrival_time" class="form-control" value="{{ old('expected_arrival_time') }}">
                        </div>
                        <div class="col-md-6">
                            <label for="special_requests">Special requests</label>
                            <textarea name="special_requests" id="special_requests" class="form-control" rows="3" maxlength="2000" placeholder="Late arrival, extra pillows, airport pickup…">{{ old('special_requests') }}</textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="notes">Internal notes</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3" maxlength="2000" placeholder="Only staff see this.">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                </x-cb.card>
            </div>

            {{-- Summary --}}
            <div class="col-xl-4">
                <div class="rs-summary">
                    <x-cb.card title="Summary" icon="ri-file-list-3-line">
                        <div class="d-flex justify-content-between small mb-1"><span class="text-muted">Stay</span><strong id="sumStay">—</strong></div>
                        <div class="d-flex justify-content-between small mb-3"><span class="text-muted">Rooms</span><strong id="sumRooms">0</strong></div>
                        <div id="sumLines" class="small mb-3"></div>
                        <div class="d-flex justify-content-between align-items-end border-top pt-3">
                            <span class="text-muted fw-semibold">Total</span>
                            <span class="rs-total" id="sumTotal">{{ config('hotel.currency_symbol') }}0.00</span>
                        </div>
                        <div class="small text-muted mt-1">Room charges only. Discounts and payments are added on the booking.</div>
                        <div id="formError" class="cb-banner danger mt-3 mb-0 d-none"><i class="ri-error-warning-line"></i><div></div></div>
                        <button type="submit" class="btn btn-primary w-100 mt-3 py-2 fw-bold" id="submitBtn"><i class="ri-check-line"></i> Create reservation</button>
                    </x-cb.card>
                </div>
            </div>
        </div>
        <div id="lineInputs"></div>
    </form>

</div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const AVAIL_URL = @json(route('reservations.availability'));
    const GUEST_URL = @json(route('guests.search'));
    const SYM = @json(config('hotel.currency_symbol'));
    const OLD_ROOMS = @json(array_values(old('rooms', [])));
    const money = n => SYM + Number(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const inEl = document.getElementById('check_in_date'), outEl = document.getElementById('check_out_date');
    const typeList = document.getElementById('typeList'), lineList = document.getElementById('lineList');
    let data = null;       // availability response
    let lines = [];        // [{typeId, roomId, adults, children}]
    let restored = false;

    // ── Dates ───────────────────────────────────────────────
    // Local-date maths (toISOString() would shift to UTC and lose a day east of Greenwich).
    function addDays(dateStr, n) {
        const [y, m, d] = dateStr.split('-').map(Number);
        const dt = new Date(y, m - 1, d + n);
        return dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
    }
    document.querySelectorAll('[data-nights]').forEach(b => b.addEventListener('click', () => {
        if (!inEl.value) return; outEl.value = addDays(inEl.value, +b.dataset.nights); load();
    }));
    inEl.addEventListener('change', () => { if (!outEl.value || outEl.value <= inEl.value) outEl.value = addDays(inEl.value, 1); outEl.min = addDays(inEl.value, 1); load(); });
    outEl.addEventListener('change', load);

    async function load() {
        if (!inEl.value || !outEl.value || outEl.value <= inEl.value) { typeList.innerHTML = '<div class="cb-banner warning mb-0"><i class="ri-calendar-line"></i><div>Pick a check-out date after check-in.</div></div>'; return; }
        typeList.innerHTML = '<div class="text-muted small py-3"><span class="spinner-border spinner-border-sm me-2"></span>Checking availability…</div>';
        try {
            const res = await fetch(AVAIL_URL + '?in=' + inEl.value + '&out=' + outEl.value, { headers: { Accept: 'application/json' } });
            const json = await res.json();
            if (!res.ok) throw new Error(json.message || 'Could not check availability.');
            data = json;
        } catch (e) { typeList.innerHTML = '<div class="cb-banner danger mb-0"><i class="ri-error-warning-line"></i><div>' + esc(e.message) + '</div></div>'; return; }

        if (!restored && OLD_ROOMS.length) {
            lines = OLD_ROOMS.map(r => ({ typeId: +r.room_type_id, roomId: r.room_id ? +r.room_id : null, adults: +r.adults || 1, children: +r.children || 0 }));
            restored = true;
        }
        // Drop lines that no longer fit the new dates.
        lines = lines.filter(l => typeById(l.typeId));
        data.types.forEach(t => { const mine = lines.filter(l => l.typeId === t.id); mine.slice(t.available).forEach(x => lines.splice(lines.indexOf(x), 1)); });
        lines.forEach(l => { if (l.roomId && !typeById(l.typeId).rooms.some(r => r.id === l.roomId)) l.roomId = null; });
        render();
    }

    const typeById = id => data?.types.find(t => t.id === id);

    // ── Rendering ───────────────────────────────────────────
    function render() {
        if (!data.types.length) { typeList.innerHTML = '<div class="cb-banner warning mb-0"><i class="ri-information-line"></i><div>No room types are set up yet.</div></div>'; return; }
        typeList.innerHTML = data.types.map(t => {
            const n = lines.filter(l => l.typeId === t.id).length;
            const avg = data.nights ? t.amount / data.nights : 0;
            return `<div class="rs-type-row ${t.available ? '' : 'soldout'}">
                <div>
                    <div class="nm"><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${esc(t.color)}"></span> ${esc(t.name)}
                        <span class="small text-muted fw-semibold ms-1">${esc(t.capacity)}</span></div>
                    <div class="small mt-1"><strong>${money(t.amount)}</strong> <span class="text-muted">for ${data.nights} night${data.nights === 1 ? '' : 's'} · avg ${money(avg)}/night</span></div>
                    <div class="small ${t.available ? 'text-success' : 'text-danger'} fw-semibold">${t.available ? t.available + ' of ' + t.total + ' available' : 'Sold out for these dates'}</div>
                </div>
                <div class="rs-qty">
                    <button type="button" data-minus="${t.id}" ${n ? '' : 'disabled'} aria-label="Remove a ${esc(t.name)} room">−</button>
                    <span>${n}</span>
                    <button type="button" data-plus="${t.id}" ${n < t.available ? '' : 'disabled'} aria-label="Add a ${esc(t.name)} room">+</button>
                </div></div>`;
        }).join('');

        const chosen = lines.map(l => l.roomId).filter(Boolean);
        lineList.innerHTML = lines.map((l, i) => {
            const t = typeById(l.typeId);
            const opts = t.rooms.filter(r => r.id === l.roomId || !chosen.includes(r.id))
                .map(r => `<option value="${r.id}" ${r.id === l.roomId ? 'selected' : ''}>Room ${esc(r.number)} · ${esc(r.floor)}${r.ready ? '' : ' (' + esc(r.status) + ')'}${r.rate_override !== null ? ' · custom rate' : ''}</option>`).join('');
            return `<div class="rs-line"><div class="row g-2 align-items-end">
                <div class="col-md-4"><div class="small fw-bold" style="color:var(--cb-heading)">Room ${i + 1}: ${esc(t.name)}</div><div class="small text-muted">${money(lineAmount(l))}</div></div>
                <div class="col-md-4"><label class="small">Room</label><select class="form-select form-select-sm" data-room="${i}"><option value="">Assign later</option>${opts}</select></div>
                <div class="col-6 col-md-2"><label class="small">Adults</label><input type="number" class="form-control form-control-sm" min="1" max="${t.max_adults}" value="${l.adults}" data-adults="${i}"></div>
                <div class="col-6 col-md-2"><label class="small">Children</label><input type="number" class="form-control form-control-sm" min="0" max="${t.max_children}" value="${l.children}" data-children="${i}" ${t.max_children ? '' : 'disabled'}></div>
            </div></div>`;
        }).join('');

        summary();
    }

    function lineAmount(l) {
        const t = typeById(l.typeId), r = l.roomId ? t.rooms.find(x => x.id === l.roomId) : null;
        return r && r.rate_override !== null ? r.rate_override * data.nights : t.amount;
    }

    function summary() {
        const fmt = d => new Date(d + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
        document.getElementById('sumStay').textContent = data ? `${fmt(inEl.value)} → ${fmt(outEl.value)} (${data.nights} night${data.nights === 1 ? '' : 's'})` : '—';
        document.getElementById('sumRooms').textContent = lines.length;
        document.getElementById('sumLines').innerHTML = lines.map((l, i) => {
            const t = typeById(l.typeId), r = l.roomId ? t.rooms.find(x => x.id === l.roomId) : null;
            return `<div class="d-flex justify-content-between"><span>${esc(t.name)}${r ? ' · ' + esc(r.number) : ''}</span><span>${money(lineAmount(l))}</span></div>`;
        }).join('');
        document.getElementById('sumTotal').textContent = money(lines.reduce((s, l) => s + lineAmount(l), 0));
    }

    typeList.addEventListener('click', e => {
        const plus = e.target.closest('[data-plus]'), minus = e.target.closest('[data-minus]');
        if (plus) { lines.push({ typeId: +plus.dataset.plus, roomId: null, adults: Math.min(2, typeById(+plus.dataset.plus).max_adults), children: 0 }); render(); }
        if (minus) { const id = +minus.dataset.minus; for (let i = lines.length - 1; i >= 0; i--) if (lines[i].typeId === id) { lines.splice(i, 1); break; } render(); }
    });
    lineList.addEventListener('change', e => {
        const t = e.target;
        if (t.dataset.room !== undefined) { lines[+t.dataset.room].roomId = t.value ? +t.value : null; render(); }
        if (t.dataset.adults !== undefined) { lines[+t.dataset.adults].adults = Math.max(1, +t.value || 1); }
        if (t.dataset.children !== undefined) { lines[+t.dataset.children].children = Math.max(0, +t.value || 0); }
    });

    // ── Guest picker ───────────────────────────────────────
    const gSearch = document.getElementById('guestSearch'), gHits = document.getElementById('guestHits');
    const gId = document.getElementById('guest_id'), picker = document.getElementById('guestPicker'), chosenBox = document.getElementById('guestChosen');
    let gTimer;
    gSearch?.addEventListener('input', () => {
        clearTimeout(gTimer);
        const q = gSearch.value.trim();
        if (q.length < 2) { gHits.classList.add('d-none'); return; }
        gTimer = setTimeout(async () => {
            const list = await fetch(GUEST_URL + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } }).then(r => r.json()).catch(() => []);
            gHits.innerHTML = list.length ? list.map(g => `<button type="button" class="rs-guest-hit" data-guest='${esc(JSON.stringify(g))}'>
                <span><strong>${esc(g.name)}</strong>${g.vip ? ' <span class="status-pill st-violet">VIP</span>' : ''}<br><span class="small text-muted">${esc(g.phone || '')} ${g.email ? '· ' + esc(g.email) : ''}</span></span>
                <span class="small text-muted">${g.stays} booking${g.stays === 1 ? '' : 's'}</span></button>`).join('')
                : '<div class="p-3 small text-muted">No matching guest — fill in the new guest form below.</div>';
            gHits.classList.remove('d-none');
        }, 300);
    });
    gHits?.addEventListener('click', e => {
        const b = e.target.closest('[data-guest]'); if (!b) return;
        const g = JSON.parse(b.dataset.guest);
        gId.value = g.id;
        document.getElementById('guestChosenName').textContent = g.name;
        document.getElementById('guestChosenMeta').textContent = [g.phone, g.email].filter(Boolean).join(' · ');
        chosenBox.classList.remove('d-none'); picker.classList.add('d-none'); gHits.classList.add('d-none');
    });
    document.getElementById('guestChange').addEventListener('click', () => { gId.value = ''; chosenBox.classList.add('d-none'); picker.classList.remove('d-none'); gSearch?.focus(); });
    document.addEventListener('click', e => { if (gHits && !gHits.contains(e.target) && e.target !== gSearch) gHits.classList.add('d-none'); });

    // ── Submit ─────────────────────────────────────────────
    document.getElementById('bookingForm').addEventListener('submit', e => {
        const err = document.getElementById('formError');
        const fail = msg => { e.preventDefault(); err.querySelector('div').textContent = msg; err.classList.remove('d-none'); err.scrollIntoView({ behavior: 'smooth', block: 'center' }); };
        if (!lines.length) return fail('Add at least one room.');
        if (!gId.value) {
            const need = ['first_name', 'last_name', 'phone'].filter(f => !document.querySelector(`[name="guest[${f}]"]`).value.trim());
            if (need.length) return fail('Pick a returning guest or enter the new guest’s first name, last name and phone.');
        }
        document.getElementById('lineInputs').innerHTML = lines.map((l, i) =>
            `<input type="hidden" name="rooms[${i}][room_type_id]" value="${l.typeId}">` +
            `<input type="hidden" name="rooms[${i}][room_id]" value="${l.roomId ?? ''}">` +
            `<input type="hidden" name="rooms[${i}][adults]" value="${l.adults}">` +
            `<input type="hidden" name="rooms[${i}][children]" value="${l.children}">`).join('');
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('submitBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
    });

    load();
});
</script>
@endsection
