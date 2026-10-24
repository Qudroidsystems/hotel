@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php
    use App\Models\Pos\PosOrder;
    use App\Enums\PaymentMethod;
    $sym = config('hotel.currency_symbol');
    $range = $from->equalTo($to) ? $from->format('l j F Y') : $from->format('j M') . ' – ' . $to->format('j M Y');
@endphp
<style>
    .po-void td { text-decoration: line-through; color: var(--cb-muted) !important; }
    .po-void td.keep { text-decoration: none; }
    .po-items { font-size: 12.5px; color: var(--cb-muted); }
    .po-attn { background: #fffbeb; }
    [data-bs-theme=dark] .po-attn { background: rgba(245, 158, 11, .08); }
</style>
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$pagetitle" icon="ri-goblet-line" :subtitle="$range">
        <x-slot:actions>
            @can('Use POS')<a class="cb-hero-btn" href="{{ route('pos.terminal') }}" style="background:#ea580c;border-color:#ea580c"><i class="ri-computer-line"></i> Open till</a>@endcan
            @can('Manage POS menu')<a class="cb-hero-btn" href="{{ route('pos.menu') }}"><i class="ri-restaurant-2-line"></i> Menu &amp; prices</a>@endcan
        </x-slot:actions>
    </x-cb.hero>

    @include('rooms.partials.flash')
    @if ($totals['attention'] > 0)
        <div class="cb-banner warning"><i class="ri-alarm-warning-line"></i>
            <div><strong>{{ $totals['attention'] }} sale{{ $totals['attention'] === 1 ? '' : 's' }} need{{ $totals['attention'] === 1 ? 's' : '' }} attention</strong> — usually a room charge that couldn't go on the guest's bill. They're at the top of the list: settle each one at the till price, or put it on the right guest's bill.</div></div>
    @endif

    <div class="row g-3 mb-1">
        <div class="col-6 col-lg-3"><x-cb.stat label="Sales" :value="$sym . number_format($totals['sales'], 2)" icon="ri-shopping-basket-2-line" accent="teal" :hint="$totals['count'] . ' order' . ($totals['count'] === 1 ? '' : 's')" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Taken at the till" :value="$sym . number_format($totals['taken'], 2)" icon="ri-hand-coin-line" accent="green" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Charged to rooms" :value="$sym . number_format($totals['room'], 2)" icon="ri-hotel-bed-line" accent="violet" /></div>
        <div class="col-6 col-lg-3"><x-cb.stat label="Needs attention / voided" :value="$totals['attention'] . ' / ' . $totals['voided']" icon="ri-alarm-warning-line" accent="amber" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="cb-card">
                <form method="GET" class="cb-toolbar">
                    <div class="cb-search"><i class="ri-search-line"></i><input type="text" name="q" value="{{ $filters['q'] }}" placeholder="Order no., guest, room, table…" aria-label="Search"></div>
                    <input type="date" name="from" class="cb-select" value="{{ $from->toDateString() }}" aria-label="From">
                    <input type="date" name="to" class="cb-select" value="{{ $to->toDateString() }}" aria-label="To">
                    <select name="outlet" class="cb-select" aria-label="Outlet" onchange="this.form.submit()">
                        <option value="">All outlets</option>
                        @foreach ($outlets as $o)<option value="{{ $o->id }}" @selected($filters['outlet'] === $o->id)>{{ $o->name }}</option>@endforeach
                    </select>
                    <select name="status" class="cb-select" aria-label="Status" onchange="this.form.submit()">
                        <option value="">Any status</option>
                        @foreach ([PosOrder::PAID => 'Paid', PosOrder::CHARGED => 'Charged to room', PosOrder::ATTENTION => 'Needs attention', PosOrder::VOIDED => 'Voided'] as $v => $l)<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endforeach
                    </select>
                    @if ($everyone)
                        <select name="cashier" class="cb-select" aria-label="Cashier" onchange="this.form.submit()">
                            <option value="">Everyone</option>
                            @foreach ($cashiers as $u)<option value="{{ $u->id }}" @selected($filters['cashier'] === $u->id)>{{ $u->name }}</option>@endforeach
                        </select>
                    @endif
                    <button class="action-btn btn-open" type="submit"><i class="ri-filter-3-line"></i> Show</button>
                </form>

                @if ($orders->isEmpty())
                    <div class="empty-state py-5"><i class="ri-goblet-line"></i><h6>No sales in this period</h6></div>
                @else
                    <div class="table-responsive">
                        <table class="cb-table stack">
                            <thead><tr><th>Order</th><th>Items</th><th>Settled by</th><th>Status</th><th class="num">Total</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($orders as $o)
                                    <tr class="{{ $o->isVoided() ? 'po-void' : '' }} {{ $o->status === PosOrder::ATTENTION ? 'po-attn' : '' }}">
                                        <td data-label="Order">
                                            <span class="rs-ref">{{ $o->number }}</span>
                                            <div class="small text-muted">{{ $o->outlet->name }} · {{ $o->ordered_at->format('j M, g:i A') }}</div>
                                            <div class="small text-muted">{{ $o->cashier?->name }}{{ $o->table_label ? ' · ' . $o->table_label : '' }}</div>
                                            @if ($o->was_offline)<span class="status-pill st-muted keep" title="Rung up while the till was offline; sent {{ $o->synced_at?->format('j M g:i A') }}"><i class="ri-wifi-off-line"></i> offline sale</span>@endif
                                        </td>
                                        <td data-label="Items" class="po-items" style="max-width:280px">
                                            {{ $o->items->map(fn ($i) => rtrim(rtrim(number_format((float) $i->quantity, 2), '0'), '.') . '× ' . $i->name)->join(', ') }}
                                            @if ($o->discount_amount > 0)<div class="text-success">Discount −@money($o->discount_amount){{ $o->discount_reason ? ' (' . $o->discount_reason . ')' : '' }}</div>@endif
                                        </td>
                                        <td data-label="Settled by">
                                            @if ($o->method === PosOrder::ROOM)
                                                <div class="fw-semibold">Room {{ $o->room_number ?: '—' }}</div>
                                                <div class="small text-muted">{{ $o->guest_name }}@if ($o->reservation) · <a href="{{ route('reservations.show', $o->reservation) }}">{{ $o->reservation->reference }}</a>@endif</div>
                                            @else
                                                {{ $o->method_label }}
                                                @if ($o->reference)<div class="small text-muted">Ref {{ $o->reference }}</div>@endif
                                                @if ($o->bankAccount)<div class="small text-muted">into {{ $o->bankAccount->short_label }}</div>@endif
                                                @if ($o->change_due > 0)<div class="small text-muted">Gave @money($o->tendered) · change @money($o->change_due)</div>@endif
                                            @endif
                                        </td>
                                        <td data-label="Status" class="keep">
                                            <span class="status-pill {{ $o->status_pill }}">{{ $o->status_label }}</span>
                                            @if ($o->sync_note)<div class="small text-warning-emphasis mt-1" style="max-width:220px"><i class="ri-error-warning-line"></i> {{ $o->sync_note }}</div>@endif
                                            @if ($o->isVoided())<div class="small text-danger mt-1">{{ $o->voidedBy?->name }}: {{ $o->void_reason }}</div>@endif
                                            @if ($o->resolved_at)<div class="small text-muted mt-1">Settled by {{ $o->resolvedBy?->name }}</div>@endif
                                        </td>
                                        <td data-label="Total" class="num fw-bold">@money($o->total)</td>
                                        <td class="keep text-end text-nowrap">
                                            <a href="{{ route('pos.orders.receipt', $o) }}" target="_blank" rel="noopener" class="action-btn btn-open" title="Receipt"><i class="ri-printer-line"></i></a>
                                            @if ($o->status === PosOrder::ATTENTION)
                                                @canany(['View POS reports', 'Void POS orders'])
                                                    <button type="button" class="action-btn btn-go" data-resolve="{{ json_encode(['url' => route('pos.orders.resolve', $o), 'number' => $o->number, 'total' => (float) $o->total, 'note' => $o->sync_note]) }}">Settle</button>
                                                @endcanany
                                            @endif
                                            @unless ($o->isVoided())
                                                @can('Void POS orders')
                                                    <button type="button" class="action-btn btn-danger-soft" data-void-order="{{ json_encode(['url' => route('pos.orders.void', $o), 'number' => $o->number, 'room' => $o->status === PosOrder::CHARGED]) }}" title="Void"><i class="ri-forbid-line"></i></button>
                                                @endcan
                                            @endunless
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $orders->links() }}</div>
                @endif
            </div>
        </div>

        <div class="col-xl-4">
            <x-cb.card title="Taken at the till" icon="ri-hand-coin-line">
                @forelse ($byMethod as $method => $row)
                    <div class="d-flex justify-content-between py-2 border-bottom"><span>{{ PaymentMethod::tryFrom($method)?->label() ?? $method }} <span class="small text-muted">({{ $row['count'] }})</span></span><strong>@money($row['total'])</strong></div>
                @empty
                    <div class="small text-muted">Nothing taken in this period.</div>
                @endforelse
                @if (isset($byMethod['cash']))
                    <div class="cb-banner info mt-3 mb-0"><i class="ri-money-dollar-box-line"></i><div>Bar/restaurant cash should be <strong>@money($byMethod['cash']['total'])</strong>{{ $filters['cashier'] ? '' : ' (all tills)' }}.</div></div>
                @endif
            </x-cb.card>
            @if ($everyone && $byOutlet->isNotEmpty())
                <x-cb.card title="By outlet" icon="ri-store-2-line">
                    @foreach ($byOutlet as $outletId => $row)
                        @php $ol = $outlets->firstWhere('id', $outletId); @endphp
                        <div class="d-flex justify-content-between py-2 border-bottom"><span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:{{ $ol?->color }}"></span> {{ $ol?->name }} <span class="small text-muted">({{ $row['count'] }})</span></span><strong>@money($row['total'])</strong></div>
                    @endforeach
                </x-cb.card>
            @endif
            @if ($top->isNotEmpty())
                <x-cb.card title="Best sellers" icon="ri-fire-line">
                    @foreach ($top as $t)
                        <div class="d-flex justify-content-between py-1 small"><span>{{ rtrim(rtrim(number_format((float) $t->qty, 2), '0'), '.') }}× {{ $t->name }}</span><span class="fw-semibold">@money($t->amount)</span></div>
                    @endforeach
                </x-cb.card>
            @endif
        </div>
    </div>

