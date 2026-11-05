{{--
    "Card terminal" on a bill: send the amount to a Paystack / Moniepoint / OPay
    terminal and wait. The payment is recorded on the bill by the server when
    the card is paid (even if this page is closed); this only watches.
    Needs: $folio, $balance.
--}}
@php
    $termList = app(\App\Services\Billing\TerminalService::class)->available();
@endphp
@if ($termList->isNotEmpty() && auth()->user()->can('Receive payments'))
<div class="modal fade" id="terminalModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rm-form">
            <div class="modal-header"><h5 class="modal-title"><i class="ri-bank-card-2-line me-1"></i> Pay on a card terminal</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="tpClose"></button></div>
            <div class="modal-body">
                <div id="tpForm" class="row g-3">
                    <div class="col-12"><label for="tpTerminal">Terminal</label>
                        <select id="tpTerminal" class="form-select">@foreach ($termList as $tm)<option value="{{ $tm->id }}">{{ $tm->name }} ({{ $tm->provider_label }})</option>@endforeach</select></div>
                    <div class="col-12"><label for="tpAmount">Amount</label>
                        <input id="tpAmount" type="number" class="form-control" min="1" step="0.01" value="{{ number_format(max(0, (float) $balance), 2, '.', '') }}"></div>
                    <div class="col-12 small text-muted">The amount appears on the terminal. When the guest's card is paid the payment is added to this bill by itself, with the terminal's approval code.</div>
                </div>
                <div id="tpWait" class="text-center py-3 d-none" aria-live="polite">
                    <div class="spinner-border text-success mb-3" role="status" id="tpSpin"></div>
                    <h5 id="tpTitle" class="mb-1">Waiting for the card…</h5>
                    <div id="tpMsg" class="text-muted small"></div>
                </div>
                <div id="tpErr" class="cb-banner danger mt-3 mb-0 d-none"><i class="ri-error-warning-line"></i><div></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="action-btn btn-more d-none" id="tpCancel">Cancel on the terminal</button>
                <button type="button" class="action-btn btn-open" id="tpSend"><i class="ri-send-plane-line"></i> Send to terminal</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const $ = id => document.getElementById(id), csrf = document.querySelector('meta[name=csrf-token]')?.content;
    const folioId = {{ $folio->id }}, base = @json(url('/terminal-payments'));
    let current = null, timer = null, misses = 0;
    const show = (el, on) => el.classList.toggle('d-none', !on);
    const error = msg => { $('tpErr').querySelector('div').textContent = msg; show($('tpErr'), !!msg); };
    const call = (url, opts = {}) => fetch(url, Object.assign({ headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, credentials: 'same-origin' }, opts))
        .then(r => r.json().then(j => { if (!r.ok) throw new Error(j.message || 'The terminal could not be reached.'); return j; }));

    function render(p) {
        current = p;
        show($('tpForm'), false); show($('tpWait'), true); show($('tpSend'), !p.open); show($('tpCancel'), p.open);
        $('tpSpin').classList.toggle('d-none', !p.open);
        $('tpTitle').textContent = p.paid ? 'Paid' + (p.receipt ? ', receipt ' + p.receipt : '') : p.status_label;
        $('tpMsg').textContent = p.paid ? [p.card, p.approval && 'approval ' + p.approval].filter(Boolean).join(' · ') : (p.message || (p.open ? 'Ask the guest to tap, insert or swipe their card on ' + p.terminal + '.' : ''));
        $('tpSend').innerHTML = p.paid ? '<i class="ri-check-line"></i> Done' : '<i class="ri-restart-line"></i> Try again';
        if (p.paid) { clearTimeout(timer); setTimeout(() => location.reload(), 1500); }
        else if (p.open) { timer = setTimeout(poll, 3000); }
    }
    function poll() {
        call(base + '/' + current.id).then(j => { misses = 0; error(''); render(j.payment); })
            .catch(() => { misses++; error(misses > 2 ? 'Lost the connection. If the terminal shows it was paid it will be on the bill once the connection is back; nothing is lost.' : ''); timer = setTimeout(poll, Math.min(15000, 3000 * misses)); });
    }
    $('tpSend').addEventListener('click', function () {
        if (current && current.paid) { location.reload(); return; }
        if (current && !current.open) { current = null; show($('tpForm'), true); show($('tpWait'), false); $('tpSend').innerHTML = '<i class="ri-send-plane-line"></i> Send to terminal'; return; }
        if (!navigator.onLine) { error('This computer is offline. Key the amount on the terminal itself, then use “Receive payment” → Card with its approval code.'); return; }
        error(''); this.disabled = true;
        call(base, { method: 'POST', body: JSON.stringify({ terminal_id: $('tpTerminal').value, amount: $('tpAmount').value, folio_id: folioId }) })
            .then(j => render(j.payment)).catch(e => error(e.message)).finally(() => { this.disabled = false; });
    });
    $('tpCancel').addEventListener('click', function () {
        clearTimeout(timer); this.disabled = true;
        call(base + '/' + current.id + '/cancel', { method: 'POST' }).then(j => render(j.payment)).catch(e => error(e.message)).finally(() => { this.disabled = false; });
    });
    $('terminalModal').addEventListener('hidden.bs.modal', () => { clearTimeout(timer); if (current && current.paid) location.reload(); });
    $('terminalModal').addEventListener('shown.bs.modal', () => { if (current && current.open) poll(); });
});
</script>
@endif
