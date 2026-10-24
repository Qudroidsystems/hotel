@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php $sym = config('hotel.currency_symbol'); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-safe-2-line" subtitle="Open your drawer with a float, close it with a cash count. Supervisors sign off each shift.">
        <x-slot:actions>
            @canany(['View finance reports', 'Receive payments'])<a class="cb-hero-btn" href="{{ route('billing.payments') }}"><i class="ri-hand-coin-line"></i> Collections</a>@endcanany
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')

    @canany(['Use POS', 'Receive payments'])
        @if ($current)
            <div class="row g-3 mb-3">
                <div class="col-xl-5">
                    <x-cb.card :title="'Your shift ' . $current->number" icon="ri-time-line">
                        <x-slot:tools><span class="status-pill st-info">Open since {{ $current->opened_at->format('g:i A') }}{{ $current->opened_at->isToday() ? '' : ' ' . $current->opened_at->format('D j M') }}</span></x-slot:tools>
                        <div class="small text-muted mb-2">{{ $current->station }} · figures update as you take money</div>
                        @include('billing.shifts.partials.summary', ['shift' => $current, 'totals' => $currentTotals])
                        <a href="{{ route('cash-shifts.show', $current) }}" class="action-btn btn-more mt-3"><i class="ri-file-list-3-line"></i> Details</a>
                    </x-cb.card>
                </div>
                <div class="col-xl-7">
                    <x-cb.card title="Close and count" icon="ri-lock-line">
                        <form method="POST" action="{{ route('cash-shifts.close', $current) }}" class="rm-form row g-3" data-once id="closeForm">
                            @csrf
                            <div class="col-12 small text-muted">Count every note and coin in the drawer (including the float). Fill in the counts below, or type the total.</div>
                            @foreach (config('hotel.cash.denominations') as $d)
                                <div class="col-3 col-md-3"><label for="den{{ $d }}">{{ $sym }}{{ number_format($d) }} ×</label>
                                    <input type="number" min="0" step="1" class="form-control den" id="den{{ $d }}" name="denominations[{{ $d }}]" data-value="{{ $d }}" value="{{ old('denominations.' . $d) }}"></div>
                            @endforeach
                            <div class="col-md-5"><label for="counted">Cash counted</label>
                                <div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" name="counted_cash" id="counted" class="form-control" min="0" step="0.01" required value="{{ old('counted_cash') }}"></div>
                                <div class="form-text" id="diffHint" data-expected="{{ $currentTotals['expected_cash'] }}" data-tolerance="{{ config('hotel.cash.variance_tolerance') }}"></div></div>
                            <div class="col-md-7"><label for="closeNote">Note <span class="text-muted fw-normal">(needed if the count is off)</span></label>
                                <input type="text" name="close_note" id="closeNote" class="form-control" maxlength="500" value="{{ old('close_note') }}" placeholder="e.g. ₦200 change given from my pocket"></div>
                            <div class="col-12 d-flex gap-2 flex-wrap">
                                <button type="submit" class="action-btn btn-open"><i class="ri-lock-line"></i> Close shift</button>
                                <button type="button" class="action-btn btn-more" data-bs-toggle="collapse" data-bs-target="#moveForm"><i class="ri-exchange-dollar-line"></i> Paid in / out / drop</button>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('cash-shifts.movement', $current) }}" class="rm-form row g-3 collapse mt-2 pt-3 border-top" id="moveForm" data-once>
                            @csrf
                            <div class="col-md-4"><label for="mvType">What</label>
                                <select name="type" id="mvType" class="form-select">
                                    @foreach (\App\Models\CashShiftMovement::TYPES as $key => [$label])<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                </select></div>
                            <div class="col-md-3"><label for="mvAmount">Amount</label><input type="number" name="amount" id="mvAmount" class="form-control" min="0.01" step="0.01" required></div>
                            <div class="col-md-5"><label for="mvReason">Reason</label><input type="text" name="reason" id="mvReason" class="form-control" maxlength="255" required placeholder="e.g. Bought ice for the bar"></div>
                            <div class="col-12"><button class="action-btn btn-open" type="submit"><i class="ri-save-line"></i> Record</button></div>
                        </form>
                    </x-cb.card>
                </div>
            </div>
        @else
            <x-cb.card title="Start your shift" icon="ri-play-circle-line">
                <form method="POST" action="{{ route('cash-shifts.store') }}" class="rm-form row g-3 align-items-end" data-once>
                    @csrf
                    <div class="col-md-4"><label for="station">Where are you working?</label>
                        <input type="text" name="station" id="station" class="form-control" list="stations" maxlength="60" required value="{{ old('station', $shifts->firstWhere('user_id', auth()->id())?->station) }}">
                        <datalist id="stations">@foreach (config('hotel.cash.stations') as $s)<option value="{{ $s }}">@endforeach @foreach ($outlets as $o)<option value="{{ $o->name }}">@endforeach</datalist></div>
                    @if ($outlets->isNotEmpty())
                        <div class="col-md-3"><label for="outlet">Outlet <span class="text-muted fw-normal">(optional)</span></label>
                            <select name="outlet_id" id="outlet" class="form-select"><option value="">—</option>@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected((int) old('outlet_id') === $o->id)>{{ $o->name }}</option>@endforeach</select></div>
                    @endif
                    <div class="col-md-3"><label for="float">Opening float</label>
                        <div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" name="opening_float" id="float" class="form-control" min="0" step="0.01" required value="{{ old('opening_float', 0) }}"></div></div>
                    <div class="col-md-2"><button type="submit" class="action-btn btn-open w-100"><i class="ri-play-line"></i> Open shift</button></div>
                </form>
            </x-cb.card>
        @endif
    @endcanany

    @if ($everyone)
        <div class="row g-3 mb-1">
            <div class="col-6 col-lg-3"><x-cb.stat label="Open now" :value="$stats['open']" icon="ri-time-line" accent="teal" /></div>
            <div class="col-6 col-lg-3"><a class="text-decoration-none" href="{{ route('cash-shifts.index', ['status' => 'closed']) }}"><x-cb.stat label="Awaiting sign-off" :value="$stats['awaiting']" icon="ri-checkbox-circle-line" accent="amber" /></a></div>
            <div class="col-6 col-lg-3"><x-cb.stat label="Short (period)" :value="$sym . number_format(abs($stats['short']), 2)" icon="ri-arrow-down-line" accent="rose" /></div>
            <div class="col-6 col-lg-3"><x-cb.stat label="Over (period)" :value="$sym . number_format($stats['over'], 2)" icon="ri-arrow-up-line" accent="green" /></div>
        </div>
    @endif

    <div class="cb-card">
        <form method="GET" class="cb-toolbar">
            <input type="date" name="from" class="cb-select" value="{{ $from->toDateString() }}" aria-label="From">
            <input type="date" name="to" class="cb-select" value="{{ $to->toDateString() }}" aria-label="To">
            <select name="status" class="cb-select" aria-label="Status" onchange="this.form.submit()">
                <option value="">Any status</option>
                <option value="open" @selected($status === 'open')>Open</option>
                <option value="closed" @selected($status === 'closed')>Awaiting review</option>
                <option value="reviewed" @selected($status === 'reviewed')>Signed off</option>
            </select>
            @if ($everyone)
                <select name="cashier" class="cb-select" aria-label="Cashier" onchange="this.form.submit()">
                    <option value="">Everyone</option>
                    @foreach ($cashiers as $u)<option value="{{ $u->id }}" @selected($cashier === $u->id)>{{ $u->name }}</option>@endforeach
                </select>
            @endif
            <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Show</button>
        </form>
        @if ($shifts->isEmpty())
            <div class="empty-state py-5"><i class="ri-safe-2-line"></i><h6>No shifts in this period</h6></div>
        @else
            <div class="table-responsive">
                <table class="cb-table stack">
                    <thead><tr><th>Shift</th>@if ($everyone)<th>Cashier</th>@endif<th>Station</th><th>Status</th><th class="num">Float</th><th class="num">Expected cash</th><th class="num">Counted</th><th class="num">Difference</th></tr></thead>
                    <tbody>
                        @foreach ($shifts as $s)
                            <tr>
                                <td data-label="Shift"><a href="{{ route('cash-shifts.show', $s) }}" class="rs-ref">{{ $s->number }}</a>
                                    <div class="small text-muted">{{ $s->opened_at->format('D j M, g:i A') }}{{ $s->closed_at ? ' – ' . $s->closed_at->format($s->closed_at->isSameDay($s->opened_at) ? 'g:i A' : 'D j M, g:i A') : '' }}</div></td>
                                @if ($everyone)<td data-label="Cashier">{{ $s->user->name }}</td>@endif
                                <td data-label="Station">{{ $s->station }}</td>
                                <td data-label="Status"><span class="status-pill {{ $s->status_pill }}">{{ $s->status_label }}</span>
                                    @if ($s->reviewedBy)<div class="small text-muted">by {{ $s->reviewedBy->name }}</div>@endif</td>
                                <td data-label="Float" class="num">@money($s->opening_float)</td>
                                <td data-label="Expected cash" class="num">{{ $s->expected_cash !== null ? $sym . number_format($s->expected_cash, 2) : '—' }}</td>
                                <td data-label="Counted" class="num">{{ $s->counted_cash !== null ? $sym . number_format($s->counted_cash, 2) : '—' }}</td>
                                <td data-label="Difference" class="num fw-semibold {{ (float) $s->variance < 0 ? 'text-danger' : ((float) $s->variance > 0 ? 'text-warning' : '') }}">
                                    @if ($s->variance !== null){{ $s->varianceLabel() }}{{ abs((float) $s->variance) >= 0.01 ? ' ' . $sym . number_format(abs((float) $s->variance), 2) : '' }}@else — @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>