</div>
</div>
</div>

<div class="modal fade" id="voidOrderModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="#" id="voidOrderForm">
            @csrf
            <div class="modal-header"><h5 class="modal-title text-danger"><i class="ri-forbid-line me-1"></i> Void order <span data-number></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label for="voidOrderReason">Reason <span class="text-danger">*</span></label>
                <input type="text" name="reason" id="voidOrderReason" class="form-control" maxlength="255" required placeholder="e.g. Rung up twice">
                <div class="small text-muted mt-2" data-room-note>The charge on the guest's bill is voided too.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep it</button><button class="btn btn-danger" type="submit">Void order</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="resolveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="#" id="resolveForm">
            @csrf
            <div class="modal-header"><h5 class="modal-title"><i class="ri-checkbox-circle-line me-1"></i> Settle order <span data-number></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="cb-banner warning" data-note></div>
                <div class="d-flex justify-content-between p-2 px-3 rounded-3 mb-3" style="background: var(--cb-surface-2)"><span class="text-muted">Order total</span><strong data-total></strong></div>
                <label>How was it settled?</label>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    @foreach ([PaymentMethod::Cash, PaymentMethod::Card, PaymentMethod::Transfer] as $m)
                        <input type="radio" class="btn-check" name="method" id="rm{{ $m->value }}" value="{{ $m->value }}" @checked($loop->first)><label class="btn btn-sm btn-outline-secondary" for="rm{{ $m->value }}">Guest paid — {{ $m->label() }}</label>
                    @endforeach
                    @can('Charge to room')
                        <input type="radio" class="btn-check" name="method" id="rmroom" value="room"><label class="btn btn-sm btn-outline-primary" for="rmroom">Put on a guest's bill</label>
                    @endcan
                </div>
                <div id="resolveRoom" class="d-none">
                    <label for="resolveRes">Guest</label>
                    <select name="reservation_id" id="resolveRes" class="form-select">
                        @foreach ($inHouse as $g)<option value="{{ $g['reservation_id'] }}">Room {{ $g['rooms']->join(', ') }} — {{ $g['guest'] }} ({{ $g['reference'] }})</option>@endforeach
                    </select>
                    @if (empty($inHouse))<div class="small text-muted mt-1">No guests are checked in.</div>@endif
                </div>
                <div id="resolveRef"><label for="resolveReference">Reference</label><input type="text" name="reference" id="resolveReference" class="form-control" maxlength="100" placeholder="Optional"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Settle</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const sym = @json($sym), fmt = n => sym + Number(n).toLocaleString('en-NG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.querySelectorAll('[data-void-order]').forEach(btn => btn.addEventListener('click', () => {
        const d = JSON.parse(btn.dataset.voidOrder), m = document.getElementById('voidOrderModal');
        document.getElementById('voidOrderForm').action = d.url;
        m.querySelector('[data-number]').textContent = d.number;
        m.querySelector('[data-room-note]').classList.toggle('d-none', !d.room);
        document.getElementById('voidOrderReason').value = '';
        bootstrap.Modal.getOrCreateInstance(m).show();
    }));
    const rf = document.getElementById('resolveForm');
    const sync = () => {
        const room = rf.querySelector('[name=method]:checked')?.value === 'room';
        document.getElementById('resolveRoom').classList.toggle('d-none', !room);
        document.getElementById('resolveRef').classList.toggle('d-none', room);
    };
    rf.querySelectorAll('[name=method]').forEach(r => r.addEventListener('change', sync));
    document.querySelectorAll('[data-resolve]').forEach(btn => btn.addEventListener('click', () => {
        const d = JSON.parse(btn.dataset.resolve), m = document.getElementById('resolveModal');
        rf.action = d.url;
        m.querySelector('[data-number]').textContent = d.number;
        m.querySelector('[data-total]').textContent = fmt(d.total);
        const note = m.querySelector('[data-note]'); note.textContent = d.note || ''; note.classList.toggle('d-none', !d.note);
        sync();
        bootstrap.Modal.getOrCreateInstance(m).show();
    }));
});
</script>
@endsection
