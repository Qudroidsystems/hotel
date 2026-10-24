{{--
    Item lines editor for receipts, purchase orders and transfers.
    Params: $items (StockItem collection), $rows (array of [item_id, quantity, unit_cost, po_line_id, locked]),
            $withCost (bool), $withReject (bool: receipts can record goods refused at the door), $availableFrom (JS expression id of a <select> whose value is a store id, optional), $levels (store => item => qty)
--}}
@php
    $withCost = $withCost ?? false;
    $withReject = $withReject ?? false;
    $rows = $rows ?? [];
    $sym = config('hotel.currency_symbol');
    $itemData = $items->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'unit' => $i->unit, 'cost' => (float) ($i->last_cost ?? $i->avg_cost), 'cat' => $i->category?->name ?? 'Other'])->values();
@endphp
<div class="table-responsive">
    <table class="cb-table" id="linesTable">
        <thead><tr><th style="min-width:240px">Item</th><th class="num" style="width:140px">Quantity</th>@if ($withCost)<th class="num" style="width:160px">Unit cost</th><th class="num" style="width:140px">Line total</th>@endif<th style="width:46px"></th></tr></thead>
        <tbody id="linesBody"></tbody>
        @if ($withCost)
            <tfoot><tr><td colspan="3" class="text-end fw-bold">Total</td><td class="num fw-bold" id="linesTotal">{{ $sym }}0.00</td><td></td></tr></tfoot>
        @endif
    </table>
</div>
<div class="d-flex gap-2 p-3 flex-wrap align-items-center">
    <div class="cb-search" style="max-width:360px;flex:1">
        <i class="ri-add-line"></i>
        <input type="text" id="lineAdd" list="lineItems" placeholder="Type an item name to add it…" aria-label="Add item" autocomplete="off">
    </div>
    <datalist id="lineItems">@foreach ($itemData as $i)<option value="{{ $i['name'] }}">@endforeach</datalist>
    <button type="button" class="action-btn btn-more" id="lineAddBlank"><i class="ri-add-line"></i> Add row</button>
    <span class="small text-muted ms-auto" id="linesNote"></span>
</div>

<template id="lineTpl">
    <tr>
        <td>
            <select class="form-select form-select-sm" data-f="item" required>
                <option value="">Choose…</option>
                @foreach ($itemData->groupBy('cat') as $cat => $group)
                    <optgroup label="{{ $cat }}">@foreach ($group as $i)<option value="{{ $i['id'] }}">{{ $i['name'] }} ({{ $i['unit'] }})</option>@endforeach</optgroup>
                @endforeach
            </select>
            <div class="small text-muted mt-1" data-f="hint"></div>
            <input type="hidden" data-f="poline">
            @if ($withReject)
                <a href="#" class="small text-danger" data-f="rejtoggle"><i class="ri-close-circle-line"></i> Refused some?</a>
                <div class="input-group input-group-sm mt-1 d-none" data-f="rejwrap">
                    <input type="number" class="form-control" data-f="rejqty" min="0" step="0.001" placeholder="Qty refused" aria-label="Quantity refused" style="max-width:120px">
                    <input type="text" class="form-control" data-f="rejwhy" maxlength="120" placeholder="Why: damaged, expired, wrong item…" aria-label="Why refused">
                </div>
            @endif
        </td>
        <td class="num"><div class="input-group input-group-sm"><input type="number" class="form-control text-end" data-f="qty" min="0" step="0.001"><span class="input-group-text" data-f="unit">—</span></div></td>
        @if ($withCost)
            <td class="num"><div class="input-group input-group-sm"><span class="input-group-text">{{ $sym }}</span><input type="number" class="form-control text-end" data-f="cost" min="0" step="0.01"></div></td>
            <td class="num fw-semibold" data-f="total">{{ $sym }}0.00</td>
        @endif
        <td><button type="button" class="action-btn btn-danger-soft" data-f="rm" aria-label="Remove line"><i class="ri-close-line"></i></button></td>
    </tr>
</template>