</div>
</div>
@if ($current)
<script>
(function () {
    var form = document.getElementById('closeForm'), counted = document.getElementById('counted'), hint = document.getElementById('diffHint');
    var expected = parseFloat(hint.dataset.expected), tol = parseFloat(hint.dataset.tolerance), sym = @json($sym);
    function fmt(n) { return sym + Math.abs(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function show() {
        if (counted.value === '') { hint.textContent = ''; return; }
        var d = Math.round((parseFloat(counted.value) - expected) * 100) / 100;
        hint.textContent = Math.abs(d) < 0.01 ? 'Balanced.' : (d < 0 ? 'Short by ' : 'Over by ') + fmt(d) + (Math.abs(d) > tol ? ' — please add a note.' : '');
        hint.className = 'form-text ' + (Math.abs(d) < 0.01 ? 'text-success' : (Math.abs(d) > tol ? 'text-danger' : 'text-warning'));
    }
    form.querySelectorAll('.den').forEach(function (el) {
        el.addEventListener('input', function () {
            var total = 0;
            form.querySelectorAll('.den').forEach(function (d) { total += (parseInt(d.value, 10) || 0) * parseFloat(d.dataset.value); });
            counted.value = total ? total.toFixed(2) : '';
            show();
        });
    });
    counted.addEventListener('input', show);
})();
</script>
@endif
@endsection
