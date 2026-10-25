{{-- Search box. $q: check_in, check_out, adults, children, rooms. $compact: smaller version on results. --}}
<form method="GET" action="{{ route('book.search') }}" class="search {{ ($compact ?? false) ? 'compact' : '' }}" aria-label="Check availability">
    <div class="field"><label for="s_in">Check-in</label><input type="date" id="s_in" name="check_in" value="{{ $q['check_in'] }}" min="{{ today()->toDateString() }}" max="{{ today()->addDays((int) config('hotel.booking.advance_days', 365))->toDateString() }}" required></div>
    <div class="field"><label for="s_out">Check-out</label><input type="date" id="s_out" name="check_out" value="{{ $q['check_out'] }}" min="{{ today()->addDay()->toDateString() }}" required></div>
    <div class="field"><label for="s_ad">Adults</label><select id="s_ad" name="adults">@for ($i = 1; $i <= 12; $i++)<option value="{{ $i }}" @selected($q['adults'] == $i)>{{ $i }}</option>@endfor</select></div>
    <div class="field"><label for="s_ch">Children</label><select id="s_ch" name="children">@for ($i = 0; $i <= 8; $i++)<option value="{{ $i }}" @selected($q['children'] == $i)>{{ $i }}</option>@endfor</select></div>
    <div class="field"><label for="s_promo">Promo code</label><input type="text" id="s_promo" name="promo" value="{{ $q['promo'] ?? '' }}" maxlength="30" autocomplete="off" placeholder="Optional" style="text-transform:uppercase"></div>
    <div class="field"><label for="s_rm">Rooms</label><select id="s_rm" name="rooms">@for ($i = 1; $i <= (int) config('hotel.booking.max_rooms', 4); $i++)<option value="{{ $i }}" @selected($q['rooms'] == $i)>{{ $i }}</option>@endfor</select></div>
    <button class="btn" type="submit"><i class="ri-search-line"></i> {{ ($compact ?? false) ? 'Update' : 'See available rooms' }}</button>
</form>
@once
@push('head')
<style>
    .search { display: grid; grid-template-columns: 1.3fr 1.3fr .7fr .7fr 1fr .7fr auto; gap: 12px; align-items: end; }
    .search .btn { height: 50px; }
    .search > * { min-width: 0; }
    .search input[type=date] { min-width: 0; width: 100%; -webkit-appearance: none; appearance: none; }
    @media (max-width: 860px) { .search { grid-template-columns: repeat(2, minmax(0, 1fr)); } .search .btn { grid-column: 1 / -1; } }
    @media (max-width: 860px) {
        .search.compact { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        .search.compact .field:nth-child(1), .search.compact .field:nth-child(2) { grid-column: span 3; }
        .search.compact .field:nth-child(3), .search.compact .field:nth-child(4), .search.compact .field:nth-child(6) { grid-column: span 2; }
        .search.compact .field:nth-child(5) { grid-column: 1 / -1; }
        .search.compact .btn { grid-column: 1 / -1; }
    }
</style>
@endpush
@push('scripts')
<script>
    // Keep check-out after check-in.
    (function () {
        const i = document.getElementById('s_in'), o = document.getElementById('s_out');
        if (!i || !o) return;
        i.addEventListener('change', () => {
            const d = new Date(i.value + 'T00:00'); d.setDate(d.getDate() + 1);
            const next = d.toISOString().slice(0, 10); o.min = next;
            if (!o.value || o.value <= i.value) o.value = next;
        });
    })();
</script>
@endpush
@endonce