<script>
(function () {
    const ITEMS = @json($itemData), byId = Object.fromEntries(ITEMS.map(i => [i.id, i])), byName = Object.fromEntries(ITEMS.map(i => [i.name.toLowerCase(), i]));
    const LEVELS = @json($levels ?? null), fromSel = @json($availableFrom ?? null), withCost = @json($withCost), sym = @json($sym);
    const body = document.getElementById('linesBody'), tpl = document.getElementById('lineTpl');
    const fmt = n => sym + Number(n || 0).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const qtyTxt = q => String(Math.round(q * 1000) / 1000);
    let n = 0;

    function renumber() {
        [...body.rows].forEach((tr, i) => {
            tr.querySelector('[data-f=item]').name = `lines[${i}][item_id]`;
            tr.querySelector('[data-f=qty]').name = `lines[${i}][quantity]`;
            tr.querySelector('[data-f=poline]').name = `lines[${i}][purchase_order_line_id]`;
            if (tr.querySelector('[data-f=rejqty]')) {
                tr.querySelector('[data-f=rejqty]').name = `lines[${i}][rejected_quantity]`;
                tr.querySelector('[data-f=rejwhy]').name = `lines[${i}][reject_reason]`;
            }
            if (withCost) tr.querySelector('[data-f=cost]').name = `lines[${i}][unit_cost]`;
        });
        recalc();
    }
    function recalc() {
        let total = 0;
        const from = fromSel ? document.getElementById(fromSel)?.value : null;
        [...body.rows].forEach(tr => {
            const it = byId[tr.querySelector('[data-f=item]').value], q = parseFloat(tr.querySelector('[data-f=qty]').value) || 0;
            tr.querySelector('[data-f=unit]').textContent = it ? it.unit : '—';
            const hint = tr.querySelector('[data-f=hint]');
            if (LEVELS && from && it) {
                const have = (LEVELS[from] || {})[it.id] || 0;
                hint.textContent = qtyTxt(have) + ' ' + it.unit + ' available';
                hint.className = 'small mt-1 ' + (q > have + 0.0005 ? 'text-danger fw-semibold' : 'text-muted');
            } else if (!tr.dataset.locked) { hint.textContent = ''; }
            if (withCost) {
                const c = parseFloat(tr.querySelector('[data-f=cost]').value) || 0;
                tr.querySelector('[data-f=total]').textContent = fmt(q * c);
                total += q * c;
            }
        });
        if (withCost) document.getElementById('linesTotal').textContent = fmt(total);
        document.getElementById('linesNote').textContent = body.rows.length + ' line' + (body.rows.length === 1 ? '' : 's');
    }
    function add(row) {
        row = row || {};
        const tr = tpl.content.firstElementChild.cloneNode(true);
        const sel = tr.querySelector('[data-f=item]');
        sel.value = row.item_id || '';
        tr.querySelector('[data-f=qty]').value = row.quantity ?? '';
        tr.querySelector('[data-f=poline]').value = row.po_line_id || '';
        const rejToggle = tr.querySelector('[data-f=rejtoggle]');
        if (rejToggle) {
            const wrap = tr.querySelector('[data-f=rejwrap]');
            tr.querySelector('[data-f=rejqty]').value = row.rejected_quantity || '';
            tr.querySelector('[data-f=rejwhy]').value = row.reject_reason || '';
            if (row.rejected_quantity) { wrap.classList.remove('d-none'); rejToggle.classList.add('d-none'); }
            rejToggle.addEventListener('click', e => { e.preventDefault(); wrap.classList.remove('d-none'); rejToggle.classList.add('d-none'); tr.querySelector('[data-f=rejqty]').focus(); });
        }
        if (withCost) tr.querySelector('[data-f=cost]').value = row.unit_cost ?? (row.item_id && byId[row.item_id] ? byId[row.item_id].cost : '');
        if (row.locked) {
            tr.dataset.locked = '1';
            sel.style.pointerEvents = 'none'; sel.tabIndex = -1; sel.classList.add('bg-light');
            tr.querySelector('[data-f=rm]').remove();
            if (row.hint) tr.querySelector('[data-f=hint]').textContent = row.hint;
        }
        sel.addEventListener('change', () => {
            if (withCost && sel.value && !tr.querySelector('[data-f=cost]').value) tr.querySelector('[data-f=cost]').value = byId[sel.value]?.cost || '';
            recalc();
        });
        tr.querySelectorAll('input').forEach(i => i.addEventListener('input', recalc));
        tr.querySelector('[data-f=rm]')?.addEventListener('click', () => { tr.remove(); renumber(); });
        body.appendChild(tr);
        renumber();
        return tr;
    }
    const addBox = document.getElementById('lineAdd');
    addBox.addEventListener('change', () => {
        const it = byName[addBox.value.trim().toLowerCase()];
        if (!it) return;
        const existing = [...body.rows].find(tr => tr.querySelector('[data-f=item]').value == it.id);
        const tr = existing || add({ item_id: it.id });
        addBox.value = '';
        tr.querySelector('[data-f=qty]').focus();
    });
    addBox.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addBox.dispatchEvent(new Event('change')); } });
    document.getElementById('lineAddBlank').addEventListener('click', () => add().querySelector('[data-f=item]').focus());
    if (fromSel) document.getElementById(fromSel)?.addEventListener('change', recalc);

    const initial = @json(array_values($rows));
    if (initial.length) initial.forEach(add); else add();
})();
</script>
