{{--
    Bill modals: post a charge, take a payment / deposit / refund, and void a line.
    Needs: $folio, $balance (positive = guest owes), optional $paidSoFar.
    Open with data-bs-toggle="modal" data-bs-target="#chargeModal" / "#paymentModal".
    Void buttons: data-void-url="…" data-void-label="…" (opens #voidModal).
--}}
@php
    use App\Enums\ChargeType;
    use App\Enums\PaymentMethod;
    $sym = config('hotel.currency_symbol');
    $owed = max(0, (float) $balance);
    $paidSoFar = $paidSoFar ?? $folio->paidTotal();
    $transferAccounts = \App\Models\HotelBankAccount::forTransfers();
@endphp

@can('Post charges')
<div class="modal fade" id="chargeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('folios.charges.store', $folio) }}" data-once>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-add-circle-line me-1"></i> Add a charge</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12"><label for="chType">Type</label>
                    <select name="type" id="chType" class="form-select" required>
                        @foreach (ChargeType::manual() as $t)
                            @if (! in_array($t, [ChargeType::Discount, ChargeType::Adjustment], true) || auth()->user()->can('Apply reservation discounts'))
                                <option value="{{ $t->value }}" @selected(old('type') === $t->value)>{{ $t->label() }}</option>
                            @endif
                        @endforeach
                    </select></div>
                <div class="col-12 d-none" id="chDirWrap"><label>Adjustment</label>
                    <div class="d-flex gap-3">
                        <label class="form-check mb-0"><input class="form-check-input" type="radio" name="direction" value="add" checked> <span class="form-check-label">Add to the bill</span></label>
                        <label class="form-check mb-0"><input class="form-check-input" type="radio" name="direction" value="reduce"> <span class="form-check-label">Take off the bill</span></label>
                    </div></div>
                <div class="col-12"><label for="chDesc">Description</label>
                    <input type="text" name="description" id="chDesc" class="form-control" maxlength="255" required value="{{ old('description') }}" placeholder="e.g. Room service — club sandwich"></div>
                <div class="col-4"><label for="chQty">Qty</label><input type="number" name="quantity" id="chQty" class="form-control" min="0.01" step="0.01" value="{{ old('quantity', 1) }}" required></div>
                <div class="col-8"><label for="chPrice">Unit price</label>
                    <div class="input-group"><span class="input-group-text">{{ $sym }}</span><input type="number" name="unit_price" id="chPrice" class="form-control" min="0.01" step="0.01" required value="{{ old('unit_price') }}"></div></div>
                <div class="col-6"><label for="chDate">Date</label><input type="date" name="service_date" id="chDate" class="form-control" max="{{ today()->toDateString() }}" value="{{ old('service_date', today()->toDateString()) }}"></div>
                <div class="col-6 d-flex align-items-end"><div class="w-100 text-end"><div class="small text-muted">Line total</div><div class="fs-5 fw-bold" id="chTotal" style="color: var(--cb-heading)">{{ $sym }}0.00</div></div></div>
                <div class="col-12 small text-muted">
                    @if (config('hotel.prices_include_tax'))Prices include {{ collect(config('hotel.taxes'))->map(fn ($t) => $t['name'] . ' ' . $t['rate'] . '%')->join(', ') ?: 'no tax' }}.
                    @else{{ collect(config('hotel.taxes'))->map(fn ($t) => $t['name'] . ' ' . $t['rate'] . '%')->join(', ') ?: 'No tax' }} is added on top.@endif
                    Mistakes can’t be edited — void the line and post it again.
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Post charge</button></div>
        </form>
    </div>
</div>
@endcan

@canany(['Receive payments', 'Issue refunds'])
<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('folios.payments.store', $folio) }}" id="payForm" data-once>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-bank-card-line me-1"></i> <span id="payTitle">Receive payment</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-12">
                    <div class="d-flex justify-content-between p-2 px-3 rounded-3" style="background: var(--cb-surface-2)">
                        <span class="text-muted">{{ $balance < 0 ? 'Guest is in credit' : 'Balance due' }}</span>
                        <strong style="color: {{ $balance > 0 ? '#b91c1c' : ($balance < 0 ? '#15803d' : 'var(--cb-heading)') }}">@money(abs($balance))</strong>
                    </div>
                </div>
                <div class="col-12"><label>Type</label>
                    <div class="btn-group w-100" role="group" aria-label="Payment type">
                        @can('Receive payments')
                            @php $defaultKind = $folio->reservation && in_array($folio->reservation->status->value, ['pending', 'confirmed'], true) ? 'deposit' : 'payment'; @endphp
                            <input type="radio" class="btn-check" name="kind" id="kPay" value="payment" @checked(old('kind', $defaultKind) === 'payment')><label class="btn btn-outline-primary" for="kPay">Payment</label>
                            <input type="radio" class="btn-check" name="kind" id="kDep" value="deposit" @checked(old('kind', $defaultKind) === 'deposit')><label class="btn btn-outline-primary" for="kDep">Deposit</label>
                        @endcan
                        @can('Issue refunds')
                            <input type="radio" class="btn-check" name="kind" id="kRef" value="refund" @checked(old('kind') === 'refund' || ! auth()->user()->can('Receive payments')) @disabled($paidSoFar <= 0)><label class="btn btn-outline-danger" for="kRef">Refund</label>
                        @endcan
                    </div></div>
                <div class="col-12"><label for="payAmount">Amount</label>
                    <div class="input-group"><span class="input-group-text">{{ $sym }}</span>
                        <input type="number" name="amount" id="payAmount" class="form-control" min="0.01" step="0.01" required value="{{ old('amount', $owed > 0 ? $owed : '') }}">
                        <button type="button" class="btn btn-light border" id="payFill">Full balance</button></div>
                    <div class="small text-muted mt-1" id="payHint"></div></div>
                <div class="col-12"><label>Method</label>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach (PaymentMethod::cases() as $m)
                            <input type="radio" class="btn-check" name="method" id="m{{ $m->value }}" value="{{ $m->value }}" data-ref="{{ $m->needsReference() ? 1 : 0 }}" @checked(old('method', 'cash') === $m->value)>
                            <label class="btn btn-sm btn-outline-secondary" for="m{{ $m->value }}"><i class="{{ $m->icon() }}"></i> {{ $m->label() }}</label>
                        @endforeach
                    </div></div>
                @if ($transferAccounts->isNotEmpty())
                    <div class="col-12" id="payAccWrap"><label for="payAcc">Paid into</label>
                        <select name="bank_account_id" id="payAcc" class="form-select">
                            @foreach ($transferAccounts as $acc)
                                <option value="{{ $acc->id }}" @selected((int) old('bank_account_id') === $acc->id)>{{ $acc->bank_name }} · {{ $acc->account_number }}{{ $acc->purpose ? ' — ' . $acc->purpose : '' }}</option>
                            @endforeach
                        </select></div>
                @endif
                <div class="col-12" id="payRefWrap"><label for="payRef">Reference <span class="text-danger">*</span></label>
                    <input type="text" name="reference" id="payRef" class="form-control" maxlength="100" value="{{ old('reference') }}" placeholder="Transfer / POS / cheque reference"></div>
                <div class="col-12"><label for="payNotes">Note</label><input type="text" name="notes" id="payNotes" class="form-control" maxlength="255" value="{{ old('notes') }}" placeholder="Optional"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit" id="paySubmit">Save &amp; issue receipt</button></div>
        </form>
    </div>
