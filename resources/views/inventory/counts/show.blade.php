@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Inventory\StockItem;
    $q = fn ($v) => StockItem::qty($v);
    $c = $count;
    $open = $c->isOpen();
    // Counters see a "blind" sheet; supervisors who approve can see what the system expects.
    $seeExpected = ! $open || auth()->user()->can('Post stock counts');
    $canCount = $open && auth()->user()->can('Count stock');
    $counted = $c->lines->whereNotNull('counted_quantity')->count();
@endphp
<style>.cnt-input { max-width: 130px; margin-left: auto; text-align: right; font-weight: 700; } tr.cnt-done td { background: rgba(22, 163, 74, .05); }</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="'Stocktake ' . $c->number" icon="ri-list-check-2" :subtitle="$c->location->name . ($c->category ? ' · ' . $c->category->name : '') . ' · started ' . $c->created_at->format('j M Y, g:i A') . ' by ' . $c->startedBy?->name" :back="route('inventory.counts.index')" back-label="Stocktakes">
        <x-slot:pills>
            <span class="cb-meta-pill"><i class="ri-checkbox-multiple-line"></i> <span id="cntProgress">{{ $counted }}</span> / {{ $c->lines->count() }} counted</span>
            @if ($c->status === 'posted')<span class="cb-meta-pill" style="background:#16a34a;border-color:#16a34a"><i class="ri-check-double-line"></i> Approved {{ $c->posted_at->format('j M g:i A') }} by {{ $c->postedBy?->name }}</span>@endif
            @if ($c->status === 'cancelled')<span class="cb-meta-pill"><i class="ri-close-line"></i> Cancelled</span>@endif
        </x-slot:pills>
        <x-slot:actions>
            <a class="cb-hero-btn" href="{{ route('inventory.counts.show', [$c, 'print' => 1]) }}" target="_blank" rel="noopener"><i class="ri-printer-line"></i> Count sheet</a>
            @if ($canCount)
                <form method="POST" action="{{ route('inventory.counts.cancel', $c) }}" onsubmit="return confirm('Cancel this stocktake? Counts entered will be discarded and stock will not change.');">@csrf<button class="cb-hero-btn" type="submit"><i class="ri-close-circle-line"></i> Cancel</button></form>
            @endif
        </x-slot:actions>
    </x-cb.hero>
    @include('rooms.partials.flash')
    @if ($c->status === 'posted')
        <div class="cb-banner {{ $c->variance_value < 0 ? 'warning' : 'success' }}"><i class="ri-scales-3-line"></i><div>Differences booked to stock: <strong>{{ $c->variance_value < 0 ? '−' : '+' }}@money(abs((float) $c->variance_value))</strong> at average cost.</div></div>
    @elseif ($open && ! $seeExpected)
        <div class="cb-banner info"><i class="ri-eye-off-line"></i><div>Count what is on the shelves and type the number. You won't see the system figure — that keeps the count honest. Save as you go; a supervisor approves it.</div></div>
    @endif

    <form method="POST" action="{{ route('inventory.counts.save', $c) }}" id="cntForm">
        @csrf
        <div class="cb-card">
            <div class="cb-toolbar">
                <div class="cb-search"><i class="ri-search-line"></i><input type="text" id="cntFilter" placeholder="Find an item…" aria-label="Find an item"></div>
                @if ($canCount)<label class="form-check mb-0 small"><input type="checkbox" class="form-check-input" id="cntOnlyLeft"> Only not counted</label>@endif
            </div>
            <div class="table-responsive">
                <table class="cb-table stack" id="cntTable">
                    <thead><tr><th>Item</th>@if ($seeExpected)<th class="num">System</th>@endif<th class="num">Counted</th>@if ($seeExpected)<th class="num">Difference</th><th class="num">Value</th>@endif</tr></thead>
                    <tbody>
                        @foreach ($c->lines->sortBy(fn ($l) => [$l->item->category?->name, $l->item->name]) as $l)
                            @php $v = $l->variance(); @endphp
                            <tr data-name="{{ strtolower($l->item->name) }}" data-expected="{{ (float) $l->expected_quantity }}" data-cost="{{ (float) $l->unit_cost }}" class="{{ $l->counted_quantity !== null ? 'cnt-done' : '' }}">
                                <td data-label="Item"><span class="fw-semibold">{{ $l->item->name }}</span><div class="small text-muted">{{ $l->item->category?->name }} · {{ $l->item->unit }}</div></td>
                                @if ($seeExpected)<td data-label="System" class="num">{{ $q($l->expected_quantity) }}</td>@endif
                                <td data-label="Counted" class="num">
                                    @if ($canCount)
                                        <input type="number" class="form-control form-control-sm cnt-input" name="counted[{{ $l->id }}]" min="0" step="0.001" inputmode="decimal" value="{{ $l->counted_quantity !== null ? $q($l->counted_quantity) : '' }}" aria-label="Counted {{ $l->item->name }}">
                                    @else
                                        {{ $l->counted_quantity !== null ? $q($l->counted_quantity) : '—' }}
                                    @endif
                                </td>
                                @if ($seeExpected)
                                    <td data-label="Difference" class="num fw-bold" data-f="diff">@if ($v !== null)<span class="{{ $v < 0 ? 'text-danger' : ($v > 0 ? 'text-success' : 'text-muted') }}">{{ $v > 0 ? '+' : '' }}{{ $q($v) }}</span>@else — @endif</td>
                                    <td data-label="Value" class="num small" data-f="val">@if ($v !== null && $v != 0){{ $v < 0 ? '−' : '+' }}@money(abs($v * (float) $l->unit_cost))@endif</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($seeExpected)
                        <tfoot><tr><td colspan="4" class="text-end fw-bold">Net difference</td><td class="num fw-bold" id="cntNet">—</td></tr></tfoot>
                    @endif
                </table>
            </div>
        </div>
        @if ($canCount)
            <div class="d-flex justify-content-end gap-2 flex-wrap" style="position:sticky;bottom:0;background:var(--bs-body-bg);padding:12px 0;z-index:5">
                <span class="small text-muted align-self-center me-auto">Empty boxes are left as not counted and won't change stock.</span>
                <button type="submit" class="btn btn-outline-primary"><i class="ri-save-line"></i> Save counts</button>
                @can('Post stock counts')
                    <button type="submit" class="btn btn-success" name="then" value="post" onclick="return confirm('Approve this stocktake? Every difference will be booked to stock. This can\'t be undone.');"><i class="ri-check-double-line"></i> Save &amp; approve</button>
                @endcan
            </div>
        @endif
    </form>
