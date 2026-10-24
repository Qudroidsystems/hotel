@extends('layouts.master')

@section('content')
@include('reservations.partials.styles')
@php use App\Models\Inventory\StockItem; $q = fn ($v) => StockItem::qty($v); @endphp
<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    <x-cb.hero :title="$po->number" icon="ri-file-list-3-line" :subtitle="$po->supplier->name . ' → ' . $po->location->name" :back="route('inventory.po.index')" back-label="Purchase orders">
        <x-slot:pills><span class="cb-meta-pill"><i class="ri-information-line"></i> {{ $po->status_label }}</span>
            @if ($po->expected_on)<span class="cb-meta-pill"><i class="ri-calendar-line"></i> Expected {{ $po->expected_on->format('D j M') }}</span>@endif</x-slot:pills>
        <x-slot:actions>
            @if ($po->canReceive())
                @can('Receive stock')<a class="cb-hero-btn" href="{{ route('inventory.receipts.create', ['po' => $po->id]) }}" style="background:#16a34a;border-color:#16a34a"><i class="ri-truck-line"></i> Receive delivery</a>@endcan
            @endif
            @can('Manage purchase orders')
                @if ($po->isEditable())
                    <a class="cb-hero-btn" href="{{ route('inventory.po.edit', $po) }}"><i class="ri-pencil-line"></i> Edit</a>
                @endif
                @if ($po->canSend())
                    <form method="POST" action="{{ route('inventory.po.order', $po) }}" data-once>@csrf<button class="cb-hero-btn" type="submit"><i class="ri-send-plane-line"></i> Mark ordered</button></form>
                @elseif ($po->status === 'draft')
                    <form method="POST" action="{{ route('inventory.po.submit', $po) }}" data-once>@csrf<button class="cb-hero-btn" type="submit"><i class="ri-send-plane-line"></i> Send for approval</button></form>
                @endif
                @if ($po->canCancel())
                    <button type="button" class="cb-hero-btn" data-bs-toggle="modal" data-bs-target="#cancelPo"><i class="ri-close-circle-line"></i> {{ $po->status === 'partial' ? 'Close' : 'Cancel' }}</button>
                @endif
            @endcan
            <a class="cb-hero-btn" href="{{ route('inventory.po.show', [$po, 'print' => 1]) }}" target="_blank" rel="noopener"><i class="ri-printer-line"></i> Print</a>
        </x-slot:actions>
    </x-cb.hero>
    @include('rooms.partials.flash')
    @if ($po->status === 'pending')
        <div class="cb-banner warning"><i class="ri-time-line"></i><div class="flex-grow-1">Waiting for approval since {{ $po->submitted_at?->format('j M, g:i A') }}{{ $po->submittedBy ? ' (sent by ' . $po->submittedBy->name . ')' : '' }}.
            @can('Approve purchases')
                @if ($selfRaised)
                    <div class="small mt-1">You raised this order, so another manager has to approve it.</div>
                @else
                    <div class="d-flex gap-2 flex-wrap mt-2">
                        <form method="POST" action="{{ route('inventory.po.approve', $po) }}" data-once>@csrf<input type="hidden" name="send" value="1"><button class="btn btn-success btn-sm"><i class="ri-check-line"></i> Approve &amp; mark ordered</button></form>
                        <form method="POST" action="{{ route('inventory.po.approve', $po) }}" data-once>@csrf<button class="btn btn-outline-success btn-sm">Approve only</button></form>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#rejectPo">Send back</button>
                    </div>
                    <form method="POST" action="{{ route('inventory.po.reject', $po) }}" class="collapse mt-2 rm-form" id="rejectPo" data-once>@csrf
                        <div class="input-group input-group-sm"><input name="reason" class="form-control" maxlength="255" required placeholder="What needs changing, e.g. get a second quote for the rice"><button class="btn btn-danger">Send back</button></div>
                    </form>
                @endif
            @endcan
        </div></div>
    @elseif ($po->status === 'draft' && $po->rejection_reason)
        <div class="cb-banner danger"><i class="ri-arrow-go-back-line"></i><div>Sent back by the approver: {{ $po->rejection_reason }}</div></div>
    @elseif ($po->status === 'draft' && $po->needsApproval())
        <div class="cb-banner info"><i class="ri-information-line"></i><div>This order is over the {{ config('hotel.currency_symbol') }}{{ number_format((float) config('hotel.procurement.approval_limit')) }} limit, so a manager has to approve it before it goes to the supplier.</div></div>
    @endif
    @php $jumps = collect($prices)->where('jump', true)->count(); @endphp
    @if ($jumps && in_array($po->status, ['draft', 'pending', 'approved'], true))
        <div class="cb-banner warning"><i class="ri-line-chart-line"></i><div>{{ $jumps }} {{ Str::plural('price', $jumps) }} {{ $jumps === 1 ? 'is' : 'are' }} more than {{ (float) config('hotel.procurement.price_jump_percent') }}% above what was last paid. Check before sending.</div></div>
    @endif
    @if ($po->status === 'cancelled')<div class="cb-banner danger"><i class="ri-close-circle-line"></i><div>Cancelled {{ $po->cancelled_at?->format('j M Y') }} — {{ $po->cancel_reason }}</div></div>@endif

    <div class="row g-3">
        <div class="col-xl-8">
            <x-cb.card title="Items" icon="ri-archive-line" :flush="true">
                <table class="cb-table stack">
                    <thead><tr><th>Item</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Unit cost</th><th class="num">Total</th></tr></thead>
                    <tbody>
                        @foreach ($po->lines as $l)
                            <tr>
                                <td data-label="Item"><a href="{{ route('inventory.items.show', $l->item) }}" class="fw-semibold text-reset">{{ $l->item->name }}</a></td>
                                <td data-label="Ordered" class="num">{{ $q($l->quantity) }} {{ $l->item->unit }}</td>
                                <td data-label="Received" class="num {{ $l->outstanding() > 0 && $po->status !== 'draft' ? 'text-warning-emphasis fw-semibold' : 'text-success' }}">{{ $q($l->received_quantity) }}@if ($l->outstanding() > 0 && $po->status !== 'draft')<div class="small">{{ $q($l->outstanding()) }} to come</div>@endif</td>
                                <td data-label="Unit cost" class="num">@money($l->unit_cost)
                                    @if (($p = $prices[$l->id] ?? null) && $p['last'] !== null)
                                        <div class="small {{ $p['jump'] ? 'text-danger fw-semibold' : 'text-muted' }}" title="Last paid {{ $p['on']?->format('j M Y') }}{{ $p['supplier'] ? ' to ' . $p['supplier'] : '' }}">last @money($p['last']){{ $p['change'] ? ' (' . ($p['change'] > 0 ? '+' : '') . $p['change'] . '%)' : '' }}</div>
                                    @endif</td>
                                <td data-label="Total" class="num fw-semibold">@money((float) $l->quantity * (float) $l->unit_cost)</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td colspan="4" class="text-end fw-bold">Order value</td><td class="num fw-bold">@money($po->total)</td></tr></tfoot>
                </table>
            </x-cb.card>
            @if ($po->receipts->isNotEmpty())
                <x-cb.card title="Deliveries" icon="ri-truck-line" :count="$po->receipts->count()">
                    @foreach ($po->receipts as $r)
                        <div class="d-flex justify-content-between py-2 border-bottom"><span><a href="{{ route('inventory.receipts.show', $r) }}" class="rs-ref">{{ $r->number }}</a> <span class="small text-muted">{{ $r->received_on->format('j M Y') }} · {{ $r->receivedBy?->name }}</span></span><strong>@money($r->total)</strong></div>
                    @endforeach
                </x-cb.card>
            @endif
        </div>
        <div class="col-xl-4">
            <x-cb.card title="Supplier" icon="ri-store-3-line">
                <div class="fw-bold" style="color: var(--cb-heading)">{{ $po->supplier->name }}</div>
                @if ($po->supplier->contact_person)<div class="small">{{ $po->supplier->contact_person }}</div>@endif
                @if ($po->supplier->phone)<div class="small"><a href="tel:{{ $po->supplier->phone }}">{{ $po->supplier->phone }}</a></div>@endif
                @if ($po->supplier->email)<div class="small"><a href="mailto:{{ $po->supplier->email }}">{{ $po->supplier->email }}</a></div>@endif
            </x-cb.card>
            @if ($po->invoices->isNotEmpty())
                <x-cb.card title="Supplier bills" icon="ri-bill-line" :count="$po->invoices->count()">
                    @foreach ($po->invoices as $inv)
                        <div class="d-flex justify-content-between py-1 small"><span>@canany(['Manage supplier invoices', 'Pay suppliers'])<a href="{{ route('inventory.invoices.show', $inv) }}">{{ $inv->invoice_number }}</a>@else{{ $inv->invoice_number }}@endcanany <span class="status-pill {{ $inv->status_pill }}">{{ $inv->status_label }}</span></span><strong>@money($inv->amount)</strong></div>
                    @endforeach
                </x-cb.card>
            @endif
            <x-cb.card title="History" icon="ri-history-line">
                <div class="small">Created {{ $po->created_at->format('j M Y, g:i A') }} by {{ $po->createdBy?->name }}</div>
                @if ($po->submitted_at)<div class="small">Sent for approval {{ $po->submitted_at->format('j M Y, g:i A') }} by {{ $po->submittedBy?->name }}</div>@endif
                @if ($po->approved_at)<div class="small">Approved {{ $po->approved_at->format('j M Y, g:i A') }} by {{ $po->approvedBy?->name }}</div>@endif
                @if ($po->ordered_at)<div class="small">Ordered {{ $po->ordered_at->format('j M Y, g:i A') }} by {{ $po->orderedBy?->name }}</div>@endif
                @if ($po->notes)<div class="small mt-2 text-muted">{{ $po->notes }}</div>@endif
            </x-cb.card>
        </div>
    </div>
</div>
</div>
</div>

<div class="modal fade" id="cancelPo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content rm-form" method="POST" action="{{ route('inventory.po.cancel', $po) }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title text-danger">{{ $po->status === 'partial' ? 'Close' : 'Cancel' }} {{ $po->number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><label>Reason <span class="text-danger">*</span></label><input name="reason" class="form-control" maxlength="255" required placeholder="e.g. Supplier out of stock">
                @if ($po->status === 'partial')<div class="small text-muted mt-2">What has already arrived stays in stock; the rest will not be expected.</div>@endif</div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep it</button><button class="btn btn-danger" type="submit">{{ $po->status === 'partial' ? 'Close order' : 'Cancel order' }}</button></div>
        </form>
    </div>
</div>
@endsection