</div>
@endcanany

<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="#" id="voidForm">
            @csrf
            <div class="modal-header"><h5 class="modal-title text-danger"><i class="ri-forbid-line me-1"></i> Void <span id="voidLabel"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label for="voidReason">Reason <span class="text-danger">*</span></label>
                <input type="text" name="reason" id="voidReason" class="form-control" maxlength="255" required placeholder="e.g. Posted to the wrong room">
                <div class="small text-muted mt-2">The line stays on record, crossed out, with your name and this reason.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep it</button><button class="btn btn-danger" type="submit">Void</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const sym = @json($sym), owed = {{ $owed }}, credit = {{ max(0, -(float) $balance) }}, paid = {{ max(0, (float) $paidSoFar) }};
    const fmt = n => sym + (Math.round(n * 100) / 100).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // Charge modal: running total, adjustment direction.
    const chType = document.getElementById('chType');
    if (chType) {
        const qty = document.getElementById('chQty'), price = document.getElementById('chPrice'), out = document.getElementById('chTotal'), dir = document.getElementById('chDirWrap');
        const calc = () => {
            const reduce = chType.value === 'discount' || (chType.value === 'adjustment' && document.querySelector('[name=direction]:checked')?.value === 'reduce');
            const t = (parseFloat(qty.value) || 0) * (parseFloat(price.value) || 0);
            out.textContent = (reduce && t ? '−' : '') + fmt(t);
            out.style.color = reduce ? '#15803d' : 'var(--cb-heading)';
            dir.classList.toggle('d-none', chType.value !== 'adjustment');
        };
        [chType, qty, price, ...document.querySelectorAll('[name=direction]')].forEach(el => el.addEventListener('input', calc));
        chType.addEventListener('change', calc); calc();
    }

    // Payment modal: reference only for non-cash, refund limits.
    const payForm = document.getElementById('payForm');
    if (payForm) {
        const amount = document.getElementById('payAmount'), hint = document.getElementById('payHint'), refWrap = document.getElementById('payRefWrap'), ref = document.getElementById('payRef'), fill = document.getElementById('payFill');
        const sync = () => {
            const kind = payForm.querySelector('[name=kind]:checked')?.value || 'payment';
            const needsRef = payForm.querySelector('[name=method]:checked')?.dataset.ref === '1';
            refWrap.classList.toggle('d-none', !needsRef); ref.required = needsRef;
            const m = payForm.querySelector('[name=method]:checked')?.value, accWrap = document.getElementById('payAccWrap');
            if (accWrap) { const show = ['transfer', 'online', 'cheque'].includes(m); accWrap.classList.toggle('d-none', !show); document.getElementById('payAcc').disabled = !show; }
            document.getElementById('payTitle').textContent = kind === 'refund' ? 'Refund the guest' : (kind === 'deposit' ? 'Take a deposit' : 'Receive payment');
            document.getElementById('paySubmit').classList.toggle('btn-danger', kind === 'refund');
            document.getElementById('paySubmit').classList.toggle('btn-primary', kind !== 'refund');
            if (kind === 'refund') {
                amount.max = paid; fill.textContent = 'Full credit'; fill.disabled = credit <= 0;
                hint.textContent = 'Up to ' + fmt(paid) + ' can be refunded' + (credit > 0 ? ' · guest is ' + fmt(credit) + ' in credit' : '') + '.';
            } else {
                amount.removeAttribute('max'); fill.textContent = 'Full balance'; fill.disabled = owed <= 0;
                hint.textContent = owed > 0 ? '' : 'Nothing is owed right now — this will be held as credit.';
            }
        };
        fill.addEventListener('click', () => {
            const kind = payForm.querySelector('[name=kind]:checked')?.value;
            amount.value = (kind === 'refund' ? credit : owed).toFixed(2);
        });
        payForm.querySelectorAll('[name=kind],[name=method]').forEach(el => el.addEventListener('change', sync)); sync();
    }

    // Void buttons anywhere on the page.
    document.querySelectorAll('[data-void-url]').forEach(btn => btn.addEventListener('click', () => {
        document.getElementById('voidForm').action = btn.dataset.voidUrl;
        document.getElementById('voidLabel').textContent = btn.dataset.voidLabel || '';
        document.getElementById('voidReason').value = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('voidModal')).show();
    }));

    @if ($errors->hasAny(['description', 'quantity', 'unit_price', 'type', 'service_date']))
        bootstrap.Modal.getOrCreateInstance(document.getElementById('chargeModal'))?.show();
    @elseif ($errors->hasAny(['amount', 'method', 'reference', 'kind']))
        bootstrap.Modal.getOrCreateInstance(document.getElementById('paymentModal'))?.show();
    @endif
});
</script>
