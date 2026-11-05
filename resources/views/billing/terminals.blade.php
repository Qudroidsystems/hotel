@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    $sym = config('hotel.currency_symbol');
    $t = $edit;
    $canManage = auth()->user()->can('Manage card terminals');
@endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-bank-card-2-line" subtitle="Paystack, Moniepoint and OPay terminals linked to the system. Send an amount from a guest bill, the bar till or the staff app; the payment is recorded by itself when the card is paid.">
        <x-slot:actions>
            @can('Manage hotel settings')<a class="cb-hero-btn" href="{{ route('settings.hotel.index', ['tab' => 'terminals']) }}"><i class="ri-key-2-line"></i> Provider accounts</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @unless (config('hotel.terminals.enabled', true))<div class="cb-banner warning"><i class="ri-information-line"></i><div>Sending to card terminals is switched off (Hotel Settings → Card Terminals).</div></div>@endunless
    @if ($stats['unlinked'])<div class="cb-banner warning"><i class="ri-error-warning-line"></i><div>{{ $stats['unlinked'] }} card payment(s) from the bar till were paid but the till never finished the sale (closed or lost its connection). Check them below and record the sale if needed.</div></div>@endif

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Terminals" :value="$terminals->where('is_active', true)->count()" icon="ri-bank-card-2-line" accent="teal" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Paid today" :value="$stats['paid']" icon="ri-checkbox-circle-line" accent="green" :hint="$sym . number_format($stats['amount'], 2)" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Declined or not paid today" :value="$stats['failed']" icon="ri-close-circle-line" accent="rose" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Paid, sale not finished" :value="$stats['unlinked']" icon="ri-links-line" accent="amber" hint="last 7 days" /></div>
    </div>

    <div class="row g-3">
        <div class="{{ $canManage ? 'col-xl-8' : 'col-12' }}">
            <x-cb.card title="Terminals" icon="ri-bank-card-2-line" :flush="true">
                @if ($terminals->isEmpty())
                    <div class="empty-state py-4"><i class="ri-bank-card-2-line"></i><h6>No terminals yet</h6><p class="text-muted small mb-0">Fill in the provider's account under Hotel Settings → Card Terminals, then add each terminal here.</p></div>
                @else
                    <div class="table-responsive"><table class="cb-table stack">
                        <thead><tr><th>Terminal</th><th>Provider</th><th>Where</th><th>Today</th>@if ($canManage)<th></th>@endif</tr></thead>
                        <tbody>
                            @foreach ($terminals as $tm)
                                <tr class="{{ $tm->is_active ? '' : 'opacity-50' }}">
                                    <td data-label="Terminal"><span class="fw-semibold">{{ $tm->name }}</span><div class="small text-muted">{{ $tm->identifier }}{{ $tm->is_active ? '' : ' · off' }}</div></td>
                                    <td data-label="Provider">{{ $tm->provider_label }}@if ($tm->last_online === false)<div class="small text-danger">offline at last test</div>@endif</td>
                                    <td data-label="Where">{{ $tm->outlet?->name ?? ($tm->location ?: 'Front desk') }}</td>
                                    <td data-label="Today">{{ $tm->paid_today }} paid</td>
                                    @if ($canManage)
                                        <td class="text-nowrap">
                                            <a class="action-btn btn-more" href="{{ route('terminals.index', ['edit' => $tm->id]) }}" title="Change"><i class="ri-edit-line"></i></a>
                                            <form method="POST" action="{{ route('terminals.test', $tm) }}" class="d-inline" data-once>@csrf<button class="action-btn btn-open" title="Test"><i class="ri-pulse-line"></i> Test</button></form>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </x-cb.card>

            <div class="cb-card mt-3">
                <form method="GET" class="cb-toolbar">
                    <select name="status" class="cb-select" aria-label="Status" onchange="this.form.submit()">
                        <option value="">Every amount sent</option>
                        @foreach (\App\Models\Billing\TerminalPayment::STATUSES as $k => [$label])<option value="{{ $k }}" @selected($status === $k)>{{ $label }}</option>@endforeach
                    </select>
                </form>
                @if ($payments->isEmpty())
                    <div class="empty-state py-4"><i class="ri-exchange-funds-line"></i><h6>Nothing sent to a terminal yet</h6></div>
                @else
                    <div class="table-responsive"><table class="cb-table stack">
                        <thead><tr><th>When</th><th>Amount</th><th>For</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($payments as $p)
                                <tr>
                                    <td data-label="When">{{ $p->created_at->format('j M, g:i A') }}<div class="small text-muted">{{ $p->terminal?->name }} · {{ $p->requester?->name }}</div></td>
                                    <td data-label="Amount" class="fw-semibold">{{ $sym }}{{ number_format((float) ($p->paid_amount ?? $p->amount), 2) }}@if ($p->card)<div class="small text-muted fw-normal">{{ $p->card }}</div>@endif</td>
                                    <td data-label="For">
                                        @if ($p->folio)
                                            <a href="{{ route('folios.show', $p->folio) }}">Bill {{ $p->folio->number }}</a>
                                            @if ($p->payment)<div class="small text-muted">receipt {{ $p->payment->receipt_number }}</div>@endif
                                        @elseif ($p->source === 'pos')
                                            Bar / restaurant sale
                                            @if ($p->status === 'paid' && ! $p->pos_order_uuid)<div class="small text-warning">sale not finished on the till</div>@endif
                                        @else
                                            {{ $p->description ?: '—' }}
                                        @endif
                                        <div class="small text-muted">{{ $p->reference }}{{ $p->approval ? ' · ' . $p->approval : '' }}</div></td>
                                    <td data-label="Status"><span class="status-pill {{ $p->status_pill }}">{{ $p->status_label }}</span>@if ($p->message && $p->status !== 'paid')<div class="small text-muted">{{ str($p->message)->limit(60) }}</div>@endif</td>
                                    <td>@if ($p->isOpen())<form method="POST" action="{{ route('terminal-payments.cancel', $p) }}">@csrf<button class="action-btn btn-more">Cancel</button></form>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    <div class="p-3">{{ $payments->links() }}</div>
                @endif
            </div>
        </div>
        @if ($canManage)
            <div class="col-xl-4">
                <x-cb.card :title="$t ? 'Change ' . $t->name : 'Add a terminal'" icon="{{ $t ? 'ri-edit-line' : 'ri-add-line' }}">
                    <form method="POST" action="{{ $t ? route('terminals.update', $t) : route('terminals.store') }}" class="row g-2 rm-form" data-once>
                        @csrf @if ($t) @method('PUT') @endif
                        <div class="col-12"><label for="tmName">Name</label><input id="tmName" name="name" class="form-control" maxlength="80" required value="{{ old('name', $t?->name) }}" placeholder="Front desk 1, Pool bar"></div>
                        <div class="col-12"><label for="tmProv">Provider</label>
                            <select id="tmProv" name="provider" class="form-select">@foreach (\App\Models\Billing\PaymentTerminal::PROVIDERS as $k => [$label, $hint])<option value="{{ $k }}" data-hint="{{ $hint }}" @selected(old('provider', $t?->provider ?? 'paystack') === $k)>{{ $label }}</option>@endforeach</select></div>
                        <div class="col-12"><label for="tmId" id="tmIdLabel">Terminal ID / serial number</label><input id="tmId" name="identifier" class="form-control" maxlength="80" required value="{{ old('identifier', $t?->identifier) }}"></div>
                        <div class="col-12"><label for="tmOutlet">Used at</label>
                            <select id="tmOutlet" name="pos_outlet_id" class="form-select"><option value="">Front desk</option>@foreach ($outlets as $o)<option value="{{ $o->id }}" @selected((int) old('pos_outlet_id', $t?->pos_outlet_id) === $o->id)>{{ $o->name }}</option>@endforeach</select>
                            <div class="form-text">The till offers its own outlet's terminal first.</div></div>
                        <div class="col-12"><label for="tmLoc">Note <span class="text-muted fw-normal">(optional)</span></label><input id="tmLoc" name="location" class="form-control" maxlength="120" value="{{ old('location', $t?->location) }}"></div>
                        @if ($t)<div class="col-12"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($t->is_active)> <span class="form-check-label fw-normal">In use</span></label></div>@endif
                        <div class="col-12 d-flex gap-2 mt-2"><button class="action-btn btn-open" type="submit"><i class="ri-save-line"></i> {{ $t ? 'Save' : 'Add terminal' }}</button>@if ($t)<a class="action-btn btn-more" href="{{ route('terminals.index') }}">Cancel</a>@endif</div>
                    </form>
                </x-cb.card>
                <x-cb.card title="When the internet is down" icon="ri-wifi-off-line" class="mt-3">
                    <p class="small mb-0">The terminals reach Paystack, Moniepoint or OPay over the internet. If the hotel's connection is down, key the amount on the terminal itself as before, then record it as <strong>Card (POS)</strong> with the terminal's approval code: on the bill, or on the till, which keeps sales on the device and sends them when the connection is back.</p>
                </x-cb.card>
            </div>
        @endif
    </div>

</div>
</div>
</div>
<script>
(function () {
    var s = document.getElementById('tmProv'), l = document.getElementById('tmIdLabel');
    if (!s) return;
    var f = function () { l.textContent = s.options[s.selectedIndex].dataset.hint || 'Terminal ID'; };
    s.addEventListener('change', f); f();
})();
</script>
@endsection