</div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const rows = [...document.querySelectorAll('#cntTable tbody tr')], sym = @json(config('hotel.currency_symbol'));
    const fmt = n => sym + Math.abs(n).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const filter = document.getElementById('cntFilter'), only = document.getElementById('cntOnlyLeft');
    function apply() {
        const q = filter.value.trim().toLowerCase();
        rows.forEach(r => {
            const inp = r.querySelector('.cnt-input');
            const hide = (q && !r.dataset.name.includes(q)) || (only && only.checked && inp && inp.value !== '');
            r.style.display = hide ? 'none' : '';
        });
    }
    function recalc() {
        let net = 0, done = 0;
        rows.forEach(r => {
            const inp = r.querySelector('.cnt-input');
            const val = inp ? inp.value : null;
            if (inp) r.classList.toggle('cnt-done', val !== '');
            if (val !== null && val !== '') done++;
            const diffCell = r.querySelector('[data-f=diff]');
            if (!diffCell || !inp) return;
            if (val === '') { diffCell.textContent = '—'; r.querySelector('[data-f=val]').textContent = ''; return; }
            const d = Math.round((parseFloat(val) - parseFloat(r.dataset.expected)) * 1000) / 1000, v = d * parseFloat(r.dataset.cost);
            diffCell.innerHTML = '<span class="' + (d < 0 ? 'text-danger' : d > 0 ? 'text-success' : 'text-muted') + '">' + (d > 0 ? '+' : '') + d + '</span>';
            r.querySelector('[data-f=val]').textContent = d ? (d < 0 ? '−' : '+') + fmt(v) : '';
            net += v;
        });
        const p = document.getElementById('cntProgress'); if (p && rows.some(r => r.querySelector('.cnt-input'))) p.textContent = done;
        const n = document.getElementById('cntNet'); if (n && rows.some(r => r.querySelector('.cnt-input'))) n.textContent = (net < 0 ? '−' : '+') + fmt(net);
    }
    filter.addEventListener('input', apply); only?.addEventListener('change', apply);
    document.querySelectorAll('.cnt-input').forEach((inp, i, all) => {
        inp.addEventListener('input', recalc);
        // Enter jumps to the next box, like a till keypad.
        inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); const nx = [...all].slice(i + 1).find(x => x.closest('tr').style.display !== 'none'); (nx || inp).focus(); } });
    });
    recalc();
    @if (! $canCount && $seeExpected)
        document.getElementById('cntNet').textContent = @json(($c->variance_value < 0 ? '−' : '+') . config('hotel.currency_symbol') . number_format(abs((float) $c->variance_value), 2));
    @endif
});
</script>
@endsection
